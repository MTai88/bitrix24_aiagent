<?php

namespace Mtai\Aiagent\Tools\CrmGetEntity;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$id = (int)($args['entity_id'] ?? 0);
		if ($id <= 0 || !in_array($type, Context::entityTypes(), true))
		{
			return ['error' => 'entity_type and entity_id are required'];
		}
		if (!AccessGuard::crmCanRead($userId, $type, $id))
		{
			return ['error' => "Нет доступа к {$type} #{$id}"];
		}

		$result = $ctx->restCall('crm.item.get', [
			'entityTypeId' => Context::entityTypeId($type),
			'id' => $id,
		]);
		$item = (array)($result['item'] ?? []);
		if ($item === [])
		{
			return ['error' => "{$type} #{$id} не найден"];
		}

		$card = $ctx->presentCrmItem($item, $type);
		$card['contacts'] = $this->relatedContacts($item, $ctx);
		if (!empty($item['companyId']) && $type !== 'company')
		{
			$card['company'] = $this->related((int)$item['companyId'], 'company', $ctx);
		}
		$card['comments'] = $this->comments($type, $id, $ctx);
		$card['activities'] = $this->activities($type, $id, $ctx);

		return $card;
	}

	/** @return array[] */
	private function relatedContacts(array $item, Context $ctx): array
	{
		$ids = [];
		foreach ((array)($item['contactIds'] ?? []) as $contactId)
		{
			$contactId = (int)$contactId;
			if ($contactId > 0)
			{
				$ids[$contactId] = $contactId;
			}
		}
		if (!empty($item['contactId']))
		{
			$ids[(int)$item['contactId']] = (int)$item['contactId'];
		}

		$contacts = [];
		foreach (array_slice(array_values($ids), 0, 3) as $contactId)
		{
			$related = $this->related($contactId, 'contact', $ctx);
			if ($related !== null)
			{
				$contacts[] = $related;
			}
		}

		return $contacts;
	}

	private function related(int $id, string $type, Context $ctx): ?array
	{
		[$ok, $result] = $ctx->restSafe('crm.item.get', [
			'entityTypeId' => Context::entityTypeId($type),
			'id' => $id,
		]);
		if (!$ok)
		{
			return null;
		}
		$item = (array)($result['item'] ?? []);
		if ($item === [])
		{
			return null;
		}

		return $ctx->presentCrmItem($item, $type);
	}

	/** @return array[] */
	private function comments(string $type, int $id, Context $ctx): array
	{
		[$ok, $result] = $ctx->restSafe('crm.timeline.comment.list', [
			'filter' => [
				'ENTITY_ID' => $id,
				'ENTITY_TYPE' => $type,
			],
			'order' => ['ID' => 'DESC'],
		]);
		if (!$ok)
		{
			return [];
		}

		$comments = [];
		foreach (array_slice((array)$result, 0, 5) as $row)
		{
			$row = (array)$row;
			$comments[] = [
				'id' => (int)($row['ID'] ?? 0),
				'created' => (string)($row['CREATED'] ?? ''),
				'author_id' => (int)($row['AUTHOR_ID'] ?? 0),
				'text' => mb_substr(trim((string)($row['COMMENT'] ?? '')), 0, 500),
			];
		}

		return $comments;
	}

	/** @return array[] */
	private function activities(string $type, int $id, Context $ctx): array
	{
		[$ok, $result] = $ctx->restSafe('crm.activity.list', [
			'filter' => [
				'OWNER_TYPE_ID' => Context::entityTypeId($type),
				'OWNER_ID' => $id,
				'COMPLETED' => 'N',
			],
			'select' => ['ID', 'SUBJECT', 'DEADLINE', 'RESPONSIBLE_ID', 'PROVIDER_ID'],
			'order' => ['DEADLINE' => 'ASC'],
		]);
		if (!$ok)
		{
			return [];
		}

		$activities = [];
		$rows = isset($result['ID']) || isset($result['id']) ? [$result] : (array)$result;
		foreach (array_slice($rows, 0, 5) as $row)
		{
			if (!is_array($row) || (!isset($row['ID']) && !isset($row['id'])))
			{
				continue;
			}
			$row = (array)$row;
			$activities[] = [
				'id' => (int)($row['ID'] ?? 0),
				'title' => (string)($row['SUBJECT'] ?? ''),
				'deadline' => (string)($row['DEADLINE'] ?? ''),
				'responsible_id' => (int)($row['RESPONSIBLE_ID'] ?? 0),
			];
		}

		return $activities;
	}
}
