<?php
/**
 * Инструмент crm_find_entities: поиск сущностей CRM по названию.
 */

return [
	'name' => 'crm_find_entities',
	'description' => 'Поиск сущностей CRM по названию. Используй перед созданием, '
		. 'чтобы не плодить дубли, и чтобы получить ID для обновления/задачи. '
		. 'Учитываются права доступа: чужие сущности не показываются.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'query' => ['type' => 'string', 'description' => 'строка поиска по названию'],
			'limit' => ['type' => 'integer', 'description' => 'максимум результатов, по умолчанию 5'],
		],
		'required' => ['entity_type', 'query'],
	],
];
