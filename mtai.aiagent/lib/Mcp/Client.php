<?php

namespace Mtai\Aiagent\Mcp;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\McpException;

/**
 * Клиент MCP-сервера: JSON-RPC 2.0 по streamable HTTP
 * (спецификация Model Context Protocol).
 *
 * Жизненный цикл: initialize → notifications/initialized → tools/list /
 * tools/call. Инициализированные клиенты кешируются на процесс — активность
 * в рамках одного запуска БП и кнопка «Проверить» не делают лишних
 * хендшейков.
 *
 * Ответы сервера могут идти двумя транспортами: обычный JSON
 * (Content-Type: application/json) или SSE (text/event-stream, поле
 * data: …) — разбираем оба. Сессионный заголовок Mcp-Session-Id
 * запоминается и подставляется в последующие запросы.
 */
final class Client
{
	private const PROTOCOL_VERSION = '2025-03-26';
	private const PROTOCOL_VERSION_FALLBACK = '2024-11-05';
	private const MAX_PAGES = 10;

	/** @var array<string, self> кеш на процесс: url+md5(ключа) → клиент */
	private static array $instances = [];

	private string $url;
	private string $apiKey;
	private ?string $sessionId = null;
	private bool $initialized = false;

	private function __construct(string $url, string $apiKey)
	{
		$this->url = $url;
		$this->apiKey = $apiKey;
	}

	/**
	 * Инициализированный клиент из процессного кеша (создаётся при первом
	 * запросе). Бросает McpException, если сервер недоступен или отказал
	 * в инициализации.
	 */
	public static function forServer(string $url, string $apiKey = ''): self
	{
		$key = $url . '|' . md5($apiKey);
		if (!isset(self::$instances[$key]))
		{
			$client = new self($url, $apiKey);
			$client->initialize();
			self::$instances[$key] = $client;
		}

		return self::$instances[$key];
	}

	/** Сброс процессного кеша (тесты, повторная проверка соединения). */
	public static function resetCache(): void
	{
		self::$instances = [];
	}

	/** Имя сервера для логов — хост из URL. */
	public function serverName(): string
	{
		return (string)parse_url($this->url, PHP_URL_HOST);
	}

	/**
	 * tools/list со всех страниц (nextCursor).
	 * @return array<int, array{name: string, description?: string, inputSchema?: array}>
	 */
	public function listTools(): array
	{
		$tools = [];
		$cursor = null;

		for ($page = 0; $page < self::MAX_PAGES; $page++)
		{
			$params = [];
			if ($cursor !== null)
			{
				$params['cursor'] = $cursor;
			}

			$result = $this->request('tools/list', $params);
			foreach ((array)($result['tools'] ?? []) as $tool)
			{
				$tool = (array)$tool;
				if (!empty($tool['name']))
				{
					$tools[] = $tool;
				}
			}

			$cursor = $result['nextCursor'] ?? null;
			if (!is_string($cursor) || $cursor === '')
			{
				break;
			}
		}

		return $tools;
	}

	/**
	 * tools/call. Возвращает структурированный результат либо текст
	 * контент-блоков. Ошибочный результат инструмента (isError) тоже
	 * отдаётся как данные — решение сообщать её пользователю принимает
	 * модель, как и с локальными инструментами.
	 * @return array результат для модели
	 */
	public function callTool(string $name, array $arguments): array
	{
		$result = (array)$this->request('tools/call', [
			'name' => $name,
			'arguments' => (object)$arguments,
		]);

		if (!empty($result['isError']))
		{
			return ['error' => $this->contentToText($result)];
		}

		// structuredContent (JSON-объект) приоритетнее текстовых блоков:
		// это машиночитаемый ответ инструмента.
		if (!empty($result['structuredContent']) && is_array($result['structuredContent']))
		{
			return $result['structuredContent'];
		}

		return ['result' => $this->contentToText($result)];
	}

	private function initialize(): void
	{
		if ($this->initialized)
		{
			return;
		}

		try
		{
			$this->sendInitialize(self::PROTOCOL_VERSION);
		}
		catch (McpException $e)
		{
			// Частая причина — сервер старой версии, не знающий
			// свежий protocolVersion: пробуем предыдущий стабильный.
			if (stripos($e->getMessage(), 'protocol') === false)
			{
				throw $e;
			}

			Logger::debug('mcp', 'initialize: повтор с protocolVersion ' . self::PROTOCOL_VERSION_FALLBACK);
			$this->sendInitialize(self::PROTOCOL_VERSION_FALLBACK);
		}

		// Обязательное уведомление завершения хендшейка (без id).
		$this->notify('notifications/initialized');

		$this->initialized = true;
		Logger::info('mcp', "MCP-сервер {$this->serverName()}: handshake ok");
	}

