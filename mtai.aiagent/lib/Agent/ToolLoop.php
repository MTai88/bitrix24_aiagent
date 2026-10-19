<?php

namespace Mtai\Aiagent\Agent;

use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Chat\ToolExecutor;
use Mtai\Aiagent\Chat\Tools;
use Mtai\Aiagent\Llm\ProviderInterface;
use Mtai\Aiagent\Mcp\Manager;

/**
 * Цикл исполнения инструментов LLM (function calling либо JSON-протокол).
 *
 * Общий для чат-агента и активности бизнес-процесса: модель запрашивает
 * инструмент → исполнение через REST → результат модели → … до финального
 * ответа (≤ MAX_ITERATIONS итераций).
 *
 * Инструменты MCP передаются отдельным агрегатором: описания подмешиваются
 * к локальным при function calling, исполнение идёт через ToolExecutor.
 */
final class ToolLoop
{
	private const MAX_ITERATIONS = 6;

	/**
	 * @param array $messages диалог (system + история + последнее сообщение)
	 * @param ProviderInterface $provider провайдер (supportsTools уже учтён)
	 * @param ToolExecutor $executor исполнитель инструментов
	 * @param int $userId от чьего имени выполняются действия в CRM
	 * @param string $model переопределение модели (пусто = из настроек)
	 * @param Manager|null $mcp агрегатор инструментов MCP (null = без MCP)
	 * @param callable|null $onProgress вызывается на каждой итерации цикла
	 *   (чат-агент шлёт им индикатор «бот печатает»); активность БП не передаёт
	 * @return array{answer: string, error: ?string}
	 */
	public static function run(
		array $messages,
		ProviderInterface $provider,
		ToolExecutor $executor,
		int $userId,
		string $model = '',
		?Manager $mcp = null,
		?callable $onProgress = null,
	): array {
		$options = trim($model) !== '' ? ['model' => trim($model)] : [];

		$refs = [];

		try
		{
			if ($provider->supportsTools())
			{
				return [
					'answer' => self::runNative($messages, $provider, $executor, $userId, $options, $mcp, $onProgress, $refs),
					'error' => null,
					'refs' => $refs,
				];
			}

			return [
				'answer' => self::runJsonProtocol($messages, $provider, $executor, $userId, $options, $onProgress, $refs),
				'error' => null,
				'refs' => $refs,
			];
		}
		catch (\Mtai\Aiagent\LlmException $e)
		{
			return ['answer' => '', 'error' => $e->getMessage(), 'refs' => $refs];
		}
	}

	private static function runNative(array $messages, ProviderInterface $provider, ToolExecutor $executor, int $userId, array $options = [], ?Manager $mcp = null, ?callable $onProgress = null, array &$refs = []): string
	{
		$tools = Tools::definitions();
		if ($mcp !== null)
		{
			$tools = array_merge($tools, $mcp->definitions());
		}

		for ($i = 0; $i < self::MAX_ITERATIONS; $i++)
		{
			$onProgress !== null && $onProgress();

			$answer = $provider->chat($messages, $tools, $options);

			if ($answer['toolCalls'] === [])
			{
				return self::finalize($answer['content']);
			}

			// В историю — assistant с tool_calls (в OpenAI-формате).
			$messages[] = [
				'role' => 'assistant',
				'content' => $answer['content'],
				'tool_calls' => array_map(
					static fn($call) => [
						'id' => $call['id'],
						'type' => 'function',
						'function' => [
							'name' => $call['name'],
							'arguments' => Json::encode($call['arguments'], JSON_UNESCAPED_UNICODE),
						],
					],
					$answer['toolCalls']
				),
			];

			foreach ($answer['toolCalls'] as $call)
			{
				$result = $executor->execute($call['name'], (array)$call['arguments'], $userId);
				self::absorbRefs($refs, (string)$call['name'], $result);
				$messages[] = [
					'role' => 'tool',
					'tool_call_id' => $call['id'],
					'content' => Json::encode($result, JSON_UNESCAPED_UNICODE),
				];
			}
		}

		return 'Выполнил действия, но ответ не сформировался — уточните запрос, пожалуйста.';
	}

	private static function runJsonProtocol(array $messages, ProviderInterface $provider, ToolExecutor $executor, int $userId, array $options = [], ?callable $onProgress = null, array &$refs = []): string
	{
		for ($i = 0; $i < self::MAX_ITERATIONS; $i++)
		{
			$onProgress !== null && $onProgress();

			$answer = $provider->chat($messages, [], $options);
			$content = trim((string)($answer['content'] ?? ''));

			$command = self::parseJsonCommand($content);
			if ($command === null)
			{
				return self::finalize($content);
			}

			// Модель ответила JSON-обёрткой — пользователю отдаём чистый
			// текст ответа (в истории оставляем оригинал, чтобы диалог
			// оставался консистентным для модели).
			if (isset($command['answer']))
			{
				return self::finalize((string)$command['answer']);
			}

			// Часть моделей (GigaChat-Max) шлёт вызов инструмента
			// одноключевой формой {"<tool>": {...}} вместо
			// {"tool": "<tool>", "arguments": {...}} — нормализуем.
			if (!isset($command['tool']) && count($command) === 1 && is_array(reset($command)))
			{
				$command = ['tool' => (string)array_key_first($command), 'arguments' => reset($command)];
			}

			$name = (string)($command['tool'] ?? '');
			$arguments = (array)($command['arguments'] ?? []);

			$result = $executor->execute($name, $arguments, $userId);
			self::absorbRefs($refs, $name, $result);

			$messages[] = ['role' => 'assistant', 'content' => $content];
			$messages[] = [
				'role' => 'user',
				'content' => 'Результат инструмента ' . $name . ': '
					. Json::encode($result, JSON_UNESCAPED_UNICODE)
					. "\nПродолжай: следующий вызов инструмента тем же JSON-форматом или финальный ответ {\"answer\": \"...\"}.",
			];
		}

		return 'Не смог завершить цепочку действий — попробуйте разбить запрос на шаги.';
	}

