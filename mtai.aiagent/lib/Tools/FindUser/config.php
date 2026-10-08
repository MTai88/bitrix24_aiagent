<?php
/**
 * Инструмент find_user: поиск сотрудника портала по имени.
 */

return [
	'name' => 'find_user',
	'description' => 'Поиск сотрудника портала по имени (для ответственных в задачах).',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'full_name' => ['type' => 'string'],
		],
		'required' => ['full_name'],
	],
];
