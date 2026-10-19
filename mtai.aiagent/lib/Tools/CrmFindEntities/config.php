<?php
/**
 * Инструмент crm_find_entities: расширенный поиск сущностей CRM.
 */

return [
	'name' => 'crm_find_entities',
	'description' => 'Поиск сущностей CRM (лид/сделка/контакт/компания) по комбинации критериев: '
		. 'название, фамилия контакта, телефон, email, стадия сделки (stage_id или название), '
		. 'семантика стадии (open/won/lost), сумма (мин/макс), ответственный (имя или ID), '
		. 'дата создания и дата закрытия (YYYY-MM-DD), воронка, связанная компания/контакт, '
		. 'сделки без активности N дней (inactive_days). Критерии объединяются по И. '
		. 'Используй перед созданием, чтобы не плодить дубли, и когда спрашивают «найди», «мои сделки», «что по клиенту». '
		. 'Если результатов несколько — перечисли и уточни. Учитываются права: чужие сущности не показываются.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'query' => ['type' => 'string', 'description' => 'строка поиска: название, ФИО контакта или фамилия'],
			'phone' => ['type' => 'string', 'description' => 'телефон'],
			'email' => ['type' => 'string', 'description' => 'email'],
			'stage_id' => ['type' => 'string', 'description' => 'стадия: status_id (NEW, WON, LOSE, C2:NEW) или название. Лиды и сделки'],
			'stage_semantic' => ['type' => 'string', 'enum' => ['open', 'won', 'lost'],
				'description' => 'семантика стадии: open — незавершённые, won — успешные, lost — проваленные'],
			'amount_min' => ['type' => 'number', 'description' => 'минимальная сумма (OPPORTUNITY)'],
			'amount_max' => ['type' => 'number', 'description' => 'максимальная сумма'],
			'responsible' => ['type' => 'string', 'description' => 'ответственный: имя («Иван Петров») или ID'],
			'created_from' => ['type' => 'string', 'description' => 'дата создания от, YYYY-MM-DD'],
			'created_to' => ['type' => 'string', 'description' => 'дата создания до, YYYY-MM-DD'],
			'close_from' => ['type' => 'string', 'description' => 'дата закрытия от, YYYY-MM-DD. Только сделки'],
			'close_to' => ['type' => 'string', 'description' => 'дата закрытия до, YYYY-MM-DD. Только сделки'],
			'inactive_days' => ['type' => 'integer', 'description' => 'без активности столько дней и больше. Только сделки'],
			'category_id' => ['type' => 'integer', 'description' => 'ID воронки сделок (0 — общая)'],
			'category_name' => ['type' => 'string', 'description' => 'название воронки, например «Общая»; приоритетнее category_id'],
			'company' => ['type' => 'string', 'description' => 'связанная компания: ID или название'],
			'contact' => ['type' => 'string', 'description' => 'связанный контакт: ID, ФИО, телефон или email'],
			'limit' => ['type' => 'integer', 'description' => 'максимум результатов, по умолчанию 5'],
		],
		'required' => ['entity_type'],
	],
];
