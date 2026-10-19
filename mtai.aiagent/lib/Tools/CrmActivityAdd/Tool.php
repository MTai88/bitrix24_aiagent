<?php

namespace Mtai\Aiagent\Tools\CrmActivityAdd;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? '');
		$id = (int)($args['entity_id'] ?? 0);
		$title = trim((string)($args['title'] ?? ''));
		$deadline = trim((string)($args['deadline'] ?? ''));
		if ($id <= 0 || $title === '' || $deadline === '' || !in_array($type, Context::entityTypes(), true))
		{
			return ['error' => 'entity_type, entity_id, title and deadline are required'];
		}
		if (!AccessGuard::crmCanUpdate($userId, $type, $id))
		{
			return ['error' => "Нет прав запланировать дело в {$type} #{$id}"];
		}

		$responsibleId = $userId;
		if (!empty($args['responsible']))
		{
			$resolved = $ctx->resolveUser((string)$args['responsible']);
			if (!isset($resolved['id']))
			{
				return $resolved;
			}
			$responsibleId = (int)$resolved['id'];
		}

		$deadline = Context::normalizeDeadline($deadline);
		$params = [
			'ownerTypeId' => Context::entityTypeId($type),
			'ownerId' => $id,
			'deadline' => date('c', strtotime($deadline) ?: time()),
			'title' => $title,
			'description' => (string)($args['description'] ?? ''),
			'responsibleId' => $responsibleId,
		];

		[$ok, $result] = $ctx->restSafe('crm.activity.todo.add', $params);
		if (!$ok)
		{
			return ['error' => 'crm.activity.todo.add: ' . $result];
		}

		$activityId = (int)(is_array($result) ? ($result['id'] ?? 0) : $result);

		return [
			'activity_id' => $activityId,
			'entity_type' => $type,
			'entity_id' => $id,
			'title' => $title,
			'deadline' => $deadline,
			'url' => $ctx->crmUrl($type, $id),
		];
	}
}
