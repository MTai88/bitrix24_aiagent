<?php
/**
 * Инструмент crm_stats: сводка по количеству и сумме.
 */

return [
	'name' => 'crm_stats',
	'description' => 'Сколько сущностей и на какую сумму: группировка по стадиям. '
		. 'Используй для «сколько выиграли в октябре», «сумма открытых сделок», «воронка за период». '
		. 'Считает не больше 200 записей. Учитываются только карточки, доступные сотруднику на чтение.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal'], 'description' => 'по умолчанию deal'],
			'stage_semantic' => ['type' => 'string', 'enum' => ['open', 'won', 'lost', 'all'],
				'description' => 'по умолчанию all'],
			'created_from' => ['type' => 'string', 'description' => 'дата создания от, YYYY-MM-DD'],
			'created_to' => ['type' => 'string', 'description' => 'дата создания до, YYYY-MM-DD'],
			'responsible' => ['type' => 'string', 'description' => 'ответственный: имя, ID или «я»'],
			'category' => ['type' => 'string', 'description' => 'воронка сделок: ID или название'],
		],
	],
];
