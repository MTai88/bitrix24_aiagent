<?php
/**
 * Инструмент crm_activity_add: дело в карточке CRM (универсальное дело).
 */

return [
	'name' => 'crm_activity_add',
	'description' => 'Запланировать дело в карточке CRM: звонок, письмо, встречу с клиентом, «связаться». '
		. 'Используй, когда просят «запланируй звонок клиенту», «поставь дело на сделку», «напомни связаться». '
		. 'Это не задача сотруднику (для неё crm_create_task) и не событие календаря без CRM. '
		. 'entity_id бери из crm_find_entities.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'entity_id' => ['type' => 'integer'],
			'title' => ['type' => 'string', 'description' => 'суть дела, например «Позвонить по счёту»'],
			'deadline' => ['type' => 'string', 'description' => 'когда: YYYY-MM-DD HH:MM или YYYY-MM-DD'],
			'description' => ['type' => 'string'],
			'responsible' => ['type' => 'string', 'description' => 'исполнитель: имя или ID, по умолчанию автор запроса'],
		],
		'required' => ['entity_type', 'entity_id', 'title', 'deadline'],
	],
];
