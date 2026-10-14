<?php

namespace Mtai\Aiagent\Tools\CalendarEventDelete;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$eventId = (int)($args['event_id'] ?? 0);
		if ($eventId <= 0)
		{
			return ['error' => 'event_id is required'];
		}

		// REST идёт от админского вебхука — без проверки агент удалил бы
		// чужое событие: пускаем только собственные события своего календаря.
		$event = (array)$ctx->restCall('calendar.event.getbyid', ['id' => $eventId]);
		if ((int)($event['ID'] ?? 0) !== $eventId)
		{
			return ['error' => "Событие #{$eventId} не найдено"];
		}
		if ((string)($event['CAL_TYPE'] ?? '') !== 'user' || (int)($event['OWNER_ID'] ?? 0) !== $userId)
		{
			return ['error' => "Событие #{$eventId} вам недоступно — удалять можно только собственные события своего календаря"];
		}

		$ctx->restCall('calendar.event.delete', ['id' => $eventId]);

		return ['deleted' => true, 'event_id' => $eventId];
	}
}
