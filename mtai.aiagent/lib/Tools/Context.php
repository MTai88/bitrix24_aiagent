<?php

namespace Mtai\Aiagent\Tools;

/**
 * Контекст исполнения инструмента: REST-клиент портала и общие константы
 * (типы CRM, префиксы привязок), шина прав.
 */
final class Context
{
	public function __construct(
		public readonly ?\Mtai\Aiagent\Rest\Client $rest = null,
	) {
	}

	public function rest(): \Mtai\Aiagent\Rest\Client
	{
		return $this->rest ?? new \Mtai\Aiagent\Rest\Client();
	}

	/** Выполнить REST-метод портала от имени модуля. */
	public function restCall(string $method, array $params = [])
	{
		return $this->rest()->call($method, $params);
	}

	/** REST-вызов без исключений: [ok, result|error]. */
	public function restSafe(string $method, array $params = []): array
	{
		try
		{
			return [true, $this->rest()->call($method, $params)];
		}
		catch (\Mtai\Aiagent\RestException $e)
		{
			return [false, $e->getMessage()];
		}
	}

	/** entityTypeId CRM: lead=1, deal=2, contact=3, company=4. */
	public static function entityTypeId(string $type): int
	{
		return match ($type)
		{
			'lead' => 1,
			'deal' => 2,
			'contact' => 3,
			'company' => 4,
			default => throw new \InvalidArgumentException("Unknown entity type: {$type}"),
		};
	}

	/** Префикс привязки CRM (UF_CRM_TASK, ENTITY_TYPE таймлайна): L/D/C/CO. */
	public static function bindingPrefix(string $type): string
	{
		return match ($type)
		{
			'lead' => 'L',
			'deal' => 'D',
			'contact' => 'C',
			'company' => 'CO',
			default => throw new \InvalidArgumentException("Unknown entity type: {$type}"),
		};
	}

	public static function entityTypes(): array
	{
		return ['lead', 'deal', 'contact', 'company'];
	}
}
