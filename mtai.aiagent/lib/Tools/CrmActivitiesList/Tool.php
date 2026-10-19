<?php

namespace Mtai\Aiagent\Tools\CrmActivitiesList;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$period = (string)($args['period'] ?? 'all');
		$limit = max(1, min(30, (int)($args['limit'] ?? 10)));

		$filter = [
			'RESPONSIBLE_ID' => $userId,
			'COMPLETED' => 'N',
		];
		if ($period === 'today')
		{
			$filter['>=DEADLINE'] = date('Y-m-d 00:00:00');
			$filter['<=DEADLINE'] = date('Y-m-d 23:59:59');
		}
		elseif ($period === 'overdue')
		{
			$filter['<DEADLINE'] = date('Y-m-d H:i:s');
		}

		$result = $ctx->restCall('crm.activity.list', [
			'filter' => $filter,
			'select' => ['ID', 'SUBJECT', 'DEADLINE', 'OWNER_TYPE_ID', 'OWNER_ID', 'PROVIDER_ID'],
			'order' => ['DEADLINE' => 'ASC'],
		]);

		$activities = [];
		foreach ($this->rows($result) as $row)
		{
			$row = (array)$row;
			$ownerTypeId = (int)($row['OWNER_TYPE_ID'] ?? 0);
			$ownerId = (int)($row['OWNER_ID'] ?? 0);
			$type = $this->typeById($ownerTypeId);
			$activities[] = [
				'id' => (int)($row['ID'] ?? 0),
				'title' => (string)($row['SUBJECT'] ?? ''),
				'deadline' => (string)($row['DEADLINE'] ?? ''),
				'entity_type' => $type,
				'entity_id' => $ownerId,
				'url' => $type !== '' ? $ctx->crmUrl($type, $ownerId) : '',
			];
			if (count($activities) >= $limit)
			{
				break;
			}
		}

		return ['activities' => $activities, 'total' => count($activities)];
	}

	/** crm.activity.list отдаёт список дел; лишние ключи ответа пропускаем. */
	private function rows(mixed $result): array
	{
		if (!is_array($result))
		{
			return [];
		}
		if (isset($result['ID']) || isset($result['id']))
		{
			return [$result];
		}

		$rows = [];
		foreach ($result as $row)
		{
			if (is_array($row) && (isset($row['ID']) || isset($row['id'])))
			{
				$rows[] = $row;
			}
		}

		return $rows;
	}

	private function typeById(int $typeId): string
	{
		return match ($typeId)
		{
			1 => 'lead',
			2 => 'deal',
			3 => 'contact',
			4 => 'company',
			default => '',
		};
	}
}
