<?php

namespace Mtai\Aiagent\Tools;

use Mtai\Aiagent\Config;

/**
 * Контекст исполнения инструмента: REST-клиент портала и общие константы
 * (типы CRM, префиксы привязок), плюс хелперы, одинаковые для всех скиллов:
 * сотрудник, стадия, ссылка на карточку, телефон/email, ФИО контакта.
 */
final class Context
{
	/** @var array<int, string> */
	private array $userNames = [];

	/** @var array<int, array{STATUS_ID: string, NAME: string, ENTITY_ID: string}>|null */
	private ?array $stages = null;

	public function __construct(
		public readonly ?\Mtai\Aiagent\Rest\Client $rest = null,
	) {
	}

	public function rest(): \Mtai\Aiagent\Rest\Client
	{
		return $this->rest ?? new \Mtai\Aiagent\Rest\Client();
	}

	/** Выполнить REST-метод портала от имени модуля. */
	public function restCall(string $method, array $params = [])
	{
		return $this->rest()->call($method, $params);
	}

	/** REST-вызов без исключений: [ok, result|error]. */
	public function restSafe(string $method, array $params = []): array
	{
		try
		{
			return [true, $this->rest()->call($method, $params)];
		}
		catch (\Mtai\Aiagent\RestException $e)
		{
			return [false, $e->getMessage()];
		}
	}

	/** entityTypeId CRM: lead=1, deal=2, contact=3, company=4. */
	public static function entityTypeId(string $type): int
	{
		return match ($type)
		{
			'lead' => 1,
			'deal' => 2,
			'contact' => 3,
			'company' => 4,
			default => throw new \InvalidArgumentException("Unknown entity type: {$type}"),
		};
	}

	/** Префикс привязки CRM (UF_CRM_TASK, ENTITY_TYPE таймлайна): L/D/C/CO. */
	public static function bindingPrefix(string $type): string
	{
		return match ($type)
		{
			'lead' => 'L',
			'deal' => 'D',
			'contact' => 'C',
			'company' => 'CO',
			default => throw new \InvalidArgumentException("Unknown entity type: {$type}"),
		};
	}

	public static function entityTypes(): array
	{
		return ['lead', 'deal', 'contact', 'company'];
	}

	/** Ссылка на карточку CRM. Пустая строка, если портал не настроен. */
	public function crmUrl(string $type, int $id): string
	{
		$portal = rtrim(Config::portalUrl(), '/');
		if ($portal === '' || $id <= 0 || !in_array($type, self::entityTypes(), true))
		{
			return '';
		}

		return "{$portal}/crm/{$type}/details/{$id}/";
	}

	/** Ссылка на задачу. */
	public function taskUrl(int $id, int $userId = 0): string
	{
		$portal = rtrim(Config::portalUrl(), '/');
		if ($portal === '' || $id <= 0)
		{
			return '';
		}

		$owner = $userId > 0 ? $userId : 0;

		return "{$portal}/company/personal/user/{$owner}/tasks/task/view/{$id}/";
	}

