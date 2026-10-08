<?php

namespace Mtai\Aiagent\Agent;

use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Chat\ToolExecutor;
use Mtai\Aiagent\Chat\Tools;
use Mtai\Aiagent\Llm\ProviderInterface;

/**
 * Цикл исполнения инструментов LLM (function calling либо JSON-протокол).
 *
 * Общий для чат-агента и активности бизнес-процесса: модель запрашивает
 * инструмент → исполнение через REST → результат модели → … до финального
 * ответа (≤ MAX_ITERATIONS итераций).
 */
final class ToolLoop
{
	private const MAX_ITERATIONS = 6;

	/**
	 * @param array $messages диалог (system + история + последнее сообщение)
	 * @param ProviderInterface $provider провайдер (supportsTools уже учтён)
	 * @param ToolExecutor $executor исполнитель инструментов
	 * @param int $userId от чьего имени выполняются действия в CRM
	 * @return array{answer: string, error: ?string}
	 */
	public static function run(
		array $messages,
		ProviderInterface $provider,
		ToolExecutor $executor,
		int $userId,
		string $model = '',
	): array {
		$options = trim($model) !== '' ? ['model' => trim($model)] : [];

		try
		{
			if ($provider->supportsTools())
			{
				return ['answer' => self::runNative($messages, $provider, $executor, $userId, $options), 'error' => null];
			}

			return ['answer' => self::runJsonProtocol($messages, $provider, $executor, $userId, $options), 'error' => null];
		}
		catch (\Mtai\Aiagent\LlmException $e)
		{
			return ['answer' => '', 'error' => $e->getMessage()];
		}
	}

	private static function runNative(array $messages, ProviderInterface $provider, ToolExecutor $executor, int $userId, array $options = []): string
	{
		$tools = Tools::definitions();

		for ($i = 0; $i < self::MAX_ITERATIONS; $i++)
		{
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
				$messages[] = [
					'role' => 'tool',
					'tool_call_id' => $call['id'],
					'content' => Json::encode($result, JSON_UNESCAPED_UNICODE),
				];
			}
		}

		return 'Выполнил действия, но ответ не сформировался — уточните запрос, пожалуйста.';
	}

	private static function runJsonProtocol(array $messages, ProviderInterface $provider, ToolExecutor $executor, int $userId, array $options = []): string
	{
		for ($i = 0; $i < self::MAX_ITERATIONS; $i++)
		{
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

			$name = (string)($command['tool'] ?? '');
			$arguments = (array)($command['arguments'] ?? []);

			$result = $executor->execute($name, $arguments, $userId);

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

	private static function finalize(?string $content): string
	{
		$reply = trim((string)$content);

		return $reply !== '' ? $reply : 'Не смог сформировать ответ — попробуйте переформулировать запрос.';
	}
}
