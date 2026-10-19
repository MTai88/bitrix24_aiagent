<?php

namespace Mtai\Aiagent\Tools\CrmAddComment;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$type = (string)($args['entity_type'] ?? '');
		if (!in_array($type, $ctx::entityTypes(), true))
		{
			return ['error' => "Unknown entity type: {$type}"];
		}

		$id = (int)($args['entity_id'] ?? 0);
		$comment = trim((string)($args['comment'] ?? ''));
		if ($id <= 0 || $comment === '')
		{
			return ['error' => 'entity_id and comment are required'];
		}

		if (!AccessGuard::crmCanUpdate($userId, $type, $id))
		{
			return ['error' => "Нет прав на запись комментария в {$type} #{$id}"];
		}

		$commentId = $ctx->restCall('crm.timeline.comment.add', [
			'fields' => [
				'ENTITY_TYPE' => $ctx::bindingPrefix($type),
				'ENTITY_ID' => $id,
				'COMMENT' => $comment,
				'AUTHOR_ID' => $userId,
			],
		]);

		return [
			'comment_id' => (int)$commentId,
			'entity_type' => $type,
			'entity_id' => $id,
			'url' => $ctx->crmUrl($type, $id),
		];
	}
}
