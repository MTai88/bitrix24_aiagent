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

		$ctx->restCall('crm.item.update', [
			'entityTypeId' => $ctx::entityTypeId($type),
			'id' => $id,
			'fields' => $fields,
		]);

		return ['updated' => true, 'entity_type' => $type, 'id' => $id];
	}
}
