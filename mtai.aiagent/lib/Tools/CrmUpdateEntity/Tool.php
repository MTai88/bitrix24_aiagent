<?php

namespace Mtai\Aiagent\Tools\CrmUpdateEntity;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$id = (int)($args['entity_id'] ?? 0);
		if ($id <= 0)
		{
			return ['error' => 'entity_id is required'];
		}

		if (!AccessGuard::crmCanUpdate($userId, $type, $id))
		{
			return ['error' => "Нет прав на изменение {$type} #{$id}"];
		}

		$fields = [];
		if (!empty($args['title']))
		{
			$fields[$type === 'contact' ? 'NAME' : 'TITLE'] = (string)$args['title'];
		}
		if (array_key_exists('amount', $args) && $args['amount'] !== '' && $args['amount'] !== null)
		{
			$fields['OPPORTUNITY'] = (float)$args['amount'];
		}
		if (!empty($args['comment']))
		{
			$fields['COMMENTS'] = (string)$args['comment'];
		}
		if (!empty($args['close_date']) && $type === 'deal')
		{
			$fields['CLOSEDATE'] = Context::normalizeDeadline((string)$args['close_date']);
		}

		$categoryId = 0;
		if ($type === 'deal' && isset($args['category']) && trim((string)$args['category']) !== '')
		{
			$resolvedCategory = $ctx->resolveCategoryId((string)$args['category']);
			if ($resolvedCategory === null)
			{
				return ['error' => 'Воронка «' . $args['category'] . '» не найдена'];
			}
			$categoryId = $resolvedCategory;
			$fields['CATEGORY_ID'] = $categoryId;
		}

		if (!empty($args['stage']) && in_array($type, ['lead', 'deal'], true))
		{
			if ($categoryId === 0 && $type === 'deal')
			{
				$current = $ctx->restCall('crm.item.get', [
					'entityTypeId' => $ctx::entityTypeId($type),
					'id' => $id,
				]);
				$categoryId = (int)(((array)($current['item'] ?? []))['categoryId'] ?? 0);
			}
			$stageId = $ctx->resolveStage($type, $categoryId, (string)$args['stage']);
			if ($stageId === null)
			{
				return ['error' => 'Стадия «' . $args['stage'] . '» не найдена'];
			}
			$fields['STAGE_ID'] = $stageId;
		}

		if (!empty($args['responsible']))
		{
			$resolved = $ctx->resolveUser((string)$args['responsible']);
			if (!isset($resolved['id']))
			{
				return $resolved;
			}
			$fields['ASSIGNED_BY_ID'] = (int)$resolved['id'];
		}

		if (!empty($args['contact']) && $type !== 'contact')
		{
			$contactId = $ctx->findContactId((string)$args['contact']);
			if ($contactId <= 0)
			{
				return ['error' => 'Контакт «' . $args['contact'] . '» не найден'];
			}
			$fields['CONTACT_ID'] = $contactId;
			$fields['CONTACT_IDS'] = [$contactId];
		}

		if (!empty($args['company']) && $type !== 'company')
		{
			$companyId = $ctx->findCompanyId((string)$args['company']);
			if ($companyId <= 0)
			{
				return ['error' => 'Компания «' . $args['company'] . '» не найдена'];
			}
			$fields['COMPANY_ID'] = $companyId;
		}

		$fm = $ctx->fmFrom($args);
		if ($fm !== [])
		{
			$fields['fm'] = $fm;
		}

		if ($fields === [])
		{
			return ['error' => 'nothing to update'];
		}

		$ctx->restCall('crm.item.update', [
			'entityTypeId' => $ctx::entityTypeId($type),
			'id' => $id,
			'fields' => $fields,
		]);

		return [
			'updated' => true,
			'entity_type' => $type,
			'id' => $id,
			'url' => $ctx->crmUrl($type, $id),
		];
	}
}
