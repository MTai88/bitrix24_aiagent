<?php

namespace Mtai\Aiagent\Tools\TaskAddComment;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$taskId = (int)($args['task_id'] ?? 0);
		$comment = trim((string)($args['comment'] ?? ''));
		if ($taskId <= 0 || $comment === '')
		{
			return ['error' => 'task_id and comment are required'];
		}

		$taskResult = $ctx->restCall('tasks.task.get', [
			'taskId' => $taskId,
			'select' => ['ID', 'TITLE', 'CREATED_BY', 'RESPONSIBLE_ID', 'ACCOMPLICES', 'AUDITORS'],
		]);
		$task = (array)($taskResult['task'] ?? []);
		if ($task === [] || !AccessGuard::taskAccessible($userId, $task))
		{
			return ['error' => "Задача #{$taskId} вам недоступна"];
		}

		// task.commentitem.add принимает аргументы по порядку (taskId, fields).
		// AUTHOR_ID — автор запроса, а не владелец вебхука.
		$commentId = $ctx->restCall('task.commentitem.add', [
			'taskId' => $taskId,
			'fields' => [
				'POST_MESSAGE' => $comment,
				'AUTHOR_ID' => $userId,
			],
		]);

		return [
			'comment_id' => (int)$commentId,
			'task_id' => $taskId,
			'title' => (string)($task['title'] ?? $task['TITLE'] ?? ''),
			'url' => $ctx->taskUrl($taskId, $userId),
		];
	}
}
