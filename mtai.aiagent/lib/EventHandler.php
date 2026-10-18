<?php

namespace Mtai\Aiagent;

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;

/** Регистрация обработчиков событий и агентов модуля. */
final class EventHandler
{
	/** Агенты модуля: имя => интервал, сек. Единый источник для install и CLI worker.php agents. */
	private const AGENTS = [
		'\\Mtai\\Aiagent\\Agent::fetchEvents();' => 60,
		'\\Mtai\\Aiagent\\Agent::processQueue();' => 60,
		'\\Mtai\\Aiagent\\Agent::runSchedule();' => 60,
		'\\Mtai\\Aiagent\\Agent::cleanLog();' => 86400,
	];

	public static function register(): void
	{
		$manager = EventManager::getInstance();
		$module = Config::MODULE_ID;
		$class = static::class;

		// Пункты админ-меню: база знаний и журнал.
		$manager->registerEventHandlerCompatible(
			'main', 'OnBuildGlobalMenu', $module, $class, 'onBuildGlobalMenu'
		);

		foreach (self::AGENTS as $name => $interval)
		{
			self::addAgent($name, $interval);
		}
	}

	/**
	 * Идемпотентная довешка недостающих агентов — для уже установленных
	 * модулей (новые агенты появляются при обновлении кода без переустановки).
	 *
	 * @return string[] строки отчёта
	 */
	public static function ensureAgents(): array
	{
		$existing = [];
		$res = \CAgent::GetList(['ID' => 'ASC'], ['MODULE_ID' => Config::MODULE_ID]);
		while ($row = $res->Fetch())
		{
			$existing[(string)$row['NAME']] = true;
		}

		$report = [];
		foreach (self::AGENTS as $name => $interval)
		{
			if (isset($existing[$name]))
			{
				$report[] = 'exists: ' . $name;

				continue;
			}

			$result = \CAgent::AddAgent($name, Config::MODULE_ID, 'N', $interval);
			$report[] = ($result === false ? 'FAILED: ' : 'added: ') . $name;
		}

		return $report;
	}

	public static function unregister(): void
	{
		$manager = EventManager::getInstance();

		$manager->unRegisterEventHandler(
			'main', 'OnBuildGlobalMenu', Config::MODULE_ID, static::class, 'onBuildGlobalMenu'
		);

		\CAgent::RemoveModuleAgents(Config::MODULE_ID);
	}

	private static function addAgent(string $name, int $interval): void
	{
		if (
			\CAgent::AddAgent($name, Config::MODULE_ID, 'N', $interval) === false
		)
		{
			// агент с таким именем уже есть
		}
	}

	public static function onBuildGlobalMenu(&$aGlobalMenu, &$aModuleMenu): void
	{
		if (!Loader::includeModule(Config::MODULE_ID))
		{
			return;
		}

		$aModuleMenu[] = [
			'parent_menu' => 'global_menu_settings',
			'sort' => 3400,
			'text' => GetMessage('MTAI_AIAGENT_MENU_ROOT'),
			'title' => GetMessage('MTAI_AIAGENT_MENU_ROOT_TITLE'),
			'icon' => 'sys_menu_icon',
			'page_icon' => 'sys_page_icon',
			'items_id' => 'mtai_aiagent_menu',
			'items' => [
				[
					'text' => GetMessage('MTAI_AIAGENT_MENU_KB'),
					'url' => 'mtai_aiagent_kb.php?lang=' . LANGUAGE_ID,
					'more_url' => ['mtai_aiagent_kb.php'],
					'title' => GetMessage('MTAI_AIAGENT_MENU_KB'),
				],
				[
					'text' => GetMessage('MTAI_AIAGENT_MENU_LOGS'),
					'url' => 'mtai_aiagent_logs.php?lang=' . LANGUAGE_ID,
					'more_url' => ['mtai_aiagent_logs.php'],
					'title' => GetMessage('MTAI_AIAGENT_MENU_LOGS'),
				],
				[
					'text' => GetMessage('MTAI_AIAGENT_MENU_SETTINGS'),
					'url' => 'settings.php?mid=mtai.aiagent&lang=' . LANGUAGE_ID,
					'more_url' => ['settings.php?mid=mtai.aiagent'],
					'title' => GetMessage('MTAI_AIAGENT_MENU_SETTINGS'),
				],
			],
		];
	}
}
