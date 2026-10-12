<?php

namespace Mtai\Aiagent\Mcp;

use Mtai\Aiagent\Logger;
use Mtai\Aiagent\McpException;

/**
 * Агрегатор инструментов MCP-серверов для одного запуска агента.
 *
 * Подключается к серверам списка (конфиги из ServerConfig), забирает
 * tools/list и публикует их LLM под именами `mcp_{сервер}_{инструмент}`
 * (санитизация под ограничения OpenAI: [a-z0-9_-], ≤ 64 символов).
 * Недоступный сервер не роняет запуск: его инструменты пропускаются,
 * ошибка пишется в журнал; сбой при вызове инструмента возвращается
 * модели как ['error' => …] — ровно как у локальных инструментов.
 */
final class Manager
{
	/** @var array<int, array{name: string, url: string, api_key: string}> */
	private array $servers;

	/** @var array<string, array{server: string, tool: string}>|null маппинг имени LLM → инструмент */
	private ?array $toolMap = null;

	/** @var array<string, Client>|null клиенты по имени сервера */
	private ?array $clients = null;

	public function __construct(array $servers)
	{
		$this->servers = $servers;
	}

	/** Есть ли вообще серверы в списке (до попыток соединения). */
	public function isEmpty(): bool
	{
		return $this->servers === [];
	}

	/**
	 * Описания инструментов в формате OpenAI tools (как ToolRegistry).
	 * @return array[] [['type' => 'function', 'function' => [...]], …]
	 */
	public function definitions(): array
	{
		$this->connect();

		$result = [];
		foreach ($this->toolMap ?? [] as $llmName => $map)
		{
			$meta = $this->toolMeta($map['server'], $map['tool']);
			if ($meta === null)
			{
				continue;
			}

			$schema = (array)($meta['inputSchema'] ?? []);
			$result[] = [
				'type' => 'function',
				'function' => [
					'name' => $llmName,
					'description' => (string)($meta['description'] ?? ''),
					// Ассоц-массивы → object, списки (required/enum) —
					// как есть: OpenAI ждёт объект верхнего уровня.
					'parameters' => $schema !== [] ? self::schemaToObject($schema) : (object)[
						'type' => 'object',
						'properties' => (object)[],
					],
				],
			];
		}

		return $result;
	}

	/**
	 * JSON Schema инструмента из JSON-RPC ответа: глубокое приведение
	 * ассоциативных массивов к object (круговое кодирование/декодирование:
	 * assoc → stdClass, списки → array).
	 */
	private static function schemaToObject(array $schema): object
	{
		$decoded = json_decode((string)json_encode($schema, JSON_UNESCAPED_UNICODE));

		return is_object($decoded) ? $decoded : (object)$schema;
	}

	/** Текстовое описание для JSON-протокола (модели без function calling). */
	public function forPrompt(): string
	{
		$list = [];
		foreach ($this->definitions() as $tool)
		{
			$function = $tool['function'];
			$parameters = [];
			foreach ((array)($function['parameters']->properties ?? []) as $param => $schema)
			{
				$schema = (array)$schema;
				$parameters[] = $param . ' (' . ($schema['type'] ?? 'string') . ')';
			}

			$list[] = '- ' . $function['name'] . ': ' . $function['description'] . "\n"
				. '  параметры: ' . ($parameters === [] ? 'нет' : implode('; ', $parameters));
		}

		return implode("\n", $list);
	}

	public function has(string $llmName): bool
	{
		$this->connect();

		return isset($this->toolMap[$llmName]);
	}

	/**
	 * Вызов инструмента по имени LLM.
	 * @return array результат для модели либо ['error' => string]
	 */
	public function execute(string $llmName, array $arguments): array
	{
		$this->connect();

		$map = $this->toolMap[$llmName] ?? null;
		if ($map === null || !isset($this->clients[$map['server']]))
		{
			return ['error' => "Unknown MCP tool: {$llmName}"];
		}

		Logger::info('mcp', "call {$llmName}", $arguments);

		try
		{
			return $this->clients[$map['server']]->callTool($map['tool'], $arguments);
		}
		catch (McpException $e)
		{
			Logger::error('mcp', "tool {$llmName} failed: " . $e->getMessage());

			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Состояние соединений по серверам (кнопка «Проверить» в настройках).
	 * @return array<int, array{name: string, ok: bool, tools?: int, error?: string}>
	 */
	public function status(): array
	{
		$this->connect();

		$result = [];
		foreach ($this->servers as $server)
		{
			$row = ['name' => $server['name'], 'ok' => false];
			$client = $this->clients[$server['name']] ?? null;
			if ($client === null)
			{
				$row['error'] = 'соединение не установлено';
			}
			else
			{
				$row['ok'] = true;
				$row['tools'] = count(array_filter(
					$this->toolMap ?? [],
					static fn($map) => $map['server'] === $server['name']
				));
			}

			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Подключение ко всем серверам списка: инициализация клиентов и
	 * построение маппинга имён. Вызывается лениво, один раз.
	 */
	private function connect(): void
	{
		if ($this->toolMap !== null)
		{
			return;
		}

		$this->toolMap = [];
		$this->clients = [];

		foreach ($this->servers as $server)
		{
			try
			{
				$client = Client::forServer($server['url'], $server['api_key']);
				$this->clients[$server['name']] = $client;
			}
			catch (McpException $e)
			{
				Logger::warn('mcp', "Сервер «{$server['name']}» ({$server['url']}): " . $e->getMessage());

				continue;
			}

			foreach ($client->listTools() as $tool)
			{
				$llmName = $this->llmName($server['name'], (string)$tool['name']);
				$this->toolMap[$llmName] = [
					'server' => $server['name'],
					'tool' => (string)$tool['name'],
					'meta' => $tool,
				];
			}
		}

		Logger::info('mcp', 'MCP: ' . count($this->toolMap) . ' инструментов с ' . count($this->clients) . ' серверов');
	}

	/** Мета описание инструмента из маппинга. */
	private function toolMeta(string $server, string $tool): ?array
	{
		return $this->toolMap[$this->llmName($server, $tool)]['meta'] ?? null;
	}

	/**
	 * Имя инструмента для LLM: mcp_{сервер}_{инструмент}.
	 * Не-ASCII заменяется на _, повторы схлопываются; если имя сервера
	 * «съелось» санитизацией целиком — srv{N} по индексу списка серверов.
	 */
	private function llmName(string $server, string $tool): string
	{
		$sanitizedServer = strtolower((string)preg_replace('/_+/', '_', (string)preg_replace('/[^a-z0-9_-]/i', '_', $server)));
		$sanitizedServer = trim($sanitizedServer, '_');
		if ($sanitizedServer === '')
		{
			$sanitizedServer = 'srv' . (array_search($server, array_column($this->servers, 'name'), true) + 1);
		}

		$sanitizedTool = (string)preg_replace('/_+/', '_', (string)preg_replace('/[^a-z0-9_-]/i', '_', $tool));

		return mb_substr('mcp_' . $sanitizedServer . '_' . $sanitizedTool, 0, 64);
	}
}