	/**
	 * Сотрудник по ID или по имени.
	 * Один точный результат — ['id' => int, 'name' => string].
	 * Несколько — ['error' => string, 'candidates' => [{id, name}, ...]].
	 * Никого — ['error' => string].
	 */
	public function resolveUser(string|int $who): array
	{
		if (is_int($who) || (is_string($who) && ctype_digit(trim($who))))
		{
			$id = (int)$who;
			if ($id <= 0)
			{
				return ['error' => 'Не указан сотрудник'];
			}

			return ['id' => $id, 'name' => $this->userName($id)];
		}

		$query = trim((string)$who);
		if ($query === '')
		{
			return ['error' => 'Не указан сотрудник'];
		}

		$users = $this->searchUsers($query);
		$parts = preg_split('/\s+/u', $query) ?: [];
		if (count($parts) >= 2)
		{
			$narrow = array_values(array_filter(
				$users,
				static function (array $user) use ($parts): bool
				{
					$full = mb_strtolower($user['name']);
					foreach ($parts as $part)
					{
						if (mb_stripos($full, $part) === false)
						{
							return false;
						}
					}

					return true;
				}
			));
			if ($narrow !== [])
			{
				$users = $narrow;
			}
		}

		if ($users === [])
		{
			return ['error' => "Сотрудник «{$query}» не найден"];
		}

		$needle = mb_strtolower($query);
		$exact = array_values(array_filter(
			$users,
			static fn(array $user): bool => mb_strtolower($user['name']) === $needle
				|| mb_strtolower($user['reversed']) === $needle
		));
		$pool = $exact !== [] ? $exact : $users;
		if (count($pool) === 1)
		{
			return ['id' => $pool[0]['id'], 'name' => $pool[0]['name']];
		}

		$candidates = [];
		foreach (array_slice($pool, 0, 5) as $user)
		{
			$candidates[] = ['id' => $user['id'], 'name' => $user['name']];
		}

		return [
			'error' => "Найдено несколько сотрудников по запросу «{$query}» — уточните, кого именно",
			'candidates' => $candidates,
		];
	}

	/** Несколько сотрудников (соисполнители, наблюдатели). */
	public function resolveUsers(array $values): array
	{
		$ids = [];
		foreach ($values as $value)
		{
			if (is_array($value))
			{
				$value = $value['name'] ?? $value['id'] ?? '';
			}
			$value = trim((string)$value);
			if ($value === '')
			{
				continue;
			}

			$resolved = $this->resolveUser($value);
			if (!isset($resolved['id']))
			{
				return $resolved;
			}
			$ids[] = (int)$resolved['id'];
		}

		return ['ids' => array_values(array_unique($ids))];
	}

	/** Имя сотрудника по ID, с кешем на процесс. */
	public function userName(int $id): string
	{
		if ($id <= 0)
		{
			return '';
		}
		if (isset($this->userNames[$id]))
		{
			return $this->userNames[$id];
		}

		[$ok, $result] = $this->restSafe('user.get', ['ID' => $id]);
		$user = (array)(($ok ? ($result[0] ?? []) : []));
		$name = trim((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? ''));
		$this->userNames[$id] = $name;

		return $name;
	}

	/** Название стадии по status_id (кеш crm.status.list). */
	public function stageName(string $type, int $categoryId, string $stageId): string
	{
		$stageId = trim($stageId);
		if ($stageId === '')
		{
			return '';
		}

		$preferred = $this->stageEntityId($type, $categoryId);
		$fallback = '';
		foreach ($this->loadStages() as $stage)
		{
			if (strcasecmp($stage['STATUS_ID'], $stageId) !== 0)
			{
				continue;
			}
			if ($preferred !== '' && $stage['ENTITY_ID'] === $preferred)
			{
				return $stage['NAME'];
			}
			$fallback = $fallback !== '' ? $fallback : $stage['NAME'];
		}

		return $fallback;
	}

	/**
	 * status_id стадии по ID или названию внутри воронки.
	 * null — стадия не найдена.
	 */
	public function resolveStage(string $type, int $categoryId, string $stage): ?string
	{
		$stage = trim($stage);
		if ($stage === '')
		{
			return null;
		}

		$rows = $this->stagesFor($type, $categoryId);
		foreach ($rows as $row)
		{
			if (strcasecmp($row['STATUS_ID'], $stage) === 0)
			{
				return $row['STATUS_ID'];
			}
		}

		$needle = mb_strtolower($stage);
		foreach ($rows as $row)
		{
			if (mb_strtolower($row['NAME']) === $needle)
			{
				return $row['STATUS_ID'];
			}
		}
		foreach ($rows as $row)
		{
			if (mb_stripos($row['NAME'], $stage) !== false)
			{
				return $row['STATUS_ID'];
			}
		}

		return null;
	}

