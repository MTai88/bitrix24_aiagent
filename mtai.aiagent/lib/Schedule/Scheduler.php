<?php

namespace Mtai\Aiagent\Schedule;

use Bitrix\Main\Type\DateTime;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Orm\ScheduleTable;

/**
 * Планировщик отложенных заданий.
 *
 * Тик атомарно забирает несколько назревших заданий (claim-токен, чтобы
 * параллельные кроны не обрабатывали одно задание дважды), выполняет их
 * через Executor и фиксирует результат. Сбои ретраятся до MAX_ATTEMPTS,
 * потом задание уходит в error и админ получает уведомление.
 */
final class Scheduler
{
	private const BATCH_SIZE = 5;
	private const MAX_ATTEMPTS = 3;
	private const STALE_SECONDS = 1800; // «зависшее» processing-задание возвращаем в очередь

	/** Тик агента: обработать пачку назревших заданий. Возвращает число обработанных. */
	public function tick(): int
	{
		$this->releaseStale();

		$claim = bin2hex(random_bytes(8));
		$ids = $this->claimTasks($claim);
		if ($ids === [])
		{
			return 0;
		}

		$tasks = ScheduleTable::getList([
			'select' => ['ID', 'USER_ID', 'DIALOG_ID', 'INSTRUCTION', 'ATTEMPTS'],
			'filter' => [
				'=CLAIM' => $claim,
				'=STATUS' => ScheduleTable::STATUS_PROCESSING,
			],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		$processed = 0;
		foreach ($tasks as $task)
		{
			$this->runTask($task);
			$processed++;
		}

		if ($processed > 0)
		{
			Config::set('last_schedule_time', date('d.m.Y H:i:s'));
		}

		return $processed;
	}

	private function runTask(array $task): void
	{
		try
		{
			$result = (new Executor())->run($task);
			if ($result['error'] !== null)
			{
				$this->fail($task, $result['error']);

				return;
			}

			ScheduleTable::update($task['ID'], [
				'STATUS' => ScheduleTable::STATUS_DONE,
				'CLAIM' => '',
				'ERROR' => null,
				'DATE_DONE' => new DateTime(),
			]);
			Logger::info('schedule', "Task #{$task['ID']} done (user {$task['USER_ID']})");
		}
		catch (\Throwable $e)
		{
			$this->fail($task, $e->getMessage());
		}
	}

	private function fail(array $task, string $error): void
	{
		$attempts = (int)$task['ATTEMPTS'] + 1;

		if ($attempts < self::MAX_ATTEMPTS)
		{
			ScheduleTable::update($task['ID'], [
				'STATUS' => ScheduleTable::STATUS_PENDING,
				'CLAIM' => '',
				'ATTEMPTS' => $attempts,
				'ERROR' => $error,
			]);
			Logger::warn('schedule', "Task #{$task['ID']} failed (attempt {$attempts}): {$error}");

			return;
		}

		ScheduleTable::update($task['ID'], [
			'STATUS' => ScheduleTable::STATUS_ERROR,
			'CLAIM' => '',
			'ATTEMPTS' => $attempts,
			'ERROR' => $error,
			'DATE_DONE' => new DateTime(),
		]);
		Logger::error('schedule', "Task #{$task['ID']} permanently failed: {$error}");

		$this->notifyAdmin($task, $error);
	}

	/**
	 * Уведомление администратора о фатальном сбое — сообщение ботом
	 * в личный чат (опция notify_admin_user).
	 */
	private function notifyAdmin(array $task, string $error): void
	{
		$adminId = Config::getInt('notify_admin_user');
		$botId = \Mtai\Aiagent\Bot\Manager::botUserId();
		if ($adminId <= 0 || $botId <= 0)
		{
			return;
		}

		$text = "⚠ AI-агент: отложенное задание #{$task['ID']} упало после "
			. self::MAX_ATTEMPTS . " попыток:\n" . mb_substr((string)$task['INSTRUCTION'], 0, 300)
			. "\n" . mb_substr($error, 0, 300)
			. "\nЖурнал: /bitrix/admin/mtai_aiagent_logs.php";

		try
		{
			(new \Mtai\Aiagent\Rest\Client())->call('imbot.v2.Chat.Message.send', [
				'botId' => $botId,
				'dialogId' => (string)$adminId,
				'fields' => ['message' => $text],
			]);
		}
		catch (\Throwable $e)
		{
			Logger::warn('schedule', 'Admin notification failed: ' . $e->getMessage());
		}
	}

	/** Атомарный захват назревших заданий через claim-токен. */
	private function claimTasks(string $claim): array
	{
		global $DB;

		$now = (new DateTime())->format('Y-m-d H:i:s');

		$rows = ScheduleTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=STATUS' => ScheduleTable::STATUS_PENDING,
				'<=RUN_AT' => new DateTime(),
			],
			'order' => ['RUN_AT' => 'ASC'],
			'limit' => self::BATCH_SIZE,
		])->fetchAll();

		if ($rows === [])
		{
			return [];
		}

		$ids = array_map(static fn($row) => (int)$row['ID'], $rows);
		$idList = implode(',', $ids);

		// UPDATE ... WHERE STATUS='pending' защищает от гонки с параллельным тиком,
		// повторная проверка RUN_AT — от захвата задания, отменённого в ту же секунду.
		$DB->Query(
			"UPDATE mtai_aiagent_schedule
			 SET STATUS = 'processing', CLAIM = '" . $DB->ForSql($claim) . "',
				 CLAIM_AT = '" . $DB->ForSql($now) . "'
			 WHERE ID IN ({$idList}) AND STATUS = 'pending' AND RUN_AT <= '" . $DB->ForSql($now) . "'"
		);

		return $ids;
	}

	/** Возврат «зависших» processing-заданий в очередь (например, убитый cron). */
	private function releaseStale(): void
	{
		global $DB;

		$stale = (new DateTime())->add('-' . self::STALE_SECONDS . ' seconds');
		$DB->Query(
			"UPDATE mtai_aiagent_schedule
			 SET STATUS = 'pending', CLAIM = ''
			 WHERE STATUS = 'processing' AND CLAIM_AT < '" . $stale->format('Y-m-d H:i:s') . "'"
		);
	}
}
