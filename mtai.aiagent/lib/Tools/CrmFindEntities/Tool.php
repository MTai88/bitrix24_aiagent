<?php

namespace Mtai\Aiagent\Tools\CrmFindEntities;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$limit = max(1, min(10, (int)($args['limit'] ?? 5)));

		$result = $ctx->restCall('crm.item.list', [
			'entityTypeId' => $ctx::entityTypeId($type),
			'filter' => ['%title' => (string)($args['query'] ?? '')],
			'select' => ['id', 'title', 'opportunity', 'currencyId', 'assignedById'],
			'limit' => $limit,
		]);

		// Показываем только сущности, доступные сотруднику на чтение.
		$items = [];
		foreach ((array)($result['items'] ?? []) as $item)
		{
			$item = (array)$item;
			if (AccessGuard::crmCanRead($userId, $type, (int)($item['id'] ?? 0)))
			{
				$items[] = $item;
			}
		}

		return ['items' => $items, 'total' => count($items)];
	}
}
