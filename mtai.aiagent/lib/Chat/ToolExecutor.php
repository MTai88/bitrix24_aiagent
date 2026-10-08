<?php

namespace Mtai\Aiagent\Chat;

use Mtai\Aiagent\Rest\Client;
use Mtai\Aiagent\Tools\ToolRegistry;

/**
 * Исполнение инструментов AI-агента.
 *
 * Фасад над ToolRegistry: реализация каждого инструмента —
 * lib/tools/<имя>/Tool.php. Отказы прав и ошибки REST возвращаются
 * модели как ['error' => ...] — она сообщает сотруднику человеческим
 * языком; всё пишется в журнал (категории tools/access).
 */
final class ToolExecutor
{
	private Client $rest;

	public function __construct(?Client $rest = null)
	{
		$this->rest = $rest ?? new Client();
	}

	/**
	 * @param string $name имя инструмента
	 * @param array $arguments аргументы от LLM
	 * @return array результат для модели (data) либо ['error' => string]
	 */
	public function execute(string $name, array $arguments, int $requestUserId): array
	{
		return ToolRegistry::execute($name, $arguments, $requestUserId, $this->rest);
	}
}
