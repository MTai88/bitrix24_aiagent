<?php
/**
 * Инструмент crm_update_entity: изменение сущности CRM по ID.
 */

return [
	'name' => 'crm_update_entity',
	'description' => 'Изменение сущности CRM по ID: название, сумма (можно 0), стадия по названию '
		. '(«переведи на этап Счёт выставлен»), ответственный, привязка контакта/компании, '
		. 'телефон, email, дата закрытия, воронка сделки. '
		. 'ID бери из crm_find_entities или crm_get_entity. Чужие сущности недоступны.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
			'title' => ['type' => 'string'],
			'amount' => ['type' => 'number', 'description' => 'сумма, 0 допустим'],
			'comment' => ['type' => 'string', 'description' => 'поле COMMENTS, не запись таймлайна'],
			'stage' => ['type' => 'string', 'description' => 'стадия: status_id или название'],
			'responsible' => ['type' => 'string', 'description' => 'новый ответственный: имя или ID'],
			'contact' => ['type' => 'string', 'description' => 'привязать контакт: ID или имя'],
			'company' => ['type' => 'string', 'description' => 'привязать компанию: ID или название'],
			'phone' => ['type' => 'string', 'description' => 'добавить телефон'],
			'email' => ['type' => 'string', 'description' => 'добавить email'],
			'close_date' => ['type' => 'string', 'description' => 'дата закрытия сделки YYYY-MM-DD'],
			'category' => ['type' => 'string', 'description' => 'воронка сделки: ID или название'],
		],
		'required' => ['entity_type', 'entity_id'],
	],
];
