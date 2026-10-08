<?php

namespace Mtai\Aiagent\Tools\CrmCreateEntity;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$title = trim((string)($args['title'] ?? ''));

		if (!AccessGuard::crmCanAdd($userId, $type))
		{
			return ['error' => "У вас нет прав на создание сущностей типа {$type}"];
		}

		$fields = [
			'TITLE' => $title,
			'ASSIGNED_BY_ID' => $userId,
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
			$contactId = $this->findOrCreateContact((string)$args['contact_name'], $userId, $ctx);
			if ($contactId > 0)
			{
				$fields['CONTACT_ID'] = $contactId;
				$fields['CONTACT_IDS'] = [$contactId];
			}
		}

		$result = $ctx->restCall('crm.item.add', [
			'entityTypeId' => $ctx::entityTypeId($type),
			'fields' => $fields,
		]);

		return [
			'id' => (int)($result['item']['id'] ?? ($result ?? 0)),
			'entity_type' => $type,
			'title' => $title,
		];
	}

	private function findOrCreateContact(string $name, int $userId, Context $ctx): int
	{
		$existing = $ctx->restCall('crm.item.list', [
			'entityTypeId' => $ctx::entityTypeId('contact'),
			'filter' => ['%name' => $name],
			'select' => ['id'],
			'limit' => 1,
		]);

		$found = (array)($existing['items'][0] ?? []);
		if (isset($found['id']))
		{
			return (int)$found['id'];
		}

		$result = $ctx->restCall('crm.item.add', [
			'entityTypeId' => $ctx::entityTypeId('contact'),
			'fields' => ['NAME' => $name, 'ASSIGNED_BY_ID' => $userId],
		]);

		return (int)($result['item']['id'] ?? 0);
	}
}