	private function sendInitialize(string $protocolVersion): void
	{
		$this->request('initialize', [
			'protocolVersion' => $protocolVersion,
			'capabilities' => (object)[],
			'clientInfo' => ['name' => 'mtai.aiagent', 'version' => '1.0'],
		]);
	}

	/**
	 * JSON-RPC-запрос с извлечением result.
	 * @return array result ответа сервера
	 */
	private function request(string $method, array $params): array
	{
		$payload = [
			'jsonrpc' => '2.0',
			'id' => uniqid('rpc_', true),
			'method' => $method,
			'params' => (object)$params,
		];

		$response = $this->post($payload, $method);

		if (isset($response['error']) && is_array($response['error']))
		{
			$message = (string)($response['error']['message'] ?? 'unknown MCP error');

			throw new McpException("MCP {$method}: {$message}");
		}

		$result = $response['result'] ?? null;
		if (!is_array($result))
		{
			throw new McpException("MCP {$method}: пустой или невалидный result");
		}

		return $result;
	}

	private function notify(string $method): void
	{
		try
		{
			$this->post([
				'jsonrpc' => '2.0',
				'method' => $method,
				'params' => (object)[],
			], $method);
		}
		catch (McpException $e)
		{
			// Уведомления fire-and-forget: ответа с result у них нет,
			// ошибку транспорта не считаем фатальной.
			Logger::debug('mcp', "notify {$method}: " . $e->getMessage());
		}
	}

	/**
	 * HTTP POST одного JSON-RPC-сообщения; разбор JSON/SSE-ответа.
	 */
	private function post(array $payload, string $method): array
	{
		$timeout = Config::getInt('llm_timeout', 45);

		$http = new HttpClient([
			'socketTimeout' => $timeout,
			'streamTimeout' => $timeout,
			'version' => '1.1',
		]);
		$http->setHeader('Content-Type', 'application/json');
		$http->setHeader('Accept', 'application/json, text/event-stream');
		if ($this->apiKey !== '')
		{
			$http->setHeader('Authorization', 'Bearer ' . $this->apiKey);
		}
		if ($this->sessionId !== null)
		{
			$http->setHeader('Mcp-Session-Id', $this->sessionId);
		}
		if (Config::get('llm_ssl_verify', 'Y') !== 'Y')
		{
			$http->disableSslVerification();
		}

		$raw = $http->post($this->url, Json::encode($payload, JSON_UNESCAPED_UNICODE));
		$status = (int)$http->getStatus();

		$sessionHeader = $http->getHeaders()->get('Mcp-Session-Id');
		if (is_string($sessionHeader) && $sessionHeader !== '')
		{
			$this->sessionId = $sessionHeader;
		}

		if ($raw === false || $raw === '')
		{
			throw new McpException("MCP {$method}: HTTP-запрос не выполнен (status {$status})");
		}

		$contentType = (string)$http->getHeaders()->get('Content-Type');
		if (stripos($contentType, 'text/event-stream') !== false)
		{
			$raw = self::extractSseData((string)$raw);
		}

		try
		{
			$decoded = Json::decode((string)$raw);
		}
		catch (\Throwable)
		{
			throw new McpException("MCP {$method}: невалидный JSON в ответе (HTTP {$status})");
		}

		if (!is_array($decoded))
		{
			throw new McpException("MCP {$method}: неожиданный ответ сервера (HTTP {$status})");
		}

		return $decoded;
	}

	/**
	 * SSE-кадры → склеенное содержимое data: (JSON-RPC-сообщение).
	 * Кадры отделяются пустыми строками; мультистроковые data склеиваются.
	 */
	private static function extractSseData(string $body): string
	{
		$data = [];
		foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line)
		{
			if (str_starts_with($line, 'data:'))
			{
				$data[] = trim(substr($line, 5));
			}
		}

		return implode("\n", $data);
	}

	/** Контент-блоки tools/call → плоский текст. */
	private function contentToText(array $result): string
	{
		$parts = [];
		foreach ((array)($result['content'] ?? []) as $block)
		{
			$block = (array)$block;
			if (($block['type'] ?? '') === 'text' && isset($block['text']))
			{
				$parts[] = (string)$block['text'];
			}
			elseif (isset($block['data']))
			{
				try
				{
					$parts[] = Json::encode($block['data'], JSON_UNESCAPED_UNICODE);
				}
				catch (\Throwable)
				{
				}
			}
		}

		return trim(implode("\n", $parts));
	}
}
