<?php

namespace Mtai\Aiagent;

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;

/** Регистрация обработчиков событий и агентов модуля. */
final class EventHandler
{
	public static function register(): void
	{
		$manager = EventManager::getInstance();
		$module = Config::MODULE_ID;
		$class = static::class;

		// Пункты админ-меню: база знаний и журнал.
		$manager->registerEventHandlerCompatible(
			'main', 'OnBuildGlobalMenu', $module, $class, 'onBuildGlobalMenu'
		);

		self::addAgent('\\Mtai\\Aiagent\\Agent::fetchEvents();', 60);
		self::addAgent('\\Mtai\\Aiagent\\Agent::processQueue();', 60);
		self::addAgent('\\Mtai\\Aiagent\\Agent::cleanLog();', 86400);
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
