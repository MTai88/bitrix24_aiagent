<?php
/**
 * Инструмент crm_create_entity: создание сущности CRM.
 */

return [
	'name' => 'crm_create_entity',
	'description' => 'Создание сущности CRM: лид, сделка, контакт или компания. '
		. 'Перед созданием проверь дубль через crm_find_entities (в том числе по телефону и email). '
		. 'Для контакта два слова в title — имя и фамилия, три — фамилия, имя и отчество; '
		. 'явные name/last_name надёжнее. Телефон и email сохраняются. '
		. 'Требует права добавления; выполняется от имени обратившегося сотрудника.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'title' => ['type' => 'string', 'description' => 'название: для лида/сделки/компании — TITLE, для контакта — ФИО'],
			'name' => ['type' => 'string', 'description' => 'имя контакта, если не хочешь разбирать title'],
			'last_name' => ['type' => 'string', 'description' => 'фамилия контакта'],
			'second_name' => ['type' => 'string', 'description' => 'отчество контакта'],
			'amount' => ['type' => 'number', 'description' => 'сумма (OPPORTUNITY)'],
			'currency' => ['type' => 'string', 'description' => 'код валюты, по умолчанию RUB'],
			'comment' => ['type' => 'string', 'description' => 'комментарий/описание (COMMENTS)'],
			'contact_name' => ['type' => 'string', 'description' => 'контакт для привязки к лиду/сделке: найдётся или создастся'],
			'company' => ['type' => 'string', 'description' => 'компания для привязки: ID или название (найдётся или создастся)'],
			'phone' => ['type' => 'string'],
			'email' => ['type' => 'string'],
			'responsible' => ['type' => 'string', 'description' => 'ответственный: имя или ID, по умолчанию автор запроса'],
			'category' => ['type' => 'string', 'description' => 'воронка сделки: ID или название, 0/«Общая» — общая'],
			'stage' => ['type' => 'string', 'description' => 'стадия лида/сделки: status_id или название («Счёт выставлен»)'],
			'source_id' => ['type' => 'string', 'description' => 'источник, например CALL, WEB'],
		],
		'required' => ['entity_type', 'title'],
	],
];
