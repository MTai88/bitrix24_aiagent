<?php
/**
 * Инструмент crm_update_entity: изменение сущности CRM по ID.
 */

return [
	'name' => 'crm_update_entity',
	'description' => 'Изменение сущности CRM по ID (название, сумма, комментарий и т.п.). '
		. 'Требует права изменения: чужие сущности недоступны.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
			'title' => ['type' => 'string'],
			'amount' => ['type' => 'number'],
			'comment' => ['type' => 'string'],
		],
		'required' => ['entity_type', 'entity_id'],
	],
];
