<?php
/**
 * Инструмент calendar_event_create: новое событие в календаре текущего пользователя.
 */

return [
	'name' => 'calendar_event_create',
	'description' => 'Создать событие во «Моём календаре» текущего пользователя: встречу, '
		. 'напоминание, событие на весь день, с участниками. Используй, когда просят '
		. '«создай встречу/событие», «запланируй», «напомни мне». Дата и время — '
		. 'в часовом поясе портала (в системном промте есть текущие дата и время).',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'title' => ['type' => 'string', 'description' => 'название события'],
			'date_from' => ['type' => 'string', 'description' => 'начало: YYYY-MM-DD HH:MM, или YYYY-MM-DD — тогда событие на весь день'],
			'date_to' => ['type' => 'string', 'description' => 'окончание, по умолчанию +1 час от начала (на весь день — тот же день)'],
			'all_day' => ['type' => 'boolean', 'description' => 'событие на весь день'],
			'location' => ['type' => 'string', 'description' => 'место'],
			'description' => ['type' => 'string', 'description' => 'описание'],
			'attendees' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'имена участников (как в find_user)'],
		],
		'required' => ['title', 'date_from'],
	],
];
