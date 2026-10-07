<?php

namespace Mtai\Aiagent\Chat;

use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Rest\Client;

/**
 * Исполнение инструментов CRM-агента: маппинг на REST-методы
 * crm.item.*, tasks.task.add, user.search. Каждый вызов
 * логируется. Ошибки REST возвращаются модели как текст —
 * она может поправить параметры и повторить.
 */
final class ToolExecutor
{
	private Client $rest;

	public function __construct(?Client $rest = null)
	{
		$this->rest = $rest ?? new Client();
	}

	/**
	 * @param string $name имя инструмента
	 * @param array $arguments аргументы от LLM
	 * @return array результат для модели (data) либо ['error' => string]
	 */
	public function execute(string $name, array $arguments, int $requestUserId): array
	{
		Logger::info('tools', "execute {$name}", $arguments);

		try
		{
			return match ($name)
			{
				'crm_find_entities' => $this->findEntities($arguments, $requestUserId),
				'crm_create_entity' => $this->createEntity($arguments, $requestUserId),
				'crm_update_entity' => $this->updateEntity($arguments, $requestUserId),
				'crm_create_task' => $this->createTask($arguments, $requestUserId),
				'find_user' => $this->findUser($arguments),
				'tasks_list' => $this->tasksList($arguments, $requestUserId),
				'task_complete' => $this->taskComplete($arguments, $requestUserId),
				'crm_add_comment' => $this->crmAddComment($arguments, $requestUserId),
				'kb_search' => $this->kbSearch($arguments),
				default => ['error' => "Unknown tool: {$name}"],
			};
		}
		catch (\Mtai\Aiagent\RestException $e)
		{
			Logger::error('tools', "tool {$name} failed: " . $e->getMessage());

			return ['error' => $e->getMessage()];
		}
	}

	private function findEntities(array $args, int $requestUserId): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$typeId = $this->typeId($type);
		$limit = max(1, min(10, (int)($args['limit'] ?? 5)));

		$result = $this->rest->call('crm.item.list', [
			'entityTypeId' => $typeId,
			'filter' => ['%title' => (string)($args['query'] ?? '')],
			'select' => ['id', 'title', 'opportunity', 'currencyId', 'assignedById'],
			'limit' => $limit,
		]);

		// Показываем только сущности, доступные сотруднику на чтение.
		$items = [];
		foreach ((array)($result['items'] ?? []) as $item) {
			$item = (array)$item;
			if (AccessGuard::crmCanRead($requestUserId, $type, (int)($item['id'] ?? 0))) {
				$items[] = $item;
			}
		}

