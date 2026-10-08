<?php

namespace Mtai\Aiagent\Tools\CrmCreateTask;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$fields = [
			'TITLE' => (string)($args['title'] ?? ''),
			'CREATED_BY' => $userId,
			'RESPONSIBLE_ID' => $userId,
		];

		if (!empty($args['description']))
		{
			$fields['DESCRIPTION'] = (string)$args['description'];
		}

		if (!empty($args['deadline']))
		{
			$fields['DEADLINE'] = $this->normalizeDeadline((string)$args['deadline']);
		}

		if (!empty($args['responsible_name']))
		{
			$responsibleId = $this->findUserByFullName((string)$args['responsible_name'], $ctx);
			if ($responsibleId > 0)
			{
				$fields['RESPONSIBLE_ID'] = $responsibleId;
			}
		}

		$type = (string)($args['entity_type'] ?? '');
		$id = (int)($args['entity_id'] ?? 0);
		if ($type !== '' && $id > 0 && in_array($type, $ctx::entityTypes(), true))
		{
			// Привязка выдаёт факт существования сущности — нужны права чтения.
			if (!AccessGuard::crmCanRead($userId, $type, $id))
			{
				return ['error' => "Нет доступа к {$type} #{$id} — задача не создана с этой привязкой"];
			}

			$fields['UF_CRM_TASK'] = [$ctx::bindingPrefix($type) . '_' . $id];
		}

		$result = $ctx->restCall('tasks.task.add', ['fields' => $fields]);

		return ['task_id' => (int)($result['task']['id'] ?? 0), 'title' => $fields['TITLE']];
	}

	private function normalizeDeadline(string $deadline): string
	{
		$deadline = trim($deadline);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline))
		{
			return $deadline . ' 18:00:00';
		}

		return $deadline;
	}

	private function findUserByFullName(string $fullName, Context $ctx): int
	{
		$result = $ctx->restCall('user.search', ['FILTER' => ['NAME' => $fullName]]);
		$user = (array)($result[0] ?? []);

		return (int)($user['ID'] ?? 0);
	}
}
