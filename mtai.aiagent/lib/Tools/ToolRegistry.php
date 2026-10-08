<?php

namespace Mtai\Aiagent\Tools;

use Mtai\Aiagent\Logger;

/**
 * Реестр инструментов AI-агента.
 *
 * Каждый инструмент живёт в своей папке lib/tools/<name>/ и содержит:
 *   - config.php — описание для LLM (name, description, parameters в JSON
 *     Schema, https://platform.openai.com/docs/guides/function-calling);
 *     возвращаемый массив = ['name' => ..., 'description' => ...,
 *     'parameters' => [...schema-объекты...]]);
 *   - Tool.php — класс реализации <PascalName>Tool с методом
 *     execute(array $arguments, int $userId, Context $ctx): array.
 *
 * Новая папка с этими двумя файлами подхватывается автоматически
 * при следующем запросе к Tools::definitions()/execute() — ничего
 * регистрировать не нужно. Имя тулса = snake_case имени папки
 * (CrmFindEntities → crm_find_entities).
 */
final class ToolRegistry
{
	/** @var array<string, array>|null [toolName => ['dir' => ..., 'class' => ..., 'config' => ...]] */
	private static ?array $registry = null;

	/**
	 * Все зарегистрированные инструменты в формате OpenAI tools.
	 * @return array[] [['type' => 'function', 'function' => [...]], ...]
	 */
	public static function definitions(): array
	{
		$result = [];
		foreach (self::load() as $name => $meta)
		{
			$cfg = $meta['config'];
			$result[] = [
				'type' => 'function',
				'function' => [
					'name' => $cfg['name'] ?? $name,
					'description' => (string)($cfg['description'] ?? ''),
					'parameters' => (object)($cfg['parameters'] ?? (object)[
						'type' => 'object',
						'properties' => (object)[],
					]),
				],
			];
		}

		return $result;
	}

	/** Текстовое описание всех инструментов для JSON-протокола. */
	public static function forPrompt(): string
	{
		$list = [];
		foreach (self::definitions() as $tool)
		{
			$function = $tool['function'];
			$parameters = [];
			foreach ((array)($function['parameters']->properties ?? []) as $param => $schema)
			{
				$schema = (array)$schema;
				$parameters[] = $param . ' (' . ($schema['type'] ?? 'string') . ')'
					. (isset($schema['enum']) ? ': ' . implode('|', (array)$schema['enum']) : '');
			}

			$list[] = '- ' . $function['name'] . ': ' . $function['description'] . "\n"
				. '  параметры: ' . ($parameters === [] ? 'нет' : implode('; ', $parameters));
		}

		return implode("\n", $list);
	}

	/**
	 * Выполнить инструмент.
	 * @return array результат для модели (data) либо ['error' => string]
	 */
	public static function execute(string $name, array $arguments, int $userId, ?\Mtai\Aiagent\Rest\Client $rest = null): array
	{
		$meta = self::load()[$name] ?? null;
		if ($meta === null)
		{
			return ['error' => "Unknown tool: {$name}"];
		}

		$class = $meta['class'];
		if (!class_exists($class))
		{
			require_once $meta['dir'] . '/Tool.php';
		}

		Logger::info('tools', "execute {$name}", $arguments);

		try
		{
			$tool = new $class();

			return $tool->execute($arguments, $userId, new Context($rest));
		}
		catch (\Mtai\Aiagent\RestException $e)
		{
			Logger::error('tools', "tool {$name} failed: " . $e->getMessage());

			return ['error' => $e->getMessage()];
		}
		catch (\Throwable $e)
		{
			Logger::error('tools', "tool {$name} crashed: " . $e->getMessage());

			return ['error' => 'Внутренняя ошибка инструмента ' . $name . ': ' . $e->getMessage()];
		}
	}

	/** Имена всех инструментов. */
	public static function names(): array
	{
		return array_keys(self::load());
	}

	/**
	 * Сканирование lib/tools/<name>/ с кешем на процесс. Папка валидна,
	 * если содержит Tool.php; config.php обязателен (без него тулс
	 * невидим для LLM — ошибка пишется в журнал).
	 */
	private static function load(): array
	{
		if (self::$registry !== null)
		{
			return self::$registry;
		}

		self::$registry = [];
		$base = __DIR__;
		$handle = is_dir($base) ? opendir($base) : false;
		if (!$handle)
		{
			return self::$registry;
		}

		while (($entry = readdir($handle)) !== false)
		{
			if ($entry === '.' || $entry === '..' || !is_dir($base . '/' . $entry))
			{
				continue;
			}

			$dir = $base . '/' . $entry;
			$configFile = $dir . '/config.php';
			$classFile = $dir . '/Tool.php';

			if (!is_file($classFile) || !is_file($configFile))
			{
				continue;
			}

			$config = include $configFile;
			if (!is_array($config) || empty($config['name']) || empty($config['description']))
			{
				Logger::warn('tools', "tool dir '{$entry}': config.php должен возвращать массив с name и description — пропущен");

				continue;
			}

			// CrmFindEntities → Mtai\Aiagent\Tools\CrmFindEntities\Tool
			$class = __NAMESPACE__ . '\\' . $entry . '\\Tool';

			self::$registry[$config['name']] = [
				'dir' => $dir,
				'class' => $class,
				'config' => $config,
			];
		}
		closedir($handle);

		ksort(self::$registry);

		return self::$registry;
	}
}
