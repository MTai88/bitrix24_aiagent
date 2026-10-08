<?php
/**
 * Инструмент crm_create_task: задача с привязкой к сущности CRM.
 */

return [
	'name' => 'crm_create_task',
	'description' => 'Задача с привязкой к сущности CRM (UF_CRM_TASK). '
		. 'entity_type/entity_id можно не указывать — задача без привязки. '
		. 'Для привязки нужно право чтения сущности.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'title' => ['type' => 'string'],
			'deadline' => ['type' => 'string', 'description' => 'срок, формат YYYY-MM-DD или YYYY-MM-DD HH:MM'],
			'description' => ['type' => 'string'],
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
			'responsible_name' => ['type' => 'string', 'description' => 'исполнитель по имени (по умолчанию автор запроса)'],
		],
		'required' => ['title'],
	],
];
