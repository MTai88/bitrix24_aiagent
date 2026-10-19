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

		$recipient = $this->resolveRecipient($args, $userId, $ctx);
		if (isset($recipient['error']))
		{
			return $recipient;
		}
		$recipientId = (int)$recipient['id'];

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

	/** @return array{id?: int, error?: string, candidates?: array} */
	private function resolveRecipient(array $args, int $userId, Context $ctx): array
	{
		if (!empty($args['user_id']))
		{
			return ['id' => (int)$args['user_id']];
		}

		if (!empty($args['user_name']))
		{
			return $ctx->resolveUser((string)$args['user_name']);
		}

		if ($userId <= 0)
		{
			return ['error' => 'no recipient: pass user_id or user_name'];
		}

		return ['id' => $userId];
	}
}