	/** ID воронки сделок по ID или названию. null — не найдена. */
	public function resolveCategoryId(string|int $category): ?int
	{
		if (is_int($category) || ctype_digit(trim((string)$category)))
		{
			return (int)$category;
		}

		$name = trim((string)$category);
		$normalized = mb_strtolower($name);
		if (in_array($normalized, ['общая', 'general', 'default'], true))
		{
			return 0;
		}

		[$ok, $result] = $this->restSafe('crm.dealcategory.list', []);
		if (!$ok)
		{
			return null;
		}

		foreach ((array)$result as $row)
		{
			$row = (array)$row;
			if (mb_strtolower((string)($row['NAME'] ?? '')) === $normalized)
			{
				return (int)$row['ID'];
			}
		}
		foreach ((array)$result as $row)
		{
			$row = (array)$row;
			if (mb_stripos((string)($row['NAME'] ?? ''), $name) !== false)
			{
				return (int)$row['ID'];
			}
		}

		return null;
	}

	/** Проект (группа соцсети) по названию. */
	public function resolveGroup(string $name): array
	{
		$name = trim($name);
		if ($name === '')
		{
			return ['error' => 'Не указан проект'];
		}
		if (ctype_digit($name))
		{
			return ['id' => (int)$name, 'name' => $name];
		}

		[$ok, $result] = $this->restSafe('sonet_group.get', [
			'FILTER' => ['%NAME' => $name, 'ACTIVE' => 'Y'],
		]);
		if (!$ok)
		{
			return ['error' => 'sonet_group.get: ' . $result];
		}

		$groups = [];
		foreach ((array)$result as $row)
		{
			$row = (array)$row;
			$id = (int)($row['ID'] ?? 0);
			$title = (string)($row['NAME'] ?? '');
			if ($id > 0)
			{
				$groups[] = ['id' => $id, 'name' => $title];
			}
		}
		if ($groups === [])
		{
			return ['error' => "Проект «{$name}» не найден"];
		}

		$needle = mb_strtolower($name);
		$exact = array_values(array_filter(
			$groups,
			static fn(array $group): bool => mb_strtolower($group['name']) === $needle
		));
		$pool = $exact !== [] ? $exact : $groups;
		if (count($pool) === 1)
		{
			return $pool[0];
		}

		return [
			'error' => "Найдено несколько проектов по запросу «{$name}» — уточните",
			'candidates' => array_slice($pool, 0, 5),
		];
	}

	/**
	 * ID контакта по ID, «Имя Фамилия», телефону или email.
	 * 0 — не найден.
	 */
	public function findContactId(string $value): int
	{
		$ids = $this->findContactIds($value, 1);

		return $ids[0] ?? 0;
	}

	/** @return int[] */
	public function findContactIds(string $value, int $limit = 5): array
	{
		$value = trim($value);
		if ($value === '')
		{
			return [];
		}
		if (ctype_digit($value))
		{
			return [(int)$value];
		}

		if ($this->looksLikeEmail($value) || $this->looksLikePhone($value))
		{
			$type = $this->looksLikeEmail($value) ? 'EMAIL' : 'PHONE';
			$byComm = $this->findByComm($type, $value);

			return array_slice($byComm['contact'] ?? [], 0, $limit);
		}

		$limit = max(1, $limit);
		$parts = preg_split('/\s+/u', $value) ?: [$value];
		$filters = [];
		if (count($parts) >= 2)
		{
			$filters[] = ['=name' => $parts[0], '=lastName' => implode(' ', array_slice($parts, 1))];
			$filters[] = ['=lastName' => $parts[0], '=name' => implode(' ', array_slice($parts, 1))];
		}
		$filters[] = ['%name' => $value];
		$filters[] = ['%lastName' => $value];

		$ids = [];
		foreach ($filters as $filter)
		{
			$result = $this->restCall('crm.item.list', [
				'entityTypeId' => self::entityTypeId('contact'),
				'filter' => $filter,
				'select' => ['id', 'name', 'lastName'],
				'limit' => $limit,
			]);
			foreach ((array)($result['items'] ?? []) as $item)
			{
				$id = (int)(((array)$item)['id'] ?? 0);
				if ($id > 0)
				{
					$ids[$id] = $id;
				}
			}
			if ($ids !== [] && count($parts) >= 2)
			{
				break;
			}
		}

		return array_slice(array_values($ids), 0, $limit);
	}

