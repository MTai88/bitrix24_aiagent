<?php
/**
 * Инструмент task_update: изменить задачу или сменить её состояние.
 */

return [
	'name' => 'task_update',
	'description' => 'Изменить существующую задачу: срок («перенеси на пятницу»), исполнителя, '
		. 'соисполнителей, наблюдателей, приоритет, заголовок, описание. '
		. 'action: update (по умолчанию), start — взять в работу, defer — отложить, renew — возобновить. '
		. 'Завершение — только task_complete. ID бери из tasks_list или task_get, это не ID сделки.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'task_id' => ['type' => 'integer'],
			'action' => ['type' => 'string', 'enum' => ['update', 'start', 'defer', 'renew'],
				'description' => 'по умолчанию update'],
			'title' => ['type' => 'string'],
			'description' => ['type' => 'string'],
			'deadline' => ['type' => 'string', 'description' => 'новый срок YYYY-MM-DD или YYYY-MM-DD HH:MM'],
			'responsible' => ['type' => 'string', 'description' => 'новый исполнитель: имя или ID'],
			'accomplices' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'заменить список соисполнителей'],
			'auditors' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'заменить список наблюдателей'],
			'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
		],
		'required' => ['task_id'],
	],
];
