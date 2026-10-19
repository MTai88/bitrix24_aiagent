<?php

namespace Mtai\Aiagent\Tools\CrmCreateTask;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$title = trim((string)($args['title'] ?? ''));
		if ($title === '')
		{
			return ['error' => 'title is required'];
		}

		$fields = [
			'TITLE' => $title,
			'CREATED_BY' => $userId,
			'RESPONSIBLE_ID' => $userId,
		];

		if (!empty($args['description']))
		{
			$fields['DESCRIPTION'] = (string)$args['description'];
		}
		if (!empty($args['deadline']))
		{
			$fields['DEADLINE'] = Context::normalizeDeadline((string)$args['deadline']);
		}

		if (!empty($args['responsible_id']) || !empty($args['responsible_name']))
		{
			$who = !empty($args['responsible_id'])
				? (int)$args['responsible_id']
				: (string)$args['responsible_name'];
			$resolved = $ctx->resolveUser($who);
			if (!isset($resolved['id']))
			{
				return $resolved;
			}
			$fields['RESPONSIBLE_ID'] = (int)$resolved['id'];
		}

		foreach (['accomplices' => 'ACCOMPLICES', 'auditors' => 'AUDITORS'] as $arg => $field)
		{
			if (!empty($args[$arg]) && is_array($args[$arg]))
			{
				$resolved = $ctx->resolveUsers($args[$arg]);
				if (!isset($resolved['ids']))
				{
					return $resolved;
				}
				if ($resolved['ids'] !== [])
				{
					$fields[$field] = $resolved['ids'];
				}
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

		if (!empty($args['group']))
		{
			$group = $ctx->resolveGroup((string)$args['group']);
			if (!isset($group['id']))
			{
				return $group;
			}
			$fields['GROUP_ID'] = (int)$group['id'];
		}

		$bindings = $this->bindings($args, $userId, $ctx);
		if (isset($bindings['error']))
		{
			return $bindings;
		}
		if ($bindings !== [])
		{
			$fields['UF_CRM_TASK'] = $bindings;
		}

		$result = $ctx->restCall('tasks.task.add', ['fields' => $fields]);
		$taskId = (int)($result['task']['id'] ?? 0);

		$checklist = [];
		foreach ((array)($args['checklist'] ?? []) as $item)
		{
			$item = trim((string)$item);
			if ($item === '' || $taskId <= 0)
			{
				continue;
			}
			[$ok, $added] = $ctx->restSafe('task.checklistitem.add', [
				'taskId' => $taskId,
				'fields' => ['TITLE' => $item],
			]);
			$checklist[] = $ok ? ['title' => $item, 'id' => (int)$added] : ['title' => $item, 'error' => (string)$added];
		}

		$out = [
			'task_id' => $taskId,
			'title' => $title,
			'responsible_id' => (int)$fields['RESPONSIBLE_ID'],
			'url' => $ctx->taskUrl($taskId, (int)$fields['RESPONSIBLE_ID']),
		];
		if ($checklist !== [])
		{
			$out['checklist'] = $checklist;
		}

		return $out;
	}

	/** @return string[]|array{error: string} */
	private function bindings(array $args, int $userId, Context $ctx): array
	{
		$pairs = [];
		$type = (string)($args['entity_type'] ?? '');
		$id = (int)($args['entity_id'] ?? 0);
		if ($type !== '' && $id > 0)
		{
			$pairs[] = [$type, $id];
		}

		foreach ((array)($args['bindings'] ?? []) as $binding)
		{
			$binding = trim((string)$binding);
			if (!preg_match('/^(lead|deal|contact|company)\s*[:#]\s*(\d+)$/i', $binding, $match))
			{
				return ['error' => "Привязка «{$binding}» должна быть вида deal:12"];
			}
			$pairs[] = [strtolower($match[1]), (int)$match[2]];
		}

		$values = [];
		foreach ($pairs as [$entityType, $entityId])
		{
			if (!in_array($entityType, Context::entityTypes(), true))
			{
				return ['error' => "Unknown entity type: {$entityType}"];
			}
			if (!AccessGuard::crmCanRead($userId, $entityType, $entityId))
			{
				return ['error' => "Нет доступа к {$entityType} #{$entityId} — задача не создана"];
			}
			$values[] = Context::bindingPrefix($entityType) . '_' . $entityId;
		}

		return array_values(array_unique($values));
	}
}
