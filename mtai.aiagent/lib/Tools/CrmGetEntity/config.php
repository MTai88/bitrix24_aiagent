<?php
/**
 * Инструмент crm_get_entity: карточка сущности CRM.
 */

return [
	'name' => 'crm_get_entity',
	'description' => 'Карточка сущности CRM по ID: стадия по названию, ответственный, телефон и email, '
		. 'связанные контакт и компания, последние комментарии таймлайна, открытые дела и ссылка. '
		. 'Используй, когда спрашивают «что по сделке», «расскажи про клиента», «открой карточку». '
		. 'ID бери из crm_find_entities. Если ID неизвестен — сначала найди.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
		],
		'required' => ['entity_type', 'entity_id'],
	],
];
