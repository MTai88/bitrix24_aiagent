<?php

namespace Mtai\Aiagent\Tools\TaskUpdate;

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

		$taskResult = $ctx->restCall('tasks.task.get', [
			'taskId' => $taskId,
			'select' => ['ID', 'TITLE', 'CREATED_BY', 'RESPONSIBLE_ID', 'ACCOMPLICES', 'AUDITORS'],
		]);
		$task = (array)($taskResult['task'] ?? []);
		if ($task === [] || !AccessGuard::taskAccessible($userId, $task))
		{
			return ['error' => "Задача #{$taskId} вам недоступна"];
		}

		$action = (string)($args['action'] ?? 'update');
		if (in_array($action, ['start', 'defer', 'renew'], true))
		{
			$ctx->restCall('tasks.task.' . $action, ['taskId' => $taskId]);

			return [
				'task_id' => $taskId,
				'action' => $action,
				'title' => (string)($task['title'] ?? $task['TITLE'] ?? ''),
				'url' => $ctx->taskUrl($taskId, $userId),
			];
		}

		$fields = [];
		if (!empty($args['title']))
		{
			$fields['TITLE'] = (string)$args['title'];
		}
		if (!empty($args['description']))
		{
			$fields['DESCRIPTION'] = (string)$args['description'];
		}
		if (!empty($args['deadline']))
		{
			$fields['DEADLINE'] = Context::normalizeDeadline((string)$args['deadline']);
		}
		if (!empty($args['responsible']))
		{
			$resolved = $ctx->resolveUser((string)$args['responsible']);
			if (!isset($resolved['id']))
			{
				return $resolved;
			}
			$fields['RESPONSIBLE_ID'] = (int)$resolved['id'];
		}
		foreach (['accomplices' => 'ACCOMPLICES', 'auditors' => 'AUDITORS'] as $arg => $field)
		{
			if (isset($args[$arg]) && is_array($args[$arg]))
			{
				$resolved = $ctx->resolveUsers($args[$arg]);
				if (!isset($resolved['ids']))
				{
					return $resolved;
				}
				$fields[$field] = $resolved['ids'];
			}
		}
		if (!empty($args['priority']))
		{
			$priority = Context::taskPriority((string)$args['priority']);
			if ($priority === null)
			{
				return ['error' => 'priority must be low, normal or high'];
			}
			$fields['PRIORITY'] = $priority;
		}

		if ($fields === [])
		{
			return ['error' => 'nothing to update'];
		}

		$ctx->restCall('tasks.task.update', [
			'taskId' => $taskId,
			'fields' => $fields,
		]);

		return [
			'task_id' => $taskId,
			'updated' => true,
			'title' => (string)($fields['TITLE'] ?? ($task['title'] ?? $task['TITLE'] ?? '')),
			'url' => $ctx->taskUrl($taskId, (int)($fields['RESPONSIBLE_ID'] ?? $userId)),
		];
	}
}
