<?php
/**
 * Инструмент cancel_scheduled_task: отмена отложенного задания.
 */

return [
	'name' => 'cancel_scheduled_task',
	'description' => 'Отменить отложенное задание сотрудника по ID (ID выясни через list_scheduled_tasks). '
		. 'Используй, когда просят «отмени напоминание/задание», «убери из расписания». '
		. 'Отменить можно только своё задание в статусе «ожидает».',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'task_id' => ['type' => 'integer', 'description' => 'ID задания из list_scheduled_tasks'],
		],
		'required' => ['task_id'],
	],
];
