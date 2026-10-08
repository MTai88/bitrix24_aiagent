<?php

namespace Mtai\Aiagent\Tools\TasksList;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	/** Статусы задач Битрикса: 1-3 незавершённые, 5 завершённые. */
	private const STATUS_OPEN = [1, 2, 3];
	private const STATUS_COMPLETED = 5;

	public function execute(array $args, int $userId, Context $ctx): array
	{
		$status = (string)($args['status'] ?? 'open');
		$limit = max(1, min(50, (int)($args['limit'] ?? 10)));

		$filter = ['RESPONSIBLE_ID' => $userId];
		if ($status === 'open')
		{
			$filter['@STATUS'] = self::STATUS_OPEN;
		}
		elseif ($status === 'completed')
		{
			$filter['=STATUS'] = self::STATUS_COMPLETED;
		}

		$result = $ctx->restCall('tasks.task.list', [
			'filter' => $filter,
			'select' => ['ID', 'TITLE', 'DEADLINE', 'STATUS'],
			'order' => ['DEADLINE' => 'ASC'],
			'limit' => $limit,
		]);

		$tasks = [];
		foreach ((array)($result['tasks'] ?? []) as $task)
		{
			$task = (array)$task;
			$tasks[] = [
				'id' => (int)$task['id'],
				'title' => (string)$task['title'],
				'deadline' => (string)($task['deadline'] ?? ''),
			];
		}

		return ['tasks' => $tasks, 'total' => (int)($result['total'] ?? count($tasks))];
	}
}
