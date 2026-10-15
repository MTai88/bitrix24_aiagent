<?php

namespace Mtai\Aiagent\Event;

use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Bot\Manager;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Orm\JobTable;
use Mtai\Aiagent\Rest\Client;
use Mtai\Aiagent\RestException;

/**
 * Получение событий бота из очереди imbot.v2.Event.get (eventMode=fetch).
 *
 * Один тик забирает до MAX_BATCHES страниц по PAGE_SIZE событий,
 * раскладывает их в таблицу заданий и сохраняет nextOffset —
 * это курсор очереди на стороне Битрикс24.
 */
final class Fetcher
{
	private const PAGE_SIZE = 50;
	private const MAX_BATCHES = 6;

	/** Тик агента: забрать события в очередь. Возвращает число созданных заданий. */
	public function tick(): int
	{
		if (!Client::isConfigured())
		{
			return 0;
		}

		$botId = Manager::botUserId();
		if ($botId <= 0)
		{
			return 0;
		}

		$client = new Client();
		$created = 0;
		$offset = Config::getInt('event_offset');

		for ($i = 0; $i < self::MAX_BATCHES; $i++)
		{
			try
			{
				$response = (array)$client->call('imbot.v2.Event.get', [
					'botId' => $botId,
					'offset' => $offset,
					'limit' => self::PAGE_SIZE,
				]);
			}
			catch (RestException $e)
			{
				Logger::error('fetch', 'Event.get failed: ' . $e->getMessage());

				return $created;
			}

			$events = (array)($response['events'] ?? []);
			foreach ($events as $event)
			{
				$created += $this->enqueueEvent((array)$event);
			}

			$offset = (int)($response['nextOffset'] ?? $offset);
			Config::set('event_offset', (string)$offset);

			$hasMore = !empty($response['hasMore']);
			if (!$hasMore)
			{
				break;
			}
		}

		if ($created > 0 || $offset > 0)
		{
			Config::set('last_fetch_time', date('d.m.Y H:i:s'));
		}

		return $created;
	}

	/** Преобразование события в задание очереди. */
	private function enqueueEvent(array $event): int
	{
		$type = (string)($event['type'] ?? '');
		$eventId = (int)($event['eventId'] ?? 0);
		$data = (array)($event['data'] ?? []);

		switch ($type)
		{
			case 'ONIMBOTV2MESSAGEADD':
				$jobType = 'message';
				break;
			case 'ONIMBOTV2COMMANDADD':
				$jobType = 'command';
				break;
			case 'ONIMBOTV2JOINCHAT':
				// Событие приветствия не обрабатываем: бот отвечает только
				// на сообщения (спека не требует welcome-message).
				Logger::debug('fetch', "Event {$type} skipped (join chat)");

				return 0;
			case 'ONIMBOTV2DELETE':
				Logger::warn('fetch', 'Bot was deleted on portal side');
				Config::set('bot_user_id', '0');

				return 0;
			default:
				Logger::debug('fetch', "Event {$type} skipped");

				return 0;
		}

		// Защита от дублей: уникальный ключ задания содержит eventId.
		$exists = JobTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=TYPE' => $jobType,
				'%PAYLOAD' => '"eventId":' . $eventId . ',',
			],
			'limit' => 1,
		])->fetch();

		if ($exists)
		{
			return 0;
		}

		JobTable::add([
			'TYPE' => $jobType,
			'PAYLOAD' => Json::encode($event, JSON_UNESCAPED_UNICODE),
			'STATUS' => JobTable::STATUS_NEW,
		]);

		// Реакция бота — сразу при приёме события, не дожидаясь тика
		// очереди: «прочитано» под сообщением и «размышает...» в диалоге.
		// Для команд не поднимаем (обрабатываются мгновенно, без LLM).
		if ($jobType === 'message' && \Mtai\Aiagent\Handler\ChatHandler::acceptMessage($data))
		{
			\Mtai\Aiagent\Handler\ChatHandler::reactToIncoming($data);
		}

		return 1;
	}
}
