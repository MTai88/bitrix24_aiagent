<?php

namespace Mtai\Aiagent\Chat;

/**
 * Описания инструментов CRM-агента (для function calling).
 *
 * Формат — JSON Schema в стиле OpenAI tools; для провайдеров без
 * нативного function calling те же описания сериализуются в
 * системный промпт JSON-протокола (см. ChatHandler).
 */
final class Tools
{
	public const ENTITY_TYPES = ['lead', 'deal', 'contact', 'company'];

	/** entityTypeId для crm.item.*: лид 1, сделка 2, контакт 3, компания 4. */
	public const ENTITY_TYPE_IDS = ['lead' => 1, 'deal' => 2, 'contact' => 3, 'company' => 4];

	/** Префиксы привязки CRM для UF_CRM_TASK. */
	public const BINDING_PREFIXES = ['lead' => 'L', 'deal' => 'D', 'contact' => 'C', 'company' => 'CO'];

	/** @return array[] описания инструментов (OpenAI tools) */
	public static function definitions(): array
	{
		$entityEnum = (object)['type' => 'string', 'enum' => self::ENTITY_TYPES];

		return [
			[
				'type' => 'function',
				'function' => [
					'name' => 'crm_find_entities',
					'description' => 'Поиск сущностей CRM по названию. Используй перед созданием, '
						. 'чтобы не плодить дубли, и чтобы получить ID для обновления/задачи.',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'entity_type' => $entityEnum,
							'query' => (object)['type' => 'string', 'description' => 'строка поиска по названию'],
							'limit' => (object)['type' => 'integer', 'description' => 'максимум результатов, по умолчанию 5'],
						],
						'required' => ['entity_type', 'query'],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'crm_create_entity',
					'description' => 'Создание сущности CRM: лид, сделка, контакт или компания.',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'entity_type' => $entityEnum,
							'title' => (object)['type' => 'string', 'description' => 'название: TITLE для лида/сделки/компании, NAME для контакта'],
							'amount' => (object)['type' => 'number', 'description' => 'сумма (OPPORTUNITY)'],
							'currency' => (object)['type' => 'string', 'description' => 'код валюты, по умолчанию RUB'],
							'comment' => (object)['type' => 'string', 'description' => 'комментарий/описание (COMMENTS)'],
							'contact_name' => (object)['type' => 'string', 'description' => 'имя контакта для привязки/создания'],
							'phone' => (object)['type' => 'string'],
							'email' => (object)['type' => 'string'],
						],
						'required' => ['entity_type', 'title'],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'crm_update_entity',
					'description' => 'Изменение сущности CRM по ID (название, сумма, комментарий и т.п.).',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'entity_type' => $entityEnum,
							'entity_id' => (object)['type' => 'integer'],
							'title' => (object)['type' => 'string'],
							'amount' => (object)['type' => 'number'],
							'comment' => (object)['type' => 'string'],
						],
						'required' => ['entity_type', 'entity_id'],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'crm_create_task',
					'description' => 'Задача с привязкой к сущности CRM (UF_CRM_TASK). '
						. 'entity_type/entity_id можно не указывать — задача без привязки.',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'title' => (object)['type' => 'string'],
							'deadline' => (object)['type' => 'string', 'description' => 'срок, формат YYYY-MM-DD или YYYY-MM-DD HH:MM'],
							'description' => (object)['type' => 'string'],
							'entity_type' => $entityEnum,
							'entity_id' => (object)['type' => 'integer'],
							'responsible_name' => (object)['type' => 'string', 'description' => 'исполнитель по имени (по умолчанию автор запроса)'],
						],
						'required' => ['title'],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'find_user',
					'description' => 'Поиск сотрудника портала по имени (для ответственных в задачах).',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'full_name' => (object)['type' => 'string'],
						],
						'required' => ['full_name'],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'tasks_list',
					'description' => 'Список задач текущего пользователя (по умолчанию — незавершённые, '
						. 'с дедлайнами). Используй, когда просят «мои задачи», «что на сегодня».',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'status' => (object)['type' => 'string', 'enum' => ['open', 'completed', 'all']],
							'limit' => (object)['type' => 'integer', 'description' => 'максимум задач, по умолчанию 10'],
						],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'task_complete',
					'description' => 'Завершить задачу по ID (ID виден в tasks_list).',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'task_id' => (object)['type' => 'integer'],
						],
						'required' => ['task_id'],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'crm_add_comment',
					'description' => 'Комментарий в карточку сущности CRM (таймлайн): «клиент обещал оплату», '
						. '«передал ТЗ» и т.п. Не путай с полем COMMENTS — это запись в историю общения.',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'entity_type' => $entityEnum,
							'entity_id' => (object)['type' => 'integer'],
							'comment' => (object)['type' => 'string'],
						],
						'required' => ['entity_type', 'entity_id', 'comment'],
					],
				],
			],
			[
				'type' => 'function',
				'function' => [
					'name' => 'kb_search',
					'description' => 'Поиск по базе знаний компании: инструкции, регламенты, FAQ, условия. '
						. 'Включает внутренние документы сотрудников (scope=internal) и публичные для клиентов.',
					'parameters' => (object)[
						'type' => 'object',
						'properties' => (object)[
							'query' => (object)['type' => 'string'],
							'limit' => (object)['type' => 'integer', 'description' => 'максимум фрагментов, по умолчанию 5'],
							'scope' => (object)['type' => 'string', 'enum' => ['all', 'public', 'internal'],
								'description' => 'область базы: all (по умолчанию), public — только клиентская, internal — только внутренняя'],
						],
						'required' => ['query'],
					],
				],
			],
		];
	}

	/** Схемы в текстовом виде для JSON-протокола. */
	public static function forPrompt(): string
	{
		$list = [];
		foreach (self::definitions() as $tool)
		{
			$function = $tool['function'];
			$parameters = [];
			foreach ((array)($function['parameters']->properties ?? []) as $name => $schema)
			{
				$schema = (array)$schema;
				$parameters[] = $name . ' (' . ($schema['type'] ?? 'string') . ')'
					. (isset($schema['enum']) ? ': ' . implode('|', (array)$schema['enum']) : '');
			}

			$list[] = '- ' . $function['name'] . ': ' . $function['description'] . "\n"
				. '  параметры: ' . ($parameters === [] ? 'нет' : implode('; ', $parameters));
		}

		return implode("\n", $list);
	}
}
