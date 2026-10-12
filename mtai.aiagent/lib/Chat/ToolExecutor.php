<?php

namespace Mtai\Aiagent\Chat;

use Mtai\Aiagent\Mcp\Manager;
use Mtai\Aiagent\Rest\Client;
use Mtai\Aiagent\Tools\ToolRegistry;

/**
 * Исполнение инструментов AI-агента.
 *
 * Фасад над ToolRegistry: реализация каждого инструмента —
 * lib/tools/<имя>/Tool.php. Отказы прав и ошибки REST возвращаются
 * модели как ['error' => ...] — она сообщает сотруднику человеческим
 * языком; всё пишется в журнал (категории tools/access).
 *
 * Инструменты MCP (Manager) проверяются первыми: их имена имеют
 * префикс mcp_ и с локальными не пересекаются, но явный приоритет
 * делает разрешение предсказуемым.
 */
final class ToolExecutor
{
	private Client $rest;
	private ?Manager $mcp;

	public function __construct(?Client $rest = null, ?Manager $mcp = null)
	{
		$this->rest = $rest ?? new Client();
		$this->mcp = $mcp;
	}

	/**
	 * @param string $name имя инструмента
	 * @param array $arguments аргументы от LLM
	 * @return array результат для модели (data) либо ['error' => string]
	 */
	public function execute(string $name, array $arguments, int $requestUserId): array
	{
		if ($this->mcp !== null && $this->mcp->has($name))
		{
			return $this->mcp->execute($name, $arguments);
		}

		return ToolRegistry::execute($name, $arguments, $requestUserId, $this->rest);
	}
}
