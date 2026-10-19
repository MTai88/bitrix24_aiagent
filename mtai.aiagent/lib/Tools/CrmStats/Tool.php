<?php

namespace Mtai\Aiagent\Tools\CrmStats;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	private const PAGE = 50;
	private const MAX_PAGES = 4;

	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? 'deal');
		if (!in_array($type, ['lead', 'deal'], true))
		{
			return ['error' => 'crm_stats supports lead and deal'];
		}

		$filter = [];
		$semantic = (string)($args['stage_semantic'] ?? 'all');
		if ($semantic !== '' && $semantic !== 'all')
		{
			$filter['=stageSemanticId'] = match ($semantic)
			{
				'won' => 'S',
				'lost' => 'F',
				default => 'P',
			};
		}
		if (!empty($args['created_from']))
		{
			$filter['>=createdTime'] = $this->day((string)$args['created_from'], false);
		}
		if (!empty($args['created_to']))
		{
			$filter['<=createdTime'] = $this->day((string)$args['created_to'], true);
		}
		if ($type === 'deal' && !empty($args['category']))
		{
			$categoryId = $ctx->resolveCategoryId((string)$args['category']);
			if ($categoryId === null)
			{
				return ['error' => 'Воронка «' . $args['category'] . '» не найдена'];
			}
			$filter['=categoryId'] = $categoryId;
		}

		$responsible = trim((string)($args['responsible'] ?? ''));
		if ($responsible !== '')
		{
			$who = in_array(mb_strtolower($responsible), ['я', 'мне', 'мой', 'мои'], true) ? $userId : $responsible;
			$resolved = $ctx->resolveUser(is_int($who) ? $who : (string)$who);
			if (!isset($resolved['id']))
			{
				return $resolved;
			}
			$filter['=assignedById'] = (int)$resolved['id'];
		}

		$byStage = [];
		$count = 0;
		$sum = 0.0;
		$seen = 0;
		$truncated = false;
		$seenIds = [];

		for ($page = 0; $page < self::MAX_PAGES; $page++)
		{
			$result = $ctx->restCall('crm.item.list', [
				'entityTypeId' => Context::entityTypeId($type),
				'filter' => $filter,
				'select' => ['id', 'opportunity', 'stageId', 'categoryId'],
				'order' => ['id' => 'ASC'],
				'start' => $page * self::PAGE,
				'limit' => self::PAGE,
			]);
			$items = (array)($result['items'] ?? []);
			if ($items === [])
			{
				break;
			}

			$firstId = (int)(((array)$items[0])['id'] ?? 0);
			if ($firstId > 0 && isset($seenIds[$firstId]))
			{
				break;
			}

			foreach ($items as $item)
			{
				$item = (array)$item;
				$id = (int)($item['id'] ?? 0);
				if ($id > 0 && isset($seenIds[$id]))
				{
					continue;
				}
				$seenIds[$id] = true;
				if (!AccessGuard::crmCanRead($userId, $type, $id))
				{
					continue;
				}
				$stageId = (string)($item['stageId'] ?? '');
				$amount = (float)($item['opportunity'] ?? 0);
				if (!isset($byStage[$stageId]))
				{
					$byStage[$stageId] = [
						'stageId' => $stageId,
						'stageName' => $ctx->stageName($type, (int)($item['categoryId'] ?? 0), $stageId),
						'count' => 0,
						'sum' => 0.0,
					];
				}
				$byStage[$stageId]['count']++;
				$byStage[$stageId]['sum'] += $amount;
				$count++;
				$sum += $amount;
			}

			$seen += count($items);
			if (count($items) < self::PAGE)
			{
				break;
			}
			if ($page === self::MAX_PAGES - 1)
			{
				$truncated = true;
			}
		}

		return [
			'entity_type' => $type,
			'count' => $count,
			'sum' => $sum,
			'by_stage' => array_values($byStage),
			'truncated' => $truncated,
			'scanned' => $seen,
		];
	}

	private function day(string $date, bool $end): string
	{
		$date = trim($date);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
		{
			return $date . ($end ? ' 23:59:59' : ' 00:00:00');
		}

		return $date;
	}
}