	/**
	 * Извлечение JSON-объекта из ответа модели (в т.ч. из ```json …```,
	 * с восстановлением незакрытых скобок — LLM их регулярно роняет).
	 */
	public static function parseJsonCommand(string $content): ?array
	{
		$content = trim((string)preg_replace('/^```(?:json)?|```$/m', '', trim($content)));

		if (preg_match('/\{.*\}/s', $content, $match) || preg_match('/\{.*/s', $content, $match))
		{
			$candidate = $match[0];

			try
			{
				$decoded = Json::decode($candidate);
				if (is_array($decoded))
				{
					return $decoded;
				}
			}
			catch (\Throwable)
			{
			}

			// Битый JSON (незакрытые скобки/кавычки): достаём answer
			// или tool регуляркой, чтобы ответ не ушёл пользователю сырым.
			if (preg_match('/"answer"\s*:\s*"((?:\\\\.|[^"\\\\])*)/s', $candidate, $m))
			{
				// Незакрытая кавычка захватывает хвост JSON — срезаем.
				$answer = preg_replace('/}\s*$/', '', stripcslashes($m[1]));
				if (trim($answer) !== '')
				{
					return ['answer' => $answer];
				}
			}

			if (preg_match('/"tool"\s*:\s*"((?:\\\\.|[^"\\\\])*)/s', $candidate, $m))
			{
				return ['tool' => $m[1], 'arguments' => self::extractArguments($candidate)];
			}
		}

		return null;
	}

	/** Аргументы инструмента из битого JSON: блок "arguments":{…} целиком. */
	private static function extractArguments(string $candidate): array
	{
		if (!preg_match('/"arguments"\s*:\s*\{(.*)$/s', $candidate, $m))
		{
			return [];
		}

		// Балансируем скобки.
		$depth = 1;
		$out = '';
		$length = mb_strlen($m[1]);
		for ($i = 0; $i < $length; $i++)
		{
			$char = mb_substr($m[1], $i, 1);
			if ($char === '{')
			{
				$depth++;
			}
			elseif ($char === '}')
			{
				$depth--;
				if ($depth === 0)
				{
					break;
				}
			}
			$out .= $char;
		}

		try
		{
			$decoded = Json::decode('{' . rtrim($out, ',') . '}');

			return is_array($decoded) ? $decoded : [];
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	/**
	 * Короткие метки сущностей из результата инструмента — для следующей реплики.
	 * @param string[] $refs
	 */
	private static function absorbRefs(array &$refs, string $tool, array $result): void
	{
		if (isset($result['error']))
		{
			return;
		}

		$push = static function (string $type, int $id, string $title = '') use (&$refs): void
		{
			if ($id <= 0 || $type === '')
			{
				return;
			}
			$label = $type . ' #' . $id;
			$title = trim($title);
			if ($title !== '')
			{
				$label .= ' «' . mb_substr($title, 0, 80) . '»';
			}
			if (!in_array($label, $refs, true))
			{
				$refs[] = $label;
			}
		};

		$schedule = in_array($tool, ['schedule_task', 'list_scheduled_tasks', 'cancel_scheduled_task'], true);
		if (isset($result['entity_type']))
		{
			$push(
				(string)$result['entity_type'],
				(int)($result['id'] ?? $result['entity_id'] ?? 0),
				(string)($result['title'] ?? '')
			);
		}
		if (isset($result['task_id']))
		{
			$push($schedule ? 'schedule' : 'task', (int)$result['task_id'], (string)($result['title'] ?? ''));
		}
		if (isset($result['activity_id']))
		{
			$push('activity', (int)$result['activity_id'], (string)($result['title'] ?? ''));
		}

		foreach ((array)($result['items'] ?? []) as $item)
		{
			if (!is_array($item))
			{
				continue;
			}
			$push((string)($item['entity_type'] ?? 'crm'), (int)($item['id'] ?? 0), (string)($item['title'] ?? ''));
		}
		if (!$schedule)
		{
			foreach ((array)($result['tasks'] ?? []) as $item)
			{
				if (!is_array($item))
				{
					continue;
				}
				$push('task', (int)($item['id'] ?? $item['task_id'] ?? 0), (string)($item['title'] ?? ''));
			}
		}

		if (count($refs) > 12)
		{
			$refs = array_slice($refs, 0, 12);
		}
	}

	private static function finalize(?string $content): string
	{
		$reply = trim((string)$content);

		return $reply !== '' ? $reply : 'Не смог сформировать ответ — попробуйте переформулировать запрос.';
	}
}
