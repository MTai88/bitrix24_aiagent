<?php

namespace Mtai\Aiagent\Tools\TasksList;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	/** Незавершённые: новая, ждёт, выполняется, ждёт контроля, отложена. */
	private const STATUS_OPEN = [1, 2, 3, 4, 6];
	private const STATUS_COMPLETED = 5;

	public function execute(array $args, int $userId, Context $ctx): array
	{
		$status = (string)($args['status'] ?? 'open');
		$role = (string)($args['role'] ?? 'responsible');
		$limit = max(1, min(50, (int)($args['limit'] ?? 10)));
		$overdue = !empty($args['overdue']);

		$filter = $this->roleFilter($role, $userId);
		// В tasks 26 фильтр STATUS не отбирает (это subStatus), рабочее поле — REAL_STATUS.
		if ($status === 'open')
		{
			$filter['REAL_STATUS'] = self::STATUS_OPEN;
		}
		elseif ($status === 'completed')
		{
			$filter['REAL_STATUS'] = self::STATUS_COMPLETED;
		}

		if (!empty($args['query']))
		{
			$filter['%TITLE'] = trim((string)$args['query']);
		}
		if (!empty($args['deadline_from']))
		{
			$filter['>=DEADLINE'] = $this->dayBound((string)$args['deadline_from'], false);
		}
		if (!empty($args['deadline_to']))
		{
			$filter['<=DEADLINE'] = $this->dayBound((string)$args['deadline_to'], true);
		}
		if ($overdue)
		{
			$filter['<DEADLINE'] = date('Y-m-d H:i:s');
			$filter['!DEADLINE'] = false;
		}

		$type = (string)($args['entity_type'] ?? '');
		$entityId = (int)($args['entity_id'] ?? 0);
		if ($type !== '' && $entityId > 0 && in_array($type, Context::entityTypes(), true))
		{
			$filter['UF_CRM_TASK'] = Context::bindingPrefix($type) . '_' . $entityId;
		}

		$select = ['ID', 'TITLE', 'DEADLINE', 'STATUS', 'RESPONSIBLE_ID', 'CREATED_BY', 'PRIORITY'];
		$withDeadline = $ctx->restCall('tasks.task.list', [
			'filter' => $filter + ['!DEADLINE' => false],
			'select' => $select,
			'order' => ['DEADLINE' => 'ASC'],
			'limit' => $limit,
		]);

		$tasks = $this->formatTasks((array)($withDeadline['tasks'] ?? []), $ctx, $userId);

		// Задачи без срока не должны вытеснять задачи со сроком (NULL в MySQL идёт первым при ASC).
		$needMore = count($tasks) < $limit && !$overdue && empty($args['deadline_from']) && empty($args['deadline_to']);
		if ($needMore)
		{
			$without = $ctx->restCall('tasks.task.list', [
				'filter' => $filter + ['DEADLINE' => false],
				'select' => $select,
				'order' => ['ID' => 'DESC'],
				'limit' => $limit - count($tasks),
			]);
			$tasks = array_merge($tasks, $this->formatTasks((array)($without['tasks'] ?? []), $ctx, $userId));
		}

		return ['tasks' => $tasks, 'total' => count($tasks)];
	}

	/** Дата фильтра: начало или конец дня. «YYYY-MM-DD» не должен становиться 18:00. */
	private function dayBound(string $value, bool $end): string
	{
		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $match))
		{
			return $match[0] . ($end ? ' 23:59:59' : ' 00:00:00');
		}

		return $value;
	}

	private function roleFilter(string $role, int $userId): array
	{
		return match ($role)
		{
			'created' => ['CREATED_BY' => $userId],
			'accomplice' => ['ACCOMPLICE' => $userId],
			'auditor' => ['AUDITOR' => $userId],
			'all' => ['MEMBER' => $userId],
			default => ['RESPONSIBLE_ID' => $userId],
		};
	}

	/** @param array[] $raw */
	private function formatTasks(array $raw, Context $ctx, int $userId): array
	{
		$tasks = [];
		foreach ($raw as $task)
		{
			$task = (array)$task;
			$id = (int)($task['id'] ?? $task['ID'] ?? 0);
			$status = (int)($task['status'] ?? $task['STATUS'] ?? 0);
			$responsibleId = (int)($task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? 0);
			$deadline = (string)($task['deadline'] ?? $task['DEADLINE'] ?? '');
			$row = [
				'id' => $id,
				'title' => (string)($task['title'] ?? $task['TITLE'] ?? ''),
				'deadline' => $deadline,
				'status' => Context::taskStatusName($status),
				'overdue' => $deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < time() && $status !== 5,
				'url' => $ctx->taskUrl($id, $responsibleId > 0 ? $responsibleId : $userId),
			];
			if ($responsibleId > 0)
			{
				$row['responsibleId'] = $responsibleId;
				$name = $ctx->userName($responsibleId);
				if ($name !== '')
				{
					$row['responsibleName'] = $name;
				}
			}
			$tasks[] = $row;
		}

		return $tasks;
	}
}
