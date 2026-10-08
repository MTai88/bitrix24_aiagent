<?php
/**
 * Инструмент crm_create_task: задача с привязкой к сущности CRM.
 */

return [
	'name' => 'crm_create_task',
	'description' => 'Создать новую задачу: заголовок, срок, описание, исполнитель. '
		. 'Используй, когда просят «поставить задачу», «создать задачу», «назначить задачу», «напомнить что-то». '
		. 'Опционально привязывается к сущности CRM (сделка/контакт/компания/лид) через entity_type/entity_id — '
		. 'ID возьми из результата crm_find_entities, а не придумывай. '
		. 'Для привязки нужно право чтения сущности; без неё задача создастся без привязки.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'title' => ['type' => 'string', 'description' => 'заголовок задачи'],
			'deadline' => ['type' => 'string', 'description' => 'срок, формат YYYY-MM-DD или YYYY-MM-DD HH:MM'],
			'description' => ['type' => 'string'],
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
			'responsible_name' => ['type' => 'string', 'description' => 'исполнитель по имени (по умолчанию автор запроса)'],
		],
		'required' => ['title'],
	],
];
