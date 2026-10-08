<?php
/**
 * Инструмент crm_create_entity: создание сущности CRM.
 */

return [
	'name' => 'crm_create_entity',
	'description' => 'Создание сущности CRM: лид, сделка, контакт или компания. '
		. 'Требует права добавления; выполняется от имени обратившегося сотрудника.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'title' => ['type' => 'string', 'description' => 'название: TITLE для лида/сделки/компании, NAME для контакта'],
			'amount' => ['type' => 'number', 'description' => 'сумма (OPPORTUNITY)'],
			'currency' => ['type' => 'string', 'description' => 'код валюты, по умолчанию RUB'],
			'comment' => ['type' => 'string', 'description' => 'комментарий/описание (COMMENTS)'],
			'contact_name' => ['type' => 'string', 'description' => 'имя контакта для привязки/создания'],
			'phone' => ['type' => 'string'],
			'email' => ['type' => 'string'],
		],
		'required' => ['entity_type', 'title'],
	],
];
