<?php

namespace Mtai\Aiagent\Chat;

use Mtai\Aiagent\Tools\ToolRegistry;

/**
 * Описания инструментов AI-агента (для function calling / JSON-протокола).
 *
 * Фасад над ToolRegistry: инструменты живут в lib/tools/<имя>/ —
 * config.php (описание для LLM) + Tool.php (класс с execute()) —
 * и подхватываются автоматически. Новые инструменты добавляются
 * своей папкой, без правки этого файла.
 */
final class Tools
{
	/** @deprecated используйте \Mtai\Aiagent\Tools\Context::entityTypes() */
	public const ENTITY_TYPES = ['lead', 'deal', 'contact', 'company'];

	/** @deprecated используйте \Mtai\Aiagent\Tools\Context::entityTypeId() */
	public const ENTITY_TYPE_IDS = ['lead' => 1, 'deal' => 2, 'contact' => 3, 'company' => 4];

	/** @deprecated используйте \Mtai\Aiagent\Tools\Context::bindingPrefix() */
	public const BINDING_PREFIXES = ['lead' => 'L', 'deal' => 'D', 'contact' => 'C', 'company' => 'CO'];

	/** @return array[] описания инструментов (OpenAI tools) */
	public static function definitions(): array
	{
		return ToolRegistry::definitions();
	}

	/** Схемы в текстовом виде для JSON-протокола. */
	public static function forPrompt(): string
	{
		return ToolRegistry::forPrompt();
	}
}
