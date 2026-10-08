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

		$filter = $this->buildFilter($args, $type, $typeId, $ctx);
		if (isset($filter['__error__']))
		{
			return ['error' => $filter['__error__']];
		}

		$result = $ctx->restCall('crm.item.list', [
			'entityTypeId' => $typeId,
			'filter' => $filter,
			'select' => ['id', 'title', 'name', 'lastName', 'opportunity', 'currencyId',
				'assignedById', 'stageId', 'categoryId', 'createdTime', 'contactId', 'companyId'],
			'limit' => $limit,
			'order' => ['createdTime' => 'DESC'],
		]);

		// Показываем только сущности, доступные сотруднику на чтение.
		$items = [];
		foreach ((array)($result['items'] ?? []) as $item)
		{
			$item = (array)$item;
			if (AccessGuard::crmCanRead($userId, $type, (int)($item['id'] ?? 0)))
			{
				$items[] = $this->formatItem($item, $type);
			}
		}

		return ['items' => $items, 'total' => count($items)];
	}

	/** Сборка REST-фильтра из аргументов LLM. */
	private function buildFilter(array $args, string $type, int $typeId, Context $ctx): array
	{
		$filter = [];

		// Название (для контакта — по NAME).
		$query = trim((string)($args['query'] ?? ''));
		if ($query !== '')
		{
			$filter[$type === 'contact' ? '%name' : '%title'] = $query;
		}

		$isDeal = $type === 'deal';
		if ($isDeal)
		{
			// Стадия: точный status_id или семантика (open/won/lost).
			$stageId = trim((string)($args['stage_id'] ?? ''));
			$semantic = trim((string)($args['stage_semantic'] ?? ''));
			if ($stageId !== '')
			{
				$filter['=stageId'] = $stageId;
			}
			elseif ($semantic !== '')
			{
				// stageSemanticId: P — в работе, S — успех, F — провал.
				$filter['=stageSemanticId'] = match ($semantic)
				{
					'won' => 'S',
					'lost' => 'F',
					default => 'P',
				};
			}

			// Воронка: название приоритетнее ID.
			$categoryId = null;
			$categoryName = trim((string)($args['category_name'] ?? ''));
			if ($categoryName !== '')
			{
				$categoryId = $this->resolveCategoryId($categoryName, $ctx);
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
		}

		// Сумма (диапазон).
		if (!empty($args['amount_min']))
		{
			$filter['>=opportunity'] = (float)$args['amount_min'];
		}
		if (!empty($args['amount_max']))
		{
			$filter['<=opportunity'] = (float)$args['amount_max'];
		}

		// Ответственный: имя → ID.
		$responsible = trim((string)($args['responsible'] ?? ''));
		if ($responsible !== '')
		{
			$responsibleId = ctype_digit($responsible)
				? (int)$responsible
				: $this->findUserId($responsible, $ctx);
			if ($responsibleId > 0)
			{
				$filter['=assignedById'] = $responsibleId;
			}
			else
			{
				return ['__error__' => "Ответственный «{$responsible}» не найден"];
			}
		}

		// Дата создания (диапазон).
		if (!empty($args['created_from']))
		{
			$filter['>=createdTime'] = $this->normalizeDate((string)$args['created_from']);
		}
		if (!empty($args['created_to']))
		{
			$filter['<=createdTime'] = $this->normalizeDate((string)$args['created_to'], true);
		}

		// Связанные компания/контакт: ID или название → ID.
		if (!empty($args['company']))
		{
			$companyId = $this->resolveRelatedId((string)$args['company'], 'company', $ctx);
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
			$contactId = $this->resolveRelatedId((string)$args['contact'], 'contact', $ctx);
			if ($contactId > 0)
			{
				$filter['=contactId'] = $contactId;
			}
			elseif ($type === 'contact')
			{
				$filter['%name'] = (string)$args['contact'];
			}
			else
			{
				return ['__error__' => 'Контакт «' . $args['contact'] . '» не найден'];
			}
		}

		return $filter;
	}

	/** Компактное представление элемента для модели. */
	private function formatItem(array $item, string $type): array
	{
		$out = [
			'id' => (int)($item['id'] ?? 0),
			'title' => (string)($item['title'] ?? ''),
			'assignedById' => (int)($item['assignedById'] ?? 0),
		];

		if ($type === 'contact' && $out['title'] === '')
		{
			$out['title'] = trim(($item['name'] ?? '') . ' ' . ($item['lastName'] ?? ''));
		}

		foreach (['opportunity', 'currencyId', 'stageId', 'categoryId', 'createdTime', 'contactId', 'companyId'] as $field)
		{
			if (!empty($item[$field]))
			{
				$out[$field] = $item[$field];
			}
		}

		return $out;
	}

	/** ID воронки по названию (crm.dealcategory.list). */
	private function resolveCategoryId(string $name, Context $ctx): ?int
	{
		$normalizedName = mb_strtolower($name);

		// «Общая» — категория 0, в списке категорий отсутствует.
		if (in_array($normalizedName, ['общая', 'general', 'default'], true))
		{
			return 0;
		}

		$result = $ctx->restCall('crm.dealcategory.list', []);
		foreach ((array)($result ?? []) as $category)
		{
			$category = (array)$category;
			if (mb_strtolower((string)($category['NAME'] ?? '')) === $normalizedName)
			{
				return (int)$category['ID'];
			}
		}

		// Частичное совпадение.
		foreach ((array)($result ?? []) as $category)
		{
			$category = (array)$category;
			if (mb_stripos((string)($category['NAME'] ?? ''), $normalizedName) !== false)
			{
				return (int)$category['ID'];
			}
		}

		return null;
	}

	/** Поиск пользователя по имени или «Имя Фамилия» (user.search). */
	private function findUserId(string $name, Context $ctx): int
	{
		$queries = [$name];
		$parts = preg_split('/\s+/', trim($name)) ?: [$name];
		if (count($parts) >= 2)
		{
			// user.search по NAME матчит и «Имя Фамилия» целиком в некоторых
			// сборках, и только имя — пробуем оба варианта плюс фамилию.
			$queries[] = $parts[0];
			$queries[] = $parts[1];
		}

		foreach ($queries as $query)
		{
			$result = $ctx->restCall('user.search', ['FILTER' => ['NAME' => $query]]);
			foreach ((array)$result as $user)
			{
				$user = (array)$user;
				$full = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? ''));
				// Для короткого запроса (имя) берём первое совпадение;
				// для полного — требуем совпадения имени или фамилии.
				if (mb_stripos($full, $query) !== false)
				{
					return (int)($user['ID'] ?? 0);
				}
			}
		}

		return 0;
	}

	/**
	 * ID связанной сущности: число или название. Контакт ищется по имени,
	 * фамилии и их комбинации (в CRM это разные поля, «%name» матчит
	 * только первое слово).
	 */
	private function resolveRelatedId(string $value, string $type, Context $ctx): int
	{
		if (ctype_digit($value))
		{
			return (int)$value;
		}

		$typeId = $ctx::entityTypeId($type);

		if ($type === 'contact')
		{
			$filters = [];
			$parts = preg_split('/\s+/', trim($value)) ?: [$value];
			if (count($parts) >= 2)
			{
				$filters[] = ['=name' => $parts[0], '=lastName' => implode(' ', array_slice($parts, 1))];
			}
			$filters[] = ['%name' => $value];
			$filters[] = ['%lastName' => $value];

			foreach ($filters as $filter)
			{
				$result = $ctx->restCall('crm.item.list', [
					'entityTypeId' => $typeId,
					'filter' => $filter,
					'select' => ['id'],
					'limit' => 1,
				]);
				$found = (int)(((array)($result['items'][0] ?? []))['id'] ?? 0);
				if ($found > 0)
				{
					return $found;
				}
			}

			return 0;
		}

		$result = $ctx->restCall('crm.item.list', [
			'entityTypeId' => $typeId,
			'filter' => ['%title' => $value],
			'select' => ['id'],
			'limit' => 1,
		]);

		return (int)(((array)($result['items'][0] ?? []))['id'] ?? 0);
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
