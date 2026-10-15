<?php

namespace Mtai\Aiagent\Chat;

use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Rest\Client;

/**
 * Индикаторы присутствия бота в диалоге (поверх imbot.v2).
 *
 * - Message.read — сообщение сотрудника сразу получает «прочитано»
 *   (read-отметка бота в личном диалоге);
 * - InputAction.notify — «AI-агент размышляет...» на время обработки
 *   ответа (пулл-эвент InputActionNotify/StartWriting). Пинги дольше
 *   REFRESH_INTERVAL сек игнорируются — индикатор обновляется между
 *   итерациями цикла инструментов и сам гаснет, если воркер умер.
 */
final class Indicator
{
	private const STATUS_THINKING = 'IMBOT_AGENT_ACTION_THINKING';

	/** Сколько секунд мессенджер держит индикатор без повторного пинга. */
	private const TYPING_DURATION = 15;
	/** Минимальный интервал между пингами (защита от спама pull-эвентами). */
	private const REFRESH_INTERVAL = 5;

	private Client $rest;
	private int $lastPingAt = 0;

	public function __construct(?Client $rest = null)
	{
		$this->rest = $rest ?? new Client();
	}

	/** «Прочитано» ботом. messageId = 0 — прочитать всё непрочитанное в чате. */
	public function markRead(int $botId, int $chatId, int $messageId = 0): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$params = ['botId' => $botId, 'chatId' => $chatId];
		if ($messageId > 0)
		{
			$params['messageId'] = $messageId;
		}

		[$ok, $error] = $this->rest->callSafe('imbot.v2.Chat.Message.read', $params);
		if (!$ok)
		{
			Logger::warn('chat', "Cannot mark message as read: " . (string)$error);
		}
	}

	/** «Бот размышает» — пинг с троттлингом REFRESH_INTERVAL. */
	public function showThinking(int $botId, int $chatId): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$now = time();
		if ($now - $this->lastPingAt < self::REFRESH_INTERVAL)
		{
			return;
		}
		$this->lastPingAt = $now;

		[$ok, $error] = $this->rest->callSafe('imbot.v2.Chat.InputAction.notify', [
			'botId' => $botId,
			'chatId' => $chatId,
			'statusMessageCode' => self::STATUS_THINKING,
			'duration' => self::TYPING_DURATION,
		]);
		if (!$ok)
		{
			Logger::warn('chat', "Cannot send typing indicator: " . (string)$error);
		}
	}
}