		return ['items' => $items, 'total' => count($items)];
	}

	private function createEntity(array $args, int $requestUserId): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$typeId = $this->typeId($type);
		$title = trim((string)($args['title'] ?? ''));

		if (!AccessGuard::crmCanAdd($requestUserId, $type))
		{
			return ['error' => "У вас нет прав на создание сущностей типа {$type}"];
		}

		$fields = [
			'TITLE' => $title,
			'ASSIGNED_BY_ID' => $requestUserId,
		];

		// Контакт называется NAME, а не TITLE.
		if ($type === 'contact')
		{
			$nameParts = preg_split('/\s+/', $title) ?: [$title];
			$fields['NAME'] = $nameParts[0];
			$fields['SECOND_NAME'] = $nameParts[1] ?? '';
			$fields['LAST_NAME'] = $nameParts[2] ?? '';
			unset($fields['TITLE']);
		}

		if (!empty($args['amount']))
		{
			$fields['OPPORTUNITY'] = (float)$args['amount'];
			$fields['CURRENCY_ID'] = (string)($args['currency'] ?? 'RUB');
		}

		if (!empty($args['comment']))
		{
			$fields['COMMENTS'] = (string)$args['comment'];
		}

		foreach (['PHONE' => 'phone', 'EMAIL' => 'email'] as $field => $key)
		{
			if (!empty($args[$key]))
			{
				$fields[$field] = [['VALUE' => (string)$args[$key]]];
			}
		}

		// Привязка/создание контакта для лида/сделки.
		if (!empty($args['contact_name']) && in_array($type, ['lead', 'deal'], true))
		{
			$contactId = $this->findOrCreateContact((string)$args['contact_name'], $requestUserId);
			if ($contactId > 0)
			{
				$fields['CONTACT_ID'] = $contactId;
				$fields['CONTACT_IDS'] = [$contactId];
			}
		}

		$result = $this->rest->call('crm.item.add', [
			'entityTypeId' => $typeId,
			'fields' => $fields,
		]);

		return ['id' => (int)($result['item']['id'] ?? ($result ?? 0)), 'entity_type' => $type, 'title' => $title];
	}

	private function updateEntity(array $args, int $requestUserId): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$typeId = $this->typeId($type);
		$id = (int)($args['entity_id'] ?? 0);
		if ($id <= 0)
		{
			return ['error' => 'entity_id is required'];
		}

		if (!AccessGuard::crmCanUpdate($requestUserId, $type, $id))
		{
			return ['error' => "Нет прав на изменение {$type} #{$id}"];
		}

		$fields = [];
		if (!empty($args['title']))
		{
			$fields['TITLE'] = (string)$args['title'];
		}
		if (!empty($args['amount']))
		{
			$fields['OPPORTUNITY'] = (float)$args['amount'];
		}
		if (!empty($args['comment']))
		{
			$fields['COMMENTS'] = (string)$args['comment'];
		}

		if ($fields === [])
		{
			return ['error' => 'nothing to update'];
		}

		$this->rest->call('crm.item.update', [
			'entityTypeId' => $typeId,
			'id' => $id,
			'fields' => $fields,
		]);

		return ['updated' => true, 'entity_type' => $type, 'id' => $id];
	}

	private function createTask(array $args, int $requestUserId): array
	{
		$fields = [
			'TITLE' => (string)($args['title'] ?? ''),
			'CREATED_BY' => $requestUserId,
			'RESPONSIBLE_ID' => $requestUserId,
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
			$userId = $this->findUserByFullName((string)$args['responsible_name']);
			if ($userId > 0)
			{
				$fields['RESPONSIBLE_ID'] = $userId;
			}
		}

		$type = (string)($args['entity_type'] ?? '');
		$id = (int)($args['entity_id'] ?? 0);
		if ($type !== '' && $id > 0 && isset(Tools::BINDING_PREFIXES[$type]))
		{
			// Привязка выдаёт факт существования сущности — нужны права чтения.
			if (!AccessGuard::crmCanRead($requestUserId, $type, $id))
			{
				return ['error' => "Нет доступа к {$type} #{$id} — задача не создана с этой привязкой"];
			}

			$fields['UF_CRM_TASK'] = [Tools::BINDING_PREFIXES[$type] . '_' . $id];
		}

		$result = $this->rest->call('tasks.task.add', ['fields' => $fields]);

		return ['task_id' => (int)($result['task']['id'] ?? 0), 'title' => $fields['TITLE']];
	}

	private function findUser(array $args): array
	{
		$userId = $this->findUserByFullName((string)($args['full_name'] ?? ''));
		if ($userId <= 0)
		{
			return ['error' => 'user not found'];
		}

		$user = $this->rest->call('user.get', ['ID' => $userId]);
		$fields = (array)($user[0] ?? []);

		return [
			'id' => $userId,
			'name' => (string)($fields['NAME'] ?? '') . ' ' . (string)($fields['LAST_NAME'] ?? ''),
			'email' => (string)($fields['EMAIL'] ?? ''),
		];
	}

	/** Статусы задач Битрикса: 1-3 незавершённые, 5 завершённые. */
	private const TASK_STATUS_OPEN = [1, 2, 3];
	private const TASK_STATUS_COMPLETED = 5;

	private function tasksList(array $args, int $requestUserId): array
	{
		$status = (string)($args['status'] ?? 'open');
		$limit = max(1, min(50, (int)($args['limit'] ?? 10)));

		$filter = ['RESPONSIBLE_ID' => $requestUserId];
		if ($status === 'open')
		{
			$filter['@STATUS'] = self::TASK_STATUS_OPEN;
		}
		elseif ($status === 'completed')
		{
			$filter['=STATUS'] = self::TASK_STATUS_COMPLETED;
		}

		$result = $this->rest->call('tasks.task.list', [
			'filter' => $filter,
			'select' => ['ID', 'TITLE', 'DEADLINE', 'STATUS'],
			'order' => ['DEADLINE' => 'ASC'],
			'limit' => $limit,
		]);

		$tasks = [];
		foreach ((array)($result['tasks'] ?? []) as $task)
		{
			$task = (array)$task;
			$tasks[] = [
				'id' => (int)$task['id'],
				'title' => (string)$task['title'],
				'deadline' => (string)($task['deadline'] ?? ''),
			];
		}

		return ['tasks' => $tasks, 'total' => (int)($result['total'] ?? count($tasks))];
	}

	private function taskComplete(array $args, int $requestUserId): array
	{
		$taskId = (int)($args['task_id'] ?? 0);
		if ($taskId <= 0)
		{
			return ['error' => 'task_id is required'];
		}

		// Завершать можно только свои задачи (участник/создатель/наблюдатель).
		$taskResult = $this->rest->call('tasks.task.get', ['taskId' => $taskId, 'select' => ['ID', 'CREATED_BY', 'RESPONSIBLE_ID', 'ACCOMPLICES', 'AUDITORS']]);
		$taskFields = (array)($taskResult['task'] ?? []);
		if (!AccessGuard::taskAccessible($requestUserId, $taskFields))
		{
			return ['error' => "Задача #{$taskId} вам недоступна"];
		}

		$this->rest->call('tasks.task.complete', ['taskId' => $taskId]);

		return ['completed' => true, 'task_id' => $taskId];
	}

	private function crmAddComment(array $args, int $requestUserId): array
	{
		$type = (string)($args['entity_type'] ?? '');
		if (!isset(Tools::BINDING_PREFIXES[$type]))
		{
			return ['error' => "Unknown entity type: {$type}"];
		}

		$id = (int)($args['entity_id'] ?? 0);
		$comment = trim((string)($args['comment'] ?? ''));
		if ($id <= 0 || $comment === '')
		{
			return ['error' => 'entity_id and comment are required'];
		}

		if (!AccessGuard::crmCanUpdate($requestUserId, $type, $id))
		{
			return ['error' => "Нет прав на запись комментария в {$type} #{$id}"];
		}

		$commentId = $this->rest->call('crm.timeline.comment.add', [
			'fields' => [
				'ENTITY_TYPE' => Tools::BINDING_PREFIXES[$type],
				'ENTITY_ID' => $id,
				'COMMENT' => $comment,
			],
		]);

		return ['comment_id' => (int)$commentId, 'entity_type' => $type, 'entity_id' => $id];
	}

	private function kbSearch(array $args): array
	{
		$query = trim((string)($args['query'] ?? ''));
		if ($query === '')
		{
			return ['error' => 'query is required'];
		}

		$limit = max(1, min(10, (int)($args['limit'] ?? 5)));

		$scope = (string)($args['scope'] ?? 'all');
		$scopes = match ($scope)
		{
			'public' => ['public'],
			'internal' => ['internal'],
			default => null, // all — обе области
		};

		$found = (new \Mtai\Aiagent\Rag\VectorIndex())->search($query, $limit, null, $scopes);

		$chunks = [];
		foreach ($found as $item)
		{
			$chunks[] = [
				'content' => mb_substr((string)$item['content'], 0, 1200),
				'score' => $item['score'],
			];
		}

		return $chunks === []
			? ['found' => false, 'note' => 'в базе знаний ничего релевантного не найдено']
			: ['found' => true, 'chunks' => $chunks];
	}

	private function findOrCreateContact(string $name, int $requestUserId): int
	{
		$existing = $this->rest->call('crm.item.list', [
			'entityTypeId' => Tools::ENTITY_TYPE_IDS['contact'],
			'filter' => ['%name' => $name],
			'select' => ['id'],
			'limit' => 1,
		]);

		$found = (array)($existing['items'][0] ?? []);
		if (isset($found['id']))
		{
			return (int)$found['id'];
		}

		$result = $this->rest->call('crm.item.add', [
			'entityTypeId' => Tools::ENTITY_TYPE_IDS['contact'],
			'fields' => ['NAME' => $name, 'ASSIGNED_BY_ID' => $requestUserId],
		]);

		return (int)($result['item']['id'] ?? 0);
	}

	private function findUserByFullName(string $fullName): int
	{
		$result = $this->rest->call('user.search', ['FILTER' => ['NAME' => $fullName]]);

		$user = (array)($result[0] ?? []);

		return (int)($user['ID'] ?? 0);
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

	private function typeId(string $type): int
	{
		if (!isset(Tools::ENTITY_TYPE_IDS[$type]))
		{
			throw new \Mtai\Aiagent\RestException("Unknown entity type: {$type}", 'BAD_ENTITY_TYPE');
		}

		return Tools::ENTITY_TYPE_IDS[$type];
	}
}
