<?php
/**
 * Инструмент task_add_comment: комментарий к задаче.
 */

return [
	'name' => 'task_add_comment',
	'description' => 'Комментарий в существующую задачу (лента задачи, не таймлайн CRM). '
		. 'Используй, когда просят «напиши в задаче», «отметь в комментарии». '
		. 'task_id бери из tasks_list. Комментарий в сделку — crm_add_comment.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'task_id' => ['type' => 'integer'],
			'comment' => ['type' => 'string'],
		],
		'required' => ['task_id', 'comment'],
	],
];
