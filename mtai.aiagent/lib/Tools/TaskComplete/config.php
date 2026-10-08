<?php
/**
 * Инструмент task_complete: завершить задачу по ID.
 */

return [
	'name' => 'task_complete',
	'description' => 'Завершить СУЩЕСТВУЮЩУЮ задачу по её ID — ID брать только из результата tasks_list, '
		. 'это не ID сделки/контакта и не для создания задач (для этого crm_create_task). '
		. 'Доступно только участникам задачи (создатель/исполнитель/соисполнитель/наблюдатель).',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'task_id' => ['type' => 'integer'],
		],
		'required' => ['task_id'],
	],
];
