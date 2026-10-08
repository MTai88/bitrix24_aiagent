<?php

namespace Mtai\Aiagent\Tools\TaskComplete;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$taskId = (int)($args['task_id'] ?? 0);
		if ($taskId <= 0)
		{
			return ['error' => 'task_id is required'];
		}

		// Завершать можно только свои задачи (участник/создатель/наблюдатель).
		$taskResult = $ctx->restCall('tasks.task.get', [
			'taskId' => $taskId,
			'select' => ['ID', 'CREATED_BY', 'RESPONSIBLE_ID', 'ACCOMPLICES', 'AUDITORS'],
		]);
		$taskFields = (array)($taskResult['task'] ?? []);
		if (!AccessGuard::taskAccessible($userId, $taskFields))
		{
			return ['error' => "Задача #{$taskId} вам недоступна"];
		}

		$ctx->restCall('tasks.task.complete', ['taskId' => $taskId]);

		return ['completed' => true, 'task_id' => $taskId];
	}
}
