<?php

namespace Mtai\Aiagent\Tools\NotifyUser;

use Mtai\Aiagent\Tools\Context;

/**
 * Отправка системного уведомления в колокольчик через im.notify.system.add.
 *
 * Уведомление уходит от приложения (FROM_USER_ID = 0, тип SYSTEM), поэтому
 * под вебхук-авторизацией админские права не требуются. Получатель по
 * умолчанию — автор запроса: агент сам себе «в колокольчик» слать не должен.
 */
class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$message = trim((string)($args['message'] ?? ''));
		if ($message === '')
		{
			return ['error' => 'message is empty'];
		}

		[$recipientId, $error] = $this->resolveRecipient($args, $userId, $ctx);
		if ($error !== null)
		{
			return ['error' => $error];
		}

		$params = [
			'USER_ID' => $recipientId,
			'MESSAGE' => $message,
		];
		if (!empty($args['tag']))
		{
			$params['TAG'] = (string)$args['tag'];
		}

		[$ok, $result] = $ctx->restSafe('im.notify.system.add', $params);
		if (!$ok)
		{
			return ['error' => 'im.notify.system.add: ' . $result];
		}

		return [
			'ok' => true,
			'user_id' => $recipientId,
			'notification_id' => (int)$result,
		];
	}

	/** @return array{0: int, 1: ?string} [recipientId, error] */
	private function resolveRecipient(array $args, int $userId, Context $ctx): array
	{
		if (!empty($args['user_id']))
		{
			return [(int)$args['user_id'], null];
		}

		if (!empty($args['user_name']))
		{
			// LOGIN не входит в разрешённые поля user.search — только FIND
			// (полнотекст по имени, фамилии, логину и email).
			[$ok, $result] = $ctx->restSafe('user.search', [
				'FILTER' => ['FIND' => (string)$args['user_name']],
			]);
			if (!$ok)
			{
				return [0, 'user.search: ' . $result];
			}

			$found = (array)($result[0] ?? []);
			if (!isset($found['ID']))
			{
				return [0, 'user not found: ' . $args['user_name']];
			}

			return [(int)$found['ID'], null];
		}

		if ($userId <= 0)
		{
			return [0, 'no recipient: pass user_id or user_name'];
		}

		return [$userId, null];
	}
}