	/** ID компании по ID или названию. 0 — не найдена. */
	public function findCompanyId(string $value): int
	{
		$value = trim($value);
		if ($value === '')
		{
			return 0;
		}
		if (ctype_digit($value))
		{
			return (int)$value;
		}

		$result = $this->restCall('crm.item.list', [
			'entityTypeId' => self::entityTypeId('company'),
			'filter' => ['%title' => $value],
			'select' => ['id'],
			'limit' => 1,
		]);

		return (int)(((array)($result['items'][0] ?? []))['id'] ?? 0);
	}

	/**
	 * ID сущностей по телефону или email (crm.duplicate.findbycomm).
	 * @return array{lead: int[], contact: int[], company: int[]}
	 */
	public function findByComm(string $type, string $value): array
	{
		$empty = ['lead' => [], 'contact' => [], 'company' => []];
		$value = trim($value);
		if ($value === '')
		{
			return $empty;
		}
		if (strtoupper($type) === 'PHONE')
		{
			$value = preg_replace('/[^\d+]/', '', $value) ?? $value;
		}

		[$ok, $result] = $this->restSafe('crm.duplicate.findbycomm', [
			'type' => strtoupper($type),
			'values' => [$value],
		]);
		if (!$ok || !is_array($result))
		{
			return $empty;
		}

		$map = ['LEAD' => 'lead', 'CONTACT' => 'contact', 'COMPANY' => 'company'];
		foreach ($map as $key => $name)
		{
			foreach ((array)($result[$key] ?? $result[$name] ?? []) as $id)
			{
				$id = (int)$id;
				if ($id > 0)
				{
					$empty[$name][] = $id;
				}
			}
		}

		return $empty;
	}

	/** Найти контакт или создать, если точного совпадения нет. */
	public function findOrCreateContact(string $name, int $userId, array $extra = []): int
	{
		$found = $this->findContactId($name);
		if ($found > 0)
		{
			return $found;
		}

		$fields = $this->personFields($name, $extra);
		$fields['ASSIGNED_BY_ID'] = $userId;
		$fm = $this->fmFrom($extra);
		if ($fm !== [])
		{
			$fields['fm'] = $fm;
		}

		$result = $this->restCall('crm.item.add', [
			'entityTypeId' => self::entityTypeId('contact'),
			'fields' => $fields,
		]);

		return (int)($result['item']['id'] ?? 0);
	}

	/** Найти компанию или создать. */
	public function findOrCreateCompany(string $title, int $userId, array $extra = []): int
	{
		$found = $this->findCompanyId($title);
		if ($found > 0)
		{
			return $found;
		}

		$fields = [
			'TITLE' => $title,
			'ASSIGNED_BY_ID' => $userId,
		];
		$fm = $this->fmFrom($extra);
		if ($fm !== [])
		{
			$fields['fm'] = $fm;
		}

		$result = $this->restCall('crm.item.add', [
			'entityTypeId' => self::entityTypeId('company'),
			'fields' => $fields,
		]);

		return (int)($result['item']['id'] ?? 0);
	}

