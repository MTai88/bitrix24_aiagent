<?php
/**
 * Инструмент crm_find_entities: расширенный поиск сущностей CRM.
 *
 * Поддерживает: название, стадию сделки, сумму (диапазон), ответственного,
 * дату создания (диапазон), воронку (категорию) сделки, связанные
 * компанию/контакт (по ID или названию). Критерии комбинируются (И).
 */

return [
	'name' => 'crm_find_entities',
	'description' => 'Поиск сущностей CRM (лид/сделка/контакт/компания) по комбинации критериев: '
		. 'название, стадия сделки (stage_id, например NEW/EXECUTING/WON/LOSE), сумма (мин/макс), '
		. 'ответственный (имя или ID), дата создания (от/до, формат YYYY-MM-DD), '
		. 'воронка сделок (category_id или название, 0/«Общая» — общая воронка), '
		. 'связанная компания/контакт (ID или название). '
		. 'Критерии объединяются по И. Используй перед созданием, чтобы не плодить дубли. '
		. 'Учитываются права: чужие сущности не показываются.',
	'parameters' => [
		'type' => 'object',
		'properties' => [
			'entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'company']],
			'query' => ['type' => 'string', 'description' => 'строка поиска по названию'],
			'stage_id' => ['type' => 'string', 'description' => 'стадия сделки (status_id): NEW, PREPARATION, PREPAYMENT_INVOICE, EXECUTING, FINAL_INVOICE, WON (успешна), LOSE (провалена). Только для сделок'],
			'stage_semantic' => ['type' => 'string', 'enum' => ['open', 'won', 'lost'],
				'description' => 'семантика стадии сделки: open — незавершённые, won — успешные, lost — проваленные. Удобнее точного stage_id'],
			'amount_min' => ['type' => 'number', 'description' => 'минимальная сумма (OPPORTUNITY)'],
			'amount_max' => ['type' => 'number', 'description' => 'максимальная сумма'],
			'responsible' => ['type' => 'string', 'description' => 'ответственный: имя («Иван Петров») или ID'],
			'created_from' => ['type' => 'string', 'description' => 'дата создания от, YYYY-MM-DD'],
			'created_to' => ['type' => 'string', 'description' => 'дата создания до, YYYY-MM-DD'],
			'category_id' => ['type' => 'integer', 'description' => 'ID воронки сделок (0 — общая)'],
			'category_name' => ['type' => 'string', 'description' => 'название воронки, например «Общая»; приоритетнее category_id'],
			'company' => ['type' => 'string', 'description' => 'связанная компания: ID или название'],
			'contact' => ['type' => 'string', 'description' => 'связанный контакт: ID или название'],
			'limit' => ['type' => 'integer', 'description' => 'максимум результатов, по умолчанию 5'],
		],
		'required' => ['entity_type'],
	],
];
