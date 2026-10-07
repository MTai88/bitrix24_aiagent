<?php

namespace Mtai\Aiagent\Queue;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Handler\ChatHandler;
use Mtai\Aiagent\Handler\OpenLineHandler;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Orm\JobTable;

/**
 * Обработчик очереди заданий.
 *
 * Тик атомарно забирает несколько новых заданий (через claim-токен,
 * чтобы параллельные кроны не обрабатывали одно задание дважды),
 * выполняет их и фиксирует результат. Ошибки ретраятся до
 * MAX_ATTEMPTS, потом задание уходит в error и админ получает
 * уведомление.
 */
final class Processor
{
	private const BATCH_SIZE = 5;
	private const MAX_ATTEMPTS = 3;
	private const STALE_SECONDS = 600; // «зависшее» processing-задание возвращаем в очередь

	/** Тик агента: обработать пачку заданий. Возвращает число обработанных. */
	public function tick(): int
	{
		$this->releaseStale();

		$claim = bin2hex(random_bytes(8));
		$ids = $this->claimJobs($claim);
		if ($ids === [])
		{
			return 0;
		}

		$jobs = JobTable::getList([
			'select' => ['ID', 'TYPE', 'PAYLOAD', 'ATTEMPTS'],
			'filter' => [
				'=CLAIM' => $claim,
				'=STATUS' => JobTable::STATUS_PROCESSING,
			],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		$processed = 0;
		foreach ($jobs as $job)
		{
			$this->runJob($job);
			$processed++;
		}

		if ($processed > 0)
		{
			Config::set('last_process_time', date('d.m.Y H:i:s'));
		}

		return $processed;
	}

	private function runJob(array $job): void
	{
		try
		{
			$payload = (array)Json::decode((string)$job['PAYLOAD']);
		}
		catch (\Throwable $e)
		{
			$this->fail($job, 'Malformed payload: ' . $e->getMessage());

			return;
		}

		try
		{
			$this->dispatch((string)$job['TYPE'], $payload);
			JobTable::update($job['ID'], [
				'STATUS' => JobTable::STATUS_DONE,
				'CLAIM' => '',
				'ERROR' => null,
				'DATE_DONE' => new DateTime(),
			]);
			Logger::info('queue', "Job #{$job['ID']} ({$job['TYPE']}) done");
		}
		catch (\Throwable $e)
		{
			$this->fail($job, $e->getMessage());
		}
	}

	private function dispatch(string $type, array $payload): void
	{
		match ($type)
		{
			'message' => $this->dispatchMessage($payload),
			'command' => (new \Mtai\Aiagent\Handler\CommandHandler())
				->handle((array)($payload['data'] ?? [])),
			default => throw new \RuntimeException("Unknown job type: {$type}"),
		};
	}

	/** Маршрутизация сообщения: открытая линия или личный/групповой чат. */
	private function dispatchMessage(array $payload): void
	{
		$data = (array)($payload['data'] ?? []);
		$chat = (array)($data['chat'] ?? []);

		// Сообщения самого бота не обрабатываем.
		$user = (array)($data['user'] ?? []);
		if (!empty($user['bot']))
		{
			return;
		}

		$entityType = mb_strtoupper((string)($chat['entityType'] ?? ''));

		if ($entityType === 'LINES')
		{
			(new OpenLineHandler())->handle($data);
		}
		else
		{
			(new ChatHandler())->handle($data);
		}
	}

	private function fail(array $job, string $error): void
	{
		$attempts = (int)$job['ATTEMPTS'] + 1;

		if ($attempts < self::MAX_ATTEMPTS)
		{
			JobTable::update($job['ID'], [
				'STATUS' => JobTable::STATUS_NEW,
				'CLAIM' => '',
				'ATTEMPTS' => $attempts,
				'ERROR' => $error,
			]);
			Logger::warn('queue', "Job #{$job['ID']} failed (attempt {$attempts}): {$error}");

			return;
		}

		JobTable::update($job['ID'], [
			'STATUS' => JobTable::STATUS_ERROR,
			'CLAIM' => '',
			'ATTEMPTS' => $attempts,
			'ERROR' => $error,
			'DATE_DONE' => new DateTime(),
		]);
		Logger::error('queue', "Job #{$job['ID']} permanently failed: {$error}");

		$this->notifyAdmin($job, $error);
	}

	/**
	 * Уведомление администратора о фатальном сбое задания — сообщение
	 * ботом в личный чат (опция notify_admin_user). Отказ REST (например,
	 * лежит портал) не должен ронять обработку — пишем только в журнал.
	 */
	private function notifyAdmin(array $job, string $error): void
	{
		$adminId = Config::getInt('notify_admin_user');
		$botId = \Mtai\Aiagent\Bot\Manager::botUserId();
		if ($adminId <= 0 || $botId <= 0)
		{
			return;
		}

		$text = "⚠ AI-агент: задание #{$job['ID']} ({$job['TYPE']}) упало после "
			. self::MAX_ATTEMPTS . " попыток:\n" . mb_substr($error, 0, 500)
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
			Logger::warn('queue', 'Admin notification failed: ' . $e->getMessage());
		}
	}

	/** Атомарный захват новых заданий через claim-токен. */
	private function claimJobs(string $claim): array
	{
		global $DB;

		$rows = JobTable::getList([
			'select' => ['ID'],
			'filter' => ['=STATUS' => JobTable::STATUS_NEW],
			'order' => ['ID' => 'ASC'],
			'limit' => self::BATCH_SIZE,
		])->fetchAll();

		if ($rows === [])
		{
			return [];
		}

		$ids = array_map(static fn($row) => (int)$row['ID'], $rows);
		$idList = implode(',', $ids);

		// UPDATE ... WHERE STATUS='new' защищает от гонки с параллельным тиком.
		$DB->Query(
			"UPDATE mtai_aiagent_job
			 SET STATUS = 'processing', CLAIM = '" . $DB->ForSql($claim) . "'
			 WHERE ID IN ({$idList}) AND STATUS = 'new'"
		);

		return $ids;
	}

	/** Возврат «зависших» processing-заданий в очередь (например, убитый cron). */
	private function releaseStale(): void
	{
		global $DB;

		$stale = (new DateTime())->add('-' . self::STALE_SECONDS . ' seconds');
		$DB->Query(
			"UPDATE mtai_aiagent_job
			 SET STATUS = 'new', CLAIM = ''
			 WHERE STATUS = 'processing' AND DATE_CREATE < '" . $stale->format('Y-m-d H:i:s') . "'"
		);
	}
}
