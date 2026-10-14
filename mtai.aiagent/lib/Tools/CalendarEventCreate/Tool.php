<?php

namespace Mtai\Aiagent\Tools\CalendarEventCreate;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$title = trim((string)($args['title'] ?? ''));
		if ($title === '')
		{
			return ['error' => 'title is required'];
		}

		$dateFrom = trim((string)($args['date_from'] ?? ''));
		if ($dateFrom === '')
		{
			return ['error' => 'date_from is required'];
		}

		// Дата без времени = событие на весь день.
		$allDay = !empty($args['all_day']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1;
		$from = $allDay ? $this->dateOnly($dateFrom) : $this->dateTime($dateFrom);

		$dateTo = trim((string)($args['date_to'] ?? ''));
		$to = $dateTo !== ''
			? ($allDay ? $this->dateOnly($dateTo) : $this->dateTime($dateTo))
			: ($allDay ? $from : date('Y-m-d H:i:s', strtotime($from . ' +1 hour')));

		if ($to < $from)
		{
			return ['error' => "Окончание события ({$to}) раньше начала ({$from})"];
		}

		$fields = [
			'type' => 'user',
			'ownerId' => $userId,
			'name' => $title,
			'from' => $from,
			'to' => $to,
		];

		if ($allDay)
		{
			$fields['skip_time'] = 'Y';
		}
		if (!empty($args['location']))
		{
			$fields['location'] = (string)$args['location'];
		}
		if (!empty($args['description']))
		{
			$fields['description'] = (string)$args['description'];
		}

		// Секция: контроллер умеет подобрать её сам, но надёжнее передать существующую.
		$sectionId = $this->firstSectionId($userId, $ctx);
		if ($sectionId > 0)
		{
			$fields['section'] = $sectionId;
		}

		$unknown = [];
		$attendeeIds = [];
		foreach ((array)($args['attendees'] ?? []) as $name)
		{
			$id = $this->findUserByFullName(trim((string)$name), $ctx);
			if ($id > 0)
			{
				$attendeeIds[$id] = true;
			}
			else
			{
				$unknown[] = (string)$name;
			}
		}

		if ($attendeeIds)
		{
			$fields['is_meeting'] = 'Y';
			$fields['attendees'] = array_keys($attendeeIds);
			$fields['meeting'] = ['notify' => true];
		}

		$result = $ctx->restCall('calendar.event.add', $fields);

		$reply = [
			'event_id' => (int)$result,
			'title' => $title,
			'date_from' => $from,
			'date_to' => $to,
			'all_day' => $allDay,
		];
		if ($unknown)
		{
			$reply['attendees_not_found'] = $unknown;
		}

		return $reply;
	}

	private function dateOnly(string $value): string
	{
		$time = strtotime($value);

		return $time ? date('Y-m-d', $time) : $value;
	}

	private function dateTime(string $value): string
	{
		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
		{
			return $value . ' 00:00:00';
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value))
		{
			return $value . ':00';
		}

		return $value;
	}

	private function firstSectionId(int $userId, Context $ctx): int
	{
		$result = $ctx->restCall('calendar.section.get', ['type' => 'user', 'ownerId' => $userId]);
		foreach ((array)$result as $section)
		{
			$section = (array)$section;
			if ((int)($section['ID'] ?? 0) > 0)
			{
				return (int)$section['ID'];
			}
		}

		return 0;
	}

	private function findUserByFullName(string $fullName, Context $ctx): int
	{
		$result = $ctx->restCall('user.search', ['FILTER' => ['NAME' => $fullName]]);
		$user = (array)($result[0] ?? []);

		return (int)($user['ID'] ?? 0);
	}
}
