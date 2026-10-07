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
	public static function run(array $messages, ProviderInterface $provider, ToolExecutor $executor, int $userId): array
	{
		try
		{
			if ($provider->supportsTools())
			{
				return ['answer' => self::runNative($messages, $provider, $executor, $userId), 'error' => null];
			}

			return ['answer' => self::runJsonProtocol($messages, $provider, $executor, $userId), 'error' => null];
		}
		catch (\Mtai\Aiagent\LlmException $e)
		{
			return ['answer' => '', 'error' => $e->getMessage()];
		}
	}

	private static function runNative(array $messages, ProviderInterface $provider, ToolExecutor $executor, int $userId): string
	{
		$tools = Tools::definitions();

		for ($i = 0; $i < self::MAX_ITERATIONS; $i++)
		{
			$answer = $provider->chat($messages, $tools);

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

	private static function runJsonProtocol(array $messages, ProviderInterface $provider, ToolExecutor $executor, int $userId): string
	{
		for ($i = 0; $i < self::MAX_ITERATIONS; $i++)
		{
			$answer = $provider->chat($messages);
			$content = trim((string)($answer['content'] ?? ''));

			$command = self::parseJsonCommand($content);
			if ($command === null || isset($command['answer']))
			{
				return self::finalize($command['answer'] ?? $content);
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

	/** Извлечение JSON-объекта из ответа модели (в т.ч. из ```json …```). */
	public static function parseJsonCommand(string $content): ?array
	{
		$content = trim((string)preg_replace('/^```(?:json)?|```$/m', '', trim($content)));

		if (preg_match('/\{.*\}/s', $content, $match) || preg_match('/\{[^{}]*\}/s', $content, $match))
		{
			try
			{
				$decoded = Json::decode($match[0]);
				if (is_array($decoded))
				{
					return $decoded;
				}
			}
			catch (\Throwable)
			{
			}
		}

		return null;
	}

	private static function finalize(?string $content): string
	{
		$reply = trim((string)$content);

		return $reply !== '' ? $reply : 'Не смог сформировать ответ — попробуйте переформулировать запрос.';
	}
}
