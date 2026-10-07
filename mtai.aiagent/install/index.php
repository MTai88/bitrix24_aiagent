<?php
/**
 * Установщик модуля mtai.aiagent.
 *
 * Создаёт таблицы модуля (документы и фрагменты базы знаний, диалоги,
 * очередь заданий, журнал), регистрирует агентов (получение событий
 * imbot.v2.Event.get и обработка очереди) и страницы админки.
 *
 * Бот и связь с REST-приложением настраиваются после установки
 * на странице настроек модуля.
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class mtai_aiagent extends CModule
{
	public $MODULE_ID = 'mtai.aiagent';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_GROUP_RIGHTS = 'R';

	private const TABLES = [
		'mtai_aiagent_document',
		'mtai_aiagent_chunk',
		'mtai_aiagent_conversation',
		'mtai_aiagent_job',
		'mtai_aiagent_log',
	];

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '');
		$this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? '');

		$this->MODULE_NAME = Loc::getMessage('MTAI_AIAGENT_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('MTAI_AIAGENT_MODULE_DESCRIPTION');
		$this->PARTNER_NAME = Loc::getMessage('MTAI_AIAGENT_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('MTAI_AIAGENT_PARTNER_URI');
	}

	public function DoInstall(): void
	{
		global $DB, $APPLICATION;

		$this->InstallDB();
		$this->InstallEvents();
		$this->InstallFiles();

		$GLOBALS['errors'] = $this->errors;
	}

	public function DoUninstall(): void
	{
		// Бота и приложение удаляем только явно (кнопками на страницы настроек):
		// при переустановке модуля они должны выжить.
		$this->UnInstallFiles();
		$this->UnInstallEvents();
		$this->UnInstallDB(false);
	}

	public function InstallDB(): bool
	{
		global $DB;

		ModuleManager::registerModule($this->MODULE_ID);

		$sql = [
			// Документы базы знаний
			"CREATE TABLE IF NOT EXISTS mtai_aiagent_document (
				ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
				NAME VARCHAR(255) NOT NULL,
				FILE_ID INT UNSIGNED NOT NULL DEFAULT 0,
				FILE_SIZE BIGINT UNSIGNED NOT NULL DEFAULT 0,
				CHUNKS INT UNSIGNED NOT NULL DEFAULT 0,
				STATUS VARCHAR(16) NOT NULL DEFAULT 'new',
				SCOPE VARCHAR(16) NOT NULL DEFAULT 'public',
				ERROR TEXT NULL,
				DATE_CREATE DATETIME NOT NULL,
				DATE_INDEX DATETIME NULL,
				PRIMARY KEY (ID),
				KEY IX_MTAA_DOC_STATUS (STATUS),
				KEY IX_MTAA_DOC_SCOPE (SCOPE)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			// Фрагменты документов с эмбеддингами (JSON-массив чисел)
			"CREATE TABLE IF NOT EXISTS mtai_aiagent_chunk (
				ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
				DOCUMENT_ID INT UNSIGNED NOT NULL,
				SORT INT UNSIGNED NOT NULL DEFAULT 0,
				CONTENT MEDIUMTEXT NOT NULL,
				EMBEDDING LONGTEXT NOT NULL,
				DATE_CREATE DATETIME NOT NULL,
				PRIMARY KEY (ID),
				KEY IX_MTAA_CHUNK_DOC (DOCUMENT_ID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			// История диалогов (контекстное окно LLM)
			"CREATE TABLE IF NOT EXISTS mtai_aiagent_conversation (
				ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
				DIALOG_KEY VARCHAR(64) NOT NULL,
				MESSAGES LONGTEXT NOT NULL,
				DATE_UPDATE DATETIME NOT NULL,
				PRIMARY KEY (ID),
				UNIQUE KEY UX_MTAA_CONV_KEY (DIALOG_KEY)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			// Очередь асинхронной обработки событий
			"CREATE TABLE IF NOT EXISTS mtai_aiagent_job (
				ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
				TYPE VARCHAR(32) NOT NULL,
				PAYLOAD LONGTEXT NOT NULL,
				STATUS VARCHAR(16) NOT NULL DEFAULT 'new',
				ATTEMPTS INT UNSIGNED NOT NULL DEFAULT 0,
				CLAIM VARCHAR(32) NOT NULL DEFAULT '',
				ERROR TEXT NULL,
				DATE_CREATE DATETIME NOT NULL,
				DATE_DONE DATETIME NULL,
				PRIMARY KEY (ID),
				KEY IX_MTAA_JOB_PICK (STATUS, ID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			// Журнал запросов к LLM и REST
			"CREATE TABLE IF NOT EXISTS mtai_aiagent_log (
				ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
				LEVEL VARCHAR(8) NOT NULL DEFAULT 'info',
				CATEGORY VARCHAR(32) NOT NULL DEFAULT 'general',
				MESSAGE TEXT NOT NULL,
				CONTEXT LONGTEXT NULL,
				DATE_CREATE DATETIME NOT NULL,
				PRIMARY KEY (ID),
				KEY IX_MTAA_LOG_DATE (DATE_CREATE)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
		];

		foreach ($sql as $query)
		{
			$DB->Query($query, true, __FILE__);
		}

		return true;
	}

	public function UnInstallDB(bool $keepTables = true): bool
	{
		global $DB;

		if (!$keepTables)
		{
			foreach (array_reverse(self::TABLES) as $table)
			{
				$DB->Query("DROP TABLE IF EXISTS {$table}", true, __FILE__);
			}
		}

		ModuleManager::unRegisterModule($this->MODULE_ID);

		return true;
	}

	public function InstallEvents(): bool
	{
		// Классы lib/ автозагружаются только после включения модуля.
		\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
		\Mtai\Aiagent\EventHandler::register();

		return true;
	}

	public function UnInstallEvents(): bool
	{
		if (\Bitrix\Main\Loader::includeModule($this->MODULE_ID))
		{
			\Mtai\Aiagent\EventHandler::unregister();
		}

		return true;
	}

	public function InstallFiles(): bool
	{
		// Страницы админки: в /bitrix/admin кладём заглушки, подключающие
		// реальные страницы из каталога модуля — так разрешение языковых
		// файлов идёт по пути модуля, как у штатных модулей.
		foreach (['mtai_aiagent_kb.php', 'mtai_aiagent_logs.php'] as $page)
		{
			$stubPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . $page;
			CheckDirPath(dirname($stubPath) . '/');
			file_put_contents(
				$stubPath,
				'<?php require($_SERVER["DOCUMENT_ROOT"] . "/local/modules/mtai.aiagent/install/admin/' . $page . '");'
				. "\n"
			);
		}

		// Активность «AI-агент» для дизайнера бизнес-процессов:
		// bizproc сканирует /bitrix/activities/custom/.
		CopyDirFiles(
			__DIR__ . '/activities/mtaiagentactivity',
			$_SERVER['DOCUMENT_ROOT'] . '/bitrix/activities/custom/mtaiagentactivity',
			true,
			true
		);

		return true;
	}

	public function UnInstallFiles(): bool
	{
		foreach (['mtai_aiagent_kb.php', 'mtai_aiagent_logs.php'] as $page)
		{
			$path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . $page;
			if (is_file($path))
			{
				unlink($path);
			}
		}

		DeleteDirFilesEx('/bitrix/activities/custom/mtaiagentactivity');

		return true;
	}
}
