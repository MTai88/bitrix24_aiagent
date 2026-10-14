<?php
/**
 * Инструмент calendar_event_delete: удаление события из календаря текущего пользователя.
 */

return [
	'name' => 'calendar_event_delete',
	'description' => 'Удалить событие из «Моего календаря» текущего пользователя. '
		. 'event_id брать только из результата calendar_events_list — это не ID сделки '
		. 'и не ID задачи. Используй, когда просят «удали событие/встречу», «убери из '
		. 'календаря». Удалить можно только собственное событие своего календаря.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'event_id' => ['type' => 'integer'],
		],
		'required' => ['event_id'],
	],
];
