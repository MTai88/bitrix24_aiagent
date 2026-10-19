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
		if ($title === '' && $type !== 'contact')
		{
			return ['error' => 'title is required'];
		}

		if (!AccessGuard::crmCanAdd($userId, $type))
		{
			return ['error' => "У вас нет прав на создание сущностей типа {$type}"];
		}

		$assigned = $userId;
		if (!empty($args['responsible']))
		{
			$resolved = $ctx->resolveUser((string)$args['responsible']);
			if (!isset($resolved['id']))
			{
				return $resolved;
			}
			$assigned = (int)$resolved['id'];
		}

		$fields = ['ASSIGNED_BY_ID' => $assigned];

		if ($type === 'contact')
		{
			$fields += $ctx->personFields($title, $args);
		}
		else
		{
			$fields['TITLE'] = $title;
		}

		if (array_key_exists('amount', $args) && $args['amount'] !== '' && $args['amount'] !== null)
		{
			$fields['OPPORTUNITY'] = (float)$args['amount'];
			$fields['CURRENCY_ID'] = (string)($args['currency'] ?? 'RUB');
		}

		if (!empty($args['comment']))
		{
			$fields['COMMENTS'] = (string)$args['comment'];
		}
		if (!empty($args['source_id']))
		{
			$fields['SOURCE_ID'] = (string)$args['source_id'];
		}

		$fm = $ctx->fmFrom($args);
		if ($fm !== [])
		{
			$fields['fm'] = $fm;
		}

		if (!empty($args['contact_name']) && in_array($type, ['lead', 'deal'], true))
		{
			$contactId = $ctx->findOrCreateContact((string)$args['contact_name'], $assigned);
			if ($contactId > 0)
			{
				$fields['CONTACT_ID'] = $contactId;
				$fields['CONTACT_IDS'] = [$contactId];
			}
		}

		if (!empty($args['company']) && $type !== 'company')
		{
			$companyId = $ctx->findOrCreateCompany((string)$args['company'], $assigned);
			if ($companyId > 0)
			{
				$fields['COMPANY_ID'] = $companyId;
			}
		}

		$categoryId = 0;
		if ($type === 'deal' && isset($args['category']) && trim((string)$args['category']) !== '')
		{
			$categoryId = $ctx->resolveCategoryId((string)$args['category']);
			if ($categoryId === null)
			{
				return ['error' => 'Воронка «' . $args['category'] . '» не найдена'];
			}
			$fields['CATEGORY_ID'] = $categoryId;
		}

		if (!empty($args['stage']) && in_array($type, ['lead', 'deal'], true))
		{
			$stageId = $ctx->resolveStage($type, $categoryId, (string)$args['stage']);
			if ($stageId === null)
			{
				return ['error' => 'Стадия «' . $args['stage'] . '» не найдена'];
			}
			$fields['STAGE_ID'] = $stageId;
		}

		$result = $ctx->restCall('crm.item.add', [
			'entityTypeId' => $ctx::entityTypeId($type),
			'fields' => $fields,
		]);

		$id = (int)($result['item']['id'] ?? 0);
		$item = is_array($result['item'] ?? null) ? (array)$result['item'] : ['id' => $id, 'title' => $title];
		$presented = $ctx->presentCrmItem($item + ['title' => $item['title'] ?? $title], $type);

		return $presented + ['created' => true];
	}
}
