<?php
/**
 * Инструмент crm_create_task: создание задачи, опционально с привязкой к CRM.
 */

return [
	'name' => 'crm_create_task',
	'description' => 'Создать новую задачу сотруднику: заголовок, срок, описание, исполнитель, '
		. 'соисполнители, наблюдатели, приоритет, проект, чек-лист. '
		. 'Используй, когда просят «поставить задачу», «создать задачу», «назначить задачу». '
		. 'Это не дело в карточке CRM (для звонка/встречи с клиентом — crm_activity_add) '
		. 'и не завершение задачи (task_complete). '
		. 'Опционально привязывается к CRM: entity_type/entity_id и дополнительные bindings («deal:12»). '
		. 'ID бери из crm_find_entities, не придумывай. Если исполнитель не найден или их несколько — задача не создаётся.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'title' => ['type' => 'string', 'description' => 'заголовок задачи'],
			'deadline' => ['type' => 'string', 'description' => 'срок, YYYY-MM-DD или YYYY-MM-DD HH:MM'],
			'description' => ['type' => 'string'],
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
			'bindings' => ['type' => 'array', 'items' => ['type' => 'string'],
				'description' => 'дополнительные привязки «deal:12», «contact:5»'],
			'responsible_id' => ['type' => 'integer', 'description' => 'ID исполнителя'],
			'responsible_name' => ['type' => 'string', 'description' => 'исполнитель по имени, если ID неизвестен'],
			'accomplices' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'соисполнители: имена или ID'],
			'auditors' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'наблюдатели: имена или ID'],
			'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high'], 'description' => 'по умолчанию normal'],
			'group' => ['type' => 'string', 'description' => 'проект: ID или название'],
			'checklist' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'пункты чек-листа'],
		],
		'required' => ['title'],
	],
];