	/**
	 * Поля NAME/LAST_NAME/SECOND_NAME.
	 * Явные name/last_name/second_name приоритетнее разбора строки.
	 * Два слова — имя и фамилия, три — фамилия, имя и отчество.
	 */
	public function personFields(string $title, array $args = []): array
	{
		$name = trim((string)($args['name'] ?? ''));
		$last = trim((string)($args['last_name'] ?? ''));
		$second = trim((string)($args['second_name'] ?? ''));
		if ($name !== '' || $last !== '' || $second !== '')
		{
			return array_filter([
				'NAME' => $name,
				'LAST_NAME' => $last,
				'SECOND_NAME' => $second,
			], static fn(string $value): bool => $value !== '');
		}

		$parts = preg_split('/\s+/u', trim($title)) ?: [];
		$parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
		if (count($parts) >= 3)
		{
			return [
				'LAST_NAME' => $parts[0],
				'NAME' => $parts[1],
				'SECOND_NAME' => $parts[2],
			];
		}
		if (count($parts) === 2)
		{
			return ['NAME' => $parts[0], 'LAST_NAME' => $parts[1]];
		}

		return ['NAME' => trim($title)];
	}

	/**
	 * Телефон и email в формате crm.item.add/update (ключ fm, новые значения n0/n1).
	 * Ключ PHONE контроллер crm.item игнорирует.
	 */
	public function fmFrom(array $args): array
	{
		$fm = [];
		$index = 0;
		if (!empty($args['phone']))
		{
			$fm['n' . $index] = [
				'typeId' => 'PHONE',
				'valueType' => 'WORK',
				'value' => trim((string)$args['phone']),
			];
			$index++;
		}
		if (!empty($args['email']))
		{
			$fm['n' . $index] = [
				'typeId' => 'EMAIL',
				'valueType' => 'WORK',
				'value' => trim((string)$args['email']),
			];
		}

		return $fm;
	}

	/** Компактная карточка элемента CRM для модели: имена, стадия, ссылка. */
	public function presentCrmItem(array $item, string $type): array
	{
		$id = (int)($item['id'] ?? 0);
		$title = (string)($item['title'] ?? '');
		if ($type === 'contact' && $title === '')
		{
			$title = trim((string)($item['name'] ?? '') . ' ' . (string)($item['lastName'] ?? ''));
		}

		$out = [
			'id' => $id,
			'entity_type' => $type,
			'title' => $title,
		];
		$url = $this->crmUrl($type, $id);
		if ($url !== '')
		{
			$out['url'] = $url;
		}

		$assigned = (int)($item['assignedById'] ?? 0);
		if ($assigned > 0)
		{
			$out['assignedById'] = $assigned;
			$assignedName = $this->userName($assigned);
			if ($assignedName !== '')
			{
				$out['assignedByName'] = $assignedName;
			}
		}

		$stageId = (string)($item['stageId'] ?? '');
		if ($stageId !== '')
		{
			$out['stageId'] = $stageId;
			$stageName = $this->stageName($type, (int)($item['categoryId'] ?? 0), $stageId);
			if ($stageName !== '')
			{
				$out['stageName'] = $stageName;
			}
		}

		foreach (['opportunity', 'currencyId', 'categoryId', 'createdTime', 'contactId', 'companyId', 'closedate', 'sourceId', 'lastActivityTime'] as $field)
		{
			if (isset($item[$field]) && $item[$field] !== '' && $item[$field] !== null && $item[$field] !== [])
			{
				$out[$field] = $item[$field];
			}
		}

		$comms = $this->commsFromItem($item);
		if ($comms['phones'] !== [])
		{
			$out['phones'] = $comms['phones'];
		}
		if ($comms['emails'] !== [])
		{
			$out['emails'] = $comms['emails'];
		}

		return $out;
	}

	/** @return array{phones: string[], emails: string[]} */
	public function commsFromItem(array $item): array
	{
		$phones = [];
		$emails = [];
		foreach ((array)($item['fm'] ?? []) as $field)
		{
			$field = (array)$field;
			$type = strtoupper((string)($field['typeId'] ?? $field['TYPE_ID'] ?? ''));
			$value = trim((string)($field['value'] ?? $field['VALUE'] ?? ''));
			if ($value === '')
			{
				continue;
			}
			if ($type === 'PHONE')
			{
				$phones[] = $value;
			}
			elseif ($type === 'EMAIL')
			{
				$emails[] = $value;
			}
		}

		return ['phones' => $phones, 'emails' => $emails];
	}

