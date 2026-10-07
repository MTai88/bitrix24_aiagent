<?php

namespace Mtai\Aiagent;

use Bitrix\Main\Web\Json;

/**
 * Журнал модуля: таблица mtai_aiagent_log + дублирование в лог Битрикса.
 * Уровень регулируется опцией log_level (debug|info|warn|error).
 */
final class Logger
{
	private const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

	public static function debug(string $category, string $message, array $context = []): void
	{
		self::log('debug', $category, $message, $context);
	}

	public static function info(string $category, string $message, array $context = []): void
	{
		self::log('info', $category, $message, $context);
	}

	public static function warn(string $category, string $message, array $context = []): void
	{
		self::log('warn', $category, $message, $context);
	}

	public static function error(string $category, string $message, array $context = []): void
	{
		self::log('error', $category, $message, $context);
	}

	public static function log(string $level, string $category, string $message, array $context = []): void
	{
		$threshold = self::LEVELS[Config::get('log_level', 'info')] ?? 1;
		if ((self::LEVELS[$level] ?? 1) < $threshold)
		{
			return;
		}

		try
		{
			Orm\LogTable::add([
				'LEVEL' => $level,
				'CATEGORY' => mb_substr($category, 0, 32),
				'MESSAGE' => $message,
				'CONTEXT' => $context === [] ? null : Json::encode($context, JSON_UNESCAPED_UNICODE),
				'DATE_CREATE' => new \Bitrix\Main\Type\DateTime(),
			]);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('mtai.aiagent: cannot write log row: ' . $e->getMessage(), Config::MODULE_ID);
		}

		AddMessage2Log('mtai.aiagent [' . $level . '/' . $category . '] ' . $message, Config::MODULE_ID);
	}

	/** Очистка журнала старше N дней (вызывается агентом). */
	public static function clean(int $days = 14): void
	{
		$expire = \Bitrix\Main\Type\DateTime::createFromTimestamp(time() - $days * 86400);
		Orm\LogTable::deleteByFilter(['<DATE_CREATE' => $expire]);
	}

	/** Полная очистка журнала. */
	public static function clear(): void
	{
		\Bitrix\Main\Application::getConnection()
			->queryExecute('TRUNCATE TABLE ' . Orm\LogTable::getTableName());
	}
}
