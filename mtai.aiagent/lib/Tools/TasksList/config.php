<?php
/**
 * Инструмент tasks_list: список задач текущего пользователя.
 */

return [
	'name' => 'tasks_list',
	'description' => 'Список задач текущего пользователя (по умолчанию — незавершённые, '
		. 'с дедлайнами). Используй, когда просят «мои задачи», «что на сегодня».',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'status' => ['type' => 'string', 'enum' => ['open', 'completed', 'all'], 'description' => 'по умолчанию open'],
			'limit' => ['type' => 'integer', 'description' => 'максимум задач, по умолчанию 10'],
		],
	],
];