	public static function taskStatusName(int $status): string
	{
		return match ($status)
		{
			1 => 'новая',
			2 => 'ждёт выполнения',
			3 => 'выполняется',
			4 => 'ждёт контроля',
			5 => 'завершена',
			6 => 'отложена',
			7 => 'отклонена',
			default => (string)$status,
		};
	}

	/** low/normal/high или 1/2/3. null — не распознано. */
	public static function taskPriority(string $value): ?int
	{
		return match (mb_strtolower(trim($value)))
		{
			'low', 'низкий', '0' => 0,
			'normal', 'средний', 'обычный', '1' => 1,
			'high', 'высокий', '2' => 2,
			default => null,
		};
	}

	/** YYYY-MM-DD → конец рабочего дня; YYYY-MM-DD HH:MM → с секундами. */
	public static function normalizeDeadline(string $deadline): string
	{
		$deadline = trim($deadline);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline))
		{
			return $deadline . ' 18:00:00';
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $deadline))
		{
			return str_replace('T', ' ', $deadline) . ':00';
		}

		return str_replace('T', ' ', $deadline);
	}

	public static function looksLikeEmail(string $value): bool
	{
		return str_contains($value, '@');
	}

	public static function looksLikePhone(string $value): bool
	{
		$digits = preg_replace('/\D/', '', $value) ?? '';

		return strlen($digits) >= 6 && preg_match('/^[\d+\s()\-]+$/u', trim($value)) === 1;
	}

	/** @return array<int, array{id: int, name: string, reversed: string, email: string}> */
	private function searchUsers(string $query): array
	{
		$batches = [];
		foreach (['FIND', 'NAME'] as $field)
		{
			[$ok, $result] = $this->restSafe('user.search', ['FILTER' => [$field => $query]]);
			if ($ok && is_array($result) && $result !== [])
			{
				$batches = $result;
				break;
			}
		}

		$users = [];
		foreach ($batches as $user)
		{
			$user = (array)$user;
			$id = (int)($user['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}
			$name = trim((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? ''));
			$reversed = trim((string)($user['LAST_NAME'] ?? '') . ' ' . (string)($user['NAME'] ?? ''));
			$users[$id] = [
				'id' => $id,
				'name' => $name,
				'reversed' => $reversed,
				'email' => (string)($user['EMAIL'] ?? ''),
			];
			$this->userNames[$id] = $name;
		}

		return array_values($users);
	}

	/** @return array<int, array{STATUS_ID: string, NAME: string, ENTITY_ID: string}> */
	private function loadStages(): array
	{
		if ($this->stages !== null)
		{
			return $this->stages;
		}

		$this->stages = [];
		[$ok, $result] = $this->restSafe('crm.status.list', []);
		if (!$ok)
		{
			return $this->stages;
		}

		foreach ((array)$result as $row)
		{
			$row = (array)$row;
			$statusId = (string)($row['STATUS_ID'] ?? '');
			if ($statusId === '')
			{
				continue;
			}
			$this->stages[] = [
				'STATUS_ID' => $statusId,
				'NAME' => (string)($row['NAME'] ?? ''),
				'ENTITY_ID' => (string)($row['ENTITY_ID'] ?? ''),
			];
		}

		return $this->stages;
	}

	/** @return array<int, array{STATUS_ID: string, NAME: string, ENTITY_ID: string}> */
	private function stagesFor(string $type, int $categoryId): array
	{
		$entityId = $this->stageEntityId($type, $categoryId);
		if ($entityId === '')
		{
			return [];
		}

		return array_values(array_filter(
			$this->loadStages(),
			static fn(array $stage): bool => $stage['ENTITY_ID'] === $entityId
		));
	}

	private function stageEntityId(string $type, int $categoryId): string
	{
		return match ($type)
		{
			'lead' => 'STATUS',
			'deal' => $categoryId > 0 ? 'DEAL_STAGE_' . $categoryId : 'DEAL_STAGE',
			default => '',
		};
	}
}
