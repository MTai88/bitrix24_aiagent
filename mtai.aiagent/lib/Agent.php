<?php

namespace Mtai\Aiagent;

/**
 * Точки входа агентов Битрикса (запускаются сервисом bitrix-cron
 * каждую минуту). Каждая возвращает строку для перепланирования.
 */
final class Agent
{
	/** Забрать события imbot.v2.Event.get в очередь. */
	public static function fetchEvents(): string
	{
		try
		{
			(new Event\Fetcher())->tick();
		}
		catch (\Throwable $e)
		{
			Logger::error('fetch', 'Agent fetchEvents: ' . $e->getMessage());
		}

		return '\\' . static::class . '::fetchEvents();';
	}

	/** Обработать очередь заданий (LLM + ответы клиентам). */
	public static function processQueue(): string
	{
		try
		{
			(new Queue\Processor())->tick();
		}
		catch (\Throwable $e)
		{
			Logger::error('queue', 'Agent processQueue: ' . $e->getMessage());
		}

		return '\\' . static::class . '::processQueue();';
	}

	/** Выполнить назревшие отложенные задания (schedule_task). */
	public static function runSchedule(): string
	{
		try
		{
			(new Schedule\Scheduler())->tick();
		}
		catch (\Throwable $e)
		{
			Logger::error('schedule', 'Agent runSchedule: ' . $e->getMessage());
		}

		return '\\' . static::class . '::runSchedule();';
	}

	/** Очистка старых записей журнала. */
	public static function cleanLog(): string
	{
		Logger::clean(14);

		return '\\' . static::class . '::cleanLog();';
	}
}
