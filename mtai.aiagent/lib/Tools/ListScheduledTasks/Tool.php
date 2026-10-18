<?php

namespace Mtai\Aiagent\Tools\ListScheduledTasks;

use Mtai\Aiagent\Orm\ScheduleTable;
use Mtai\Aiagent\Tools\Context;

/** Список предстоящих отложенных заданий автора запроса. */
class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$rows = ScheduleTable::getList([
			'select' => ['ID', 'INSTRUCTION', 'RUN_AT'],
			'filter' => [
				'=USER_ID' => $userId,
				'=STATUS' => ScheduleTable::STATUS_PENDING,
			],
			'order' => ['RUN_AT' => 'ASC'],
			'limit' => 20,
		])->fetchAll();

		$tasks = [];
		foreach ($rows as $row)
		{
			$tasks[] = [
				'task_id' => (int)$row['ID'],
				'run_at' => $row['RUN_AT']->format('Y-m-d H:i'),
				'instruction' => mb_substr((string)$row['INSTRUCTION'], 0, 300),
			];
		}

		return ['count' => count($tasks), 'tasks' => $tasks];
	}
}
