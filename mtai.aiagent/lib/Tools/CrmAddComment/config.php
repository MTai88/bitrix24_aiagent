<?php
/**
 * Инструмент crm_add_comment: комментарий в таймлайн карточки CRM.
 */

return [
	'name' => 'crm_add_comment',
	'description' => 'Комментарий в карточку сущности CRM (таймлайн): «клиент обещал оплату», '
		. '«передал ТЗ» и т.п. Не путай с полем COMMENTS — это запись в историю общения. '
		. 'Требует права записи.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
			'comment' => ['type' => 'string'],
		],
		'required' => ['entity_type', 'entity_id', 'comment'],
	],
];
