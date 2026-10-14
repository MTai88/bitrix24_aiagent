<?php

namespace Mtai\Aiagent\Tools\CalendarEventsList;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$from = $this->normalizeDate((string)($args['from'] ?? ''), date('Y-m-d'));
		$to = $this->normalizeDate((string)($args['to'] ?? ''), date('Y-m-d', strtotime('+7 days')));

		if ($from > $to)
		{
			return ['error' => "Некорректный период: начало {$from} позже окончания {$to}"];
		}

		$result = $ctx->restCall('calendar.event.get', [
			'type' => 'user',
			'ownerId' => $userId,
			'from' => $from . ' 00:00:00',
			'to' => $to . ' 23:59:59',
		]);

		$attendeeIds = [];
		$events = [];
		foreach ((array)$result as $event)
		{
			$event = (array)$event;

			$attendees = [];
			foreach ((array)($event['ATTENDEE_LIST'] ?? []) as $attendee)
			{
				$attendee = (array)$attendee;
				$id = (int)($attendee['id'] ?? 0);
				if ($id <= 0)
				{
					continue;
				}
				$attendees[$id] = (string)($attendee['status'] ?? '');
				$attendeeIds[$id] = true;
			}

			$events[] = [
				'id' => (int)$event['ID'],
				'title' => (string)$event['NAME'],
				'date_from' => (string)$event['DATE_FROM'],
				'date_to' => (string)$event['DATE_TO'],
				'all_day' => (string)($event['DT_SKIP_TIME'] ?? '') === 'Y',
				'location' => (string)($event['LOCATION'] ?? ''),
				'is_meeting' => !empty($event['IS_MEETING']),
				'attendees' => $attendees,
			];
		}

		usort($events, static fn(array $a, array $b): int => strcmp((string)$a['date_from'], (string)$b['date_from']));

		// ATTENDEE_LIST отдаёт только id/status — имена подтягиваем отдельно.
		$names = $this->resolveNames(array_keys($attendeeIds), $ctx);
		foreach ($events as &$event)
		{
			$resolved = [];
			foreach ((array)$event['attendees'] as $id => $status)
			{
				$resolved[] = $names[$id] ?? ("user #{$id}");
			}
			$event['attendees'] = $resolved;
		}
		unset($event);

		return ['events' => $events, 'total' => count($events)];
	}

	private function normalizeDate(string $value, string $default): string
	{
		$value = trim($value);
		if ($value === '')
		{
			return $default;
		}

		$time = strtotime($value);

		return $time ? date('Y-m-d', $time) : $default;
	}

	/** Имена участников по списку ID (user.get, FILTER по ID). */
	private function resolveNames(array $ids, Context $ctx): array
	{
		if (!$ids)
		{
			return [];
		}

		$result = $ctx->restCall('user.get', ['FILTER' => ['ID' => $ids]]);

		$names = [];
		foreach ((array)$result as $user)
		{
			$user = (array)$user;
			$id = (int)($user['ID'] ?? 0);
			if ($id > 0)
			{
				$names[$id] = trim((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? ''));
			}
		}

		return $names;
	}
}
