<?php
/**
 * Инструмент tasks_list: список задач.
 */

return [
	'name' => 'tasks_list',
	'description' => 'Список задач: мои (исполнитель), поставленные мной, где я соисполнитель или наблюдатель. '
		. 'Фильтры: открытые/завершённые, просроченные, срок на период («на сегодня», «на неделю»), '
		. 'поиск по названию, задачи по сделке/контакту. '
		. 'Используй для «мои задачи», «что на сегодня», «просроченные», «задачи по сделке». '
		. 'Задачи со сроком идут первыми. ID из ответа — единственный источник для task_complete и task_update.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'status' => ['type' => 'string', 'enum' => ['open', 'completed', 'all'], 'description' => 'по умолчанию open (включая «ждёт контроля» и «отложена»)'],
			'role' => ['type' => 'string', 'enum' => ['responsible', 'created', 'accomplice', 'auditor', 'all'],
				'description' => 'роль текущего пользователя, по умолчанию responsible'],
			'overdue' => ['type' => 'boolean', 'description' => 'только просроченные'],
			'deadline_from' => ['type' => 'string', 'description' => 'срок от, YYYY-MM-DD'],
			'deadline_to' => ['type' => 'string', 'description' => 'срок до, YYYY-MM-DD'],
			'query' => ['type' => 'string', 'description' => 'часть названия'],
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company'], 'description' => 'задачи, привязанные к CRM'],
			'entity_id' => ['type' => 'integer'],
			'limit' => ['type' => 'integer', 'description' => 'максимум задач, по умолчанию 10'],
		],
	],
];
