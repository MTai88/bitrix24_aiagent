<?php

namespace Mtai\Aiagent\Tools\CrmFindEntities;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$typeId = $ctx::entityTypeId($type);
		$limit = max(1, min(10, (int)($args['limit'] ?? 5)));

		$filter = $this->buildFilter($args, $type, $ctx);
		if (isset($filter['__error__']))
		{
			$error = ['error' => $filter['__error__']];
			if (!empty($filter['candidates']))
			{
				$error['candidates'] = $filter['candidates'];
			}

			return $error;
		}

		// Лимит REST больше запрошенного: отсев по правам идёт после выборки,
		// иначе чужие карточки вебхука вытесняют доступные сотруднику.
		$fetch = min(50, $limit * 4);
		$result = $ctx->restCall('crm.item.list', [
			'entityTypeId' => $typeId,
			'filter' => $filter,
			'select' => ['id', 'title', 'name', 'lastName', 'opportunity', 'currencyId',
				'assignedById', 'stageId', 'categoryId', 'createdTime', 'contactId', 'companyId',
				'closedate', 'sourceId', 'lastActivityTime', 'fm'],
			'limit' => $fetch,
			'order' => ['createdTime' => 'DESC'],
		]);

		$items = [];
		foreach ((array)($result['items'] ?? []) as $item)
		{
			$item = (array)$item;
			if (!AccessGuard::crmCanRead($userId, $type, (int)($item['id'] ?? 0)))
			{
				continue;
			}
			$items[] = $ctx->presentCrmItem($item, $type);
			if (count($items) >= $limit)
			{
				break;
			}
		}

		return ['items' => $items, 'total' => count($items), 'entity_type' => $type];
	}

	/** Сборка REST-фильтра из аргументов LLM. */
	private function buildFilter(array $args, string $type, Context $ctx): array
	{
		$filter = [];

		$query = trim((string)($args['query'] ?? ''));
		if ($query !== '')
		{
			if ($type === 'contact')
			{
				$ids = $ctx->findContactIds($query, 10);
				if ($ids === [])
				{
					return ['__error__' => "Контакт «{$query}» не найден"];
				}
				$filter['@id'] = $ids;
			}
			elseif ($ctx::looksLikePhone($query) || $ctx::looksLikeEmail($query))
			{
				$commType = $ctx::looksLikeEmail($query) ? 'EMAIL' : 'PHONE';
				$ids = $ctx->findByComm($commType, $query)[$type] ?? [];
				if ($ids === [])
				{
					return ['__error__' => "По «{$query}» ничего не найдено"];
				}
				$filter['@id'] = $ids;
			}
			else
			{
				$filter['%title'] = $query;
			}
		}

		$commIds = $this->idsByComm($args, $type, $ctx);
		if (isset($commIds['__error__']))
		{
			return $commIds;
		}
		if ($commIds !== null)
		{
			$filter['@id'] = isset($filter['@id'])
				? array_values(array_intersect($filter['@id'], $commIds))
				: $commIds;
			if ($filter['@id'] === [])
			{
				return ['__error__' => 'Ничего не найдено по телефону/email и остальным критериям'];
			}
		}

		$isDeal = $type === 'deal';
		$isLead = $type === 'lead';
		if ($isDeal || $isLead)
		{
			$stageId = trim((string)($args['stage_id'] ?? ''));
			$semantic = trim((string)($args['stage_semantic'] ?? ''));
			if ($stageId !== '')
			{
				$categoryForStage = $isDeal ? (int)($args['category_id'] ?? 0) : 0;
				$resolved = $ctx->resolveStage($type, $categoryForStage, $stageId);
				$filter['=stageId'] = $resolved ?? $stageId;
			}
			elseif ($semantic !== '')
			{
				$filter['=stageSemanticId'] = match ($semantic)
				{
					'won' => 'S',
					'lost' => 'F',
					default => 'P',
				};
			}
		}

		if ($isDeal)
		{
			$categoryId = null;
			$categoryName = trim((string)($args['category_name'] ?? ''));
			if ($categoryName !== '')
			{
				$categoryId = $ctx->resolveCategoryId($categoryName);
				if ($categoryId === null)
				{
					return ['__error__' => "Воронка «{$categoryName}» не найдена"];
				}
			}
			elseif (isset($args['category_id']))
			{
				$categoryId = (int)$args['category_id'];
			}
			if ($categoryId !== null)
			{
				$filter['=categoryId'] = $categoryId;
			}

			if (!empty($args['close_from']))
			{
				$filter['>=closedate'] = $this->normalizeDate((string)$args['close_from']);
			}
			if (!empty($args['close_to']))
			{
				$filter['<=closedate'] = $this->normalizeDate((string)$args['close_to'], true);
			}
			if (!empty($args['inactive_days']))
			{
				$days = max(1, (int)$args['inactive_days']);
				$filter['<lastActivityTime'] = date('Y-m-d H:i:s', strtotime("-{$days} days"));
			}
		}

		if (isset($args['amount_min']) && $args['amount_min'] !== '' && $args['amount_min'] !== null)
		{
			$filter['>=opportunity'] = (float)$args['amount_min'];
		}
		if (isset($args['amount_max']) && $args['amount_max'] !== '' && $args['amount_max'] !== null)
		{
			$filter['<=opportunity'] = (float)$args['amount_max'];
		}

		$responsible = trim((string)($args['responsible'] ?? ''));
		if ($responsible !== '')
		{
			$resolved = $ctx->resolveUser($responsible);
			if (!isset($resolved['id']))
			{
				return ['__error__' => (string)($resolved['error'] ?? 'Ответственный не найден'), 'candidates' => $resolved['candidates'] ?? null];
			}
			$filter['=assignedById'] = (int)$resolved['id'];
		}

		if (!empty($args['created_from']))
		{
			$filter['>=createdTime'] = $this->normalizeDate((string)$args['created_from']);
		}
		if (!empty($args['created_to']))
		{
			$filter['<=createdTime'] = $this->normalizeDate((string)$args['created_to'], true);
		}

		if (!empty($args['company']))
		{
			$companyId = $ctx->findCompanyId((string)$args['company']);
			if ($companyId > 0)
			{
				$filter['=companyId'] = $companyId;
			}
			elseif ($type === 'company')
			{
				$filter['%title'] = (string)$args['company'];
			}
			else
			{
				return ['__error__' => 'Компания «' . $args['company'] . '» не найдена'];
			}
		}
		if (!empty($args['contact']))
		{
			$contactId = $ctx->findContactId((string)$args['contact']);
			if ($contactId > 0)
			{
				$filter['=contactId'] = $contactId;
			}
			elseif ($type === 'contact')
			{
				$ids = $ctx->findContactIds((string)$args['contact'], 10);
				if ($ids === [])
				{
					return ['__error__' => 'Контакт «' . $args['contact'] . '» не найден'];
				}
				$filter['@id'] = isset($filter['@id'])
					? array_values(array_intersect($filter['@id'], $ids))
					: $ids;
			}
			else
			{
				return ['__error__' => 'Контакт «' . $args['contact'] . '» не найден'];
			}
		}

		return $filter;
	}

	/**
	 * ID по явному телефону/email. null — критерий не задан.
	 * @return int[]|array{__error__: string}|null
	 */
	private function idsByComm(array $args, string $type, Context $ctx): ?array
	{
		$ids = null;
		foreach (['phone' => 'PHONE', 'email' => 'EMAIL'] as $key => $commType)
		{
			$value = trim((string)($args[$key] ?? ''));
			if ($value === '')
			{
				continue;
			}
			$found = $ctx->findByComm($commType, $value)[$type] ?? [];
			if ($found === [])
			{
				return ['__error__' => ($commType === 'PHONE' ? 'Телефон' : 'Email') . " «{$value}» не найден"];
			}
			$ids = $ids === null ? $found : array_values(array_intersect($ids, $found));
		}

		return $ids;
	}

	/** YYYY-MM-DD → формат фильтра; для «до» — конец дня. */
	private function normalizeDate(string $date, bool $endOfDay = false): string
	{
		$date = trim($date);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
		{
			return $date . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
		}

		return $date;
	}
}
