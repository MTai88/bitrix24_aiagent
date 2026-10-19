<?php
/**
 * Инструмент crm_activities_list: незавершённые дела CRM сотрудника.
 */

return [
	'name' => 'crm_activities_list',
	'description' => 'Незавершённые дела CRM текущего сотрудника: звонки, встречи, «связаться». '
		. 'Используй для «мои дела», «что просрочено в CRM», «дела на сегодня». '
		. 'Это не задачи (tasks_list) и не календарь.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'period' => ['type' => 'string', 'enum' => ['all', 'today', 'overdue'],
				'description' => 'all — все открытые (по умолчанию), today — срок сегодня, overdue — просроченные'],
			'limit' => ['type' => 'integer', 'description' => 'максимум, по умолчанию 10'],
		],
	],
];
