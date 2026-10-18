<?php

namespace Mtai\Aiagent\Schedule;

use Mtai\Aiagent\Handler\ChatHandler;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Rest\Client;
use Mtai\Aiagent\Tools\ToolRegistry;

/**
 * Исполнение отложенного задания: сохранённая инструкция выполняется
 * полноценным циклом агента (ToolLoop со всеми локальными инструментами
 * и MCP-скиллами чата) от имени сотрудника. Результат докладывается
 * сообщением в личный чат с ботом + коротким уведомлением в колокольчик
 * (через скилл notify_user).
 */
final class Executor
{
	/**
	 * @param array $task строка mtai_aiagent_schedule
	 * @return array{answer: string, error: ?string} error !== null — задание
	 *   считается сбойным и уйдёт на ретрай планировщика
	 */
	public function run(array $task): array
	{
		$userId = (int)$task['USER_ID'];
		$instruction = (string)$task['INSTRUCTION'];
		$dialogId = trim((string)$task['DIALOG_ID']) ?: (string)$userId;

		$result = (new ChatHandler())->runScheduled($userId, $dialogId, $instruction);
		if ($result['error'] !== null)
		{
			return $result;
		}

		$this->notifyBell($userId, $instruction);

		return $result;
	}

	/**
	 * Короткий пуш в колокольчик о выполненном задании. Сбой уведомления
	 * не роняет задание — результат уже ушёл в чат, пишем только в журнал.
	 */
	private function notifyBell(int $userId, string $instruction): void
	{
		$text = '✅ Отложенное задание выполнено: ' . mb_substr(trim($instruction), 0, 200);

		try
		{
			$result = ToolRegistry::execute('notify_user', ['message' => $text, 'user_id' => $userId], $userId, new Client());
			if (isset($result['error']))
			{
				Logger::warn('schedule', 'Bell notification failed: ' . $result['error']);
			}
		}
		catch (\Throwable $e)
		{
			Logger::warn('schedule', 'Bell notification failed: ' . $e->getMessage());
		}
	}
}
