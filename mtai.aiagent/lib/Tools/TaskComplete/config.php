<?php
/**
 * Инструмент task_complete: завершить задачу по ID.
 */

return [
	'name' => 'task_complete',
	'description' => 'Завершить задачу по ID (ID виден в tasks_list). '
		. 'Доступно только участникам задачи (создатель/исполнитель/соисполнитель/наблюдатель).',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'task_id' => ['type' => 'integer'],
		],
		'required' => ['task_id'],
	],
];
