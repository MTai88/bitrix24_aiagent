<?php

namespace Mtai\Aiagent\Tools\CancelScheduledTask;

use Mtai\Aiagent\Orm\ScheduleTable;
use Mtai\Aiagent\Tools\Context;

/** Отмена отложенного задания: только своё, только в статусе pending. */
class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$taskId = (int)($args['task_id'] ?? 0);
		if ($taskId <= 0)
		{
			return ['error' => 'task_id is required'];
		}

		$row = ScheduleTable::getList([
			'select' => ['ID', 'USER_ID', 'STATUS'],
			'filter' => ['=ID' => $taskId],
			'limit' => 1,
		])->fetch();

		if (!$row || (int)$row['USER_ID'] !== $userId)
		{
			return ['error' => "task not found: {$taskId}"];
		}
		if ($row['STATUS'] !== ScheduleTable::STATUS_PENDING)
		{
			return ['error' => "task #{$taskId} is {$row['STATUS']}, cannot cancel"];
		}

		ScheduleTable::update($taskId, ['STATUS' => ScheduleTable::STATUS_CANCELLED]);

		return ['ok' => true, 'task_id' => $taskId, 'status' => ScheduleTable::STATUS_CANCELLED];
	}
}
