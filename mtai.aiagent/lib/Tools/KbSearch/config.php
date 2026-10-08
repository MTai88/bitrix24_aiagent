<?php
/**
 * Инструмент kb_search: поиск по базе знаний компании.
 */

return [
	'name' => 'kb_search',
	'description' => 'Поиск по базе знаний компании: инструкции, регламенты, FAQ, условия. '
		. 'Включает внутренние документы сотрудников (scope=internal) и публичные для клиентов.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'query' => ['type' => 'string'],
			'limit' => ['type' => 'integer', 'description' => 'максимум фрагментов, по умолчанию 5'],
			'scope' => ['type' => 'string', 'enum' => ['all', 'public', 'internal'],
				'description' => 'область базы: all (по умолчанию), public — только клиентская, internal — только внутренняя'],
		],
		'required' => ['query'],
	],
];
