<?php
/**
 * CLI-воркер модуля mtai.aiagent — запуск и отладка без ожидания агентов.
 *
 * Примеры (из PHP-контейнера стенда):
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php fetch
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php process
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php schedule
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php tasks
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php agents
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php loop
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php bot status|register|update|unregister
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php kb list|reindex|reindex-all|delete ID
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php jobs
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php log tail 20
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php rest METHOD '{"param":1}'
 *   docker compose exec php php local/modules/mtai.aiagent/cli/worker.php tool NAME '{"arg":1}' [USER_ID]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli')
{
	die('CLI only');
}

// Корень портала: поднимаемся вверх, пока не найдём bitrix/modules.
// Наивная арифметика уровней ломается, когда модуль подключён симлинком
// из отдельного репозитория (mtai.aiagent-repo).
$documentRoot = __DIR__;
while ($documentRoot !== '/' && !is_dir($documentRoot . '/bitrix/modules'))
{
	$documentRoot = dirname($documentRoot);
}
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('CHK_EVENT', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Mtai\Aiagent\Bot\Manager;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Event\Fetcher;
use Mtai\Aiagent\Orm\JobTable;
use Mtai\Aiagent\Orm\LogTable;
use Mtai\Aiagent\Queue\Processor;
use Mtai\Aiagent\Rag\DocumentService;
use Mtai\Aiagent\Rest\Client;

if (!Loader::includeModule('mtai.aiagent'))
{
	fwrite(STDERR, "Module mtai.aiagent is not installed\n");
	exit(1);
}

$command = (string)($argv[1] ?? 'help');
$arg = $argv[2] ?? null;

$out = static fn(string $line = '') => print($line . "\n");

try
{
	switch ($command)
	{
		case 'fetch':
			$out('Fetched jobs: ' . (new Fetcher())->tick());
			break;

		case 'process':
			$out('Processed jobs: ' . (new Processor())->tick());
			break;

		case 'schedule':
			$out('Scheduled tasks processed: ' . (new \Mtai\Aiagent\Schedule\Scheduler())->tick());
			break;

		case 'tasks':
			\Mtai\Aiagent\Orm\ScheduleTable::ensureRepeatColumn();
			$rows = \Mtai\Aiagent\Orm\ScheduleTable::getList([
				'order' => ['ID' => 'DESC'],
				'limit' => 20,
			])->fetchAll();
			foreach ($rows as $row)
			{
				$out(sprintf(
					'#%d user=%s [%s] run_at=%s attempts=%d %s',
					$row['ID'],
					$row['USER_ID'],
					$row['STATUS'],
					$row['RUN_AT'] instanceof \Bitrix\Main\Type\DateTime ? $row['RUN_AT']->format('d.m.Y H:i') : (string)$row['RUN_AT'],
					$row['ATTEMPTS'],
					$row['ERROR'] ? 'error: ' . mb_substr((string)$row['ERROR'], 0, 120) : ''
				));
			}
			break;

		case 'agents':
			foreach (\Mtai\Aiagent\EventHandler::ensureAgents() as $line)
			{
				$out($line);
			}
			break;

		case 'loop':
			$out('Loop mode: fetch+process every 15 s, Ctrl+C to stop');
			while (true)
			{
				$created = (new Fetcher())->tick();
				$processed = (new Processor())->tick();
				if ($created || $processed)
				{
					$out(date('H:i:s') . " fetched={$created} processed={$processed}");
				}
				sleep(15);
			}
			// no break needed

		case 'bot':
			$manager = new Manager();
			switch ((string)$arg)
			{
				case 'register':
					$out('Registered, botId=' . $manager->register());
					break;
				case 'update':
					$manager->update();
					$out('Updated');
					break;
				case 'unregister':
					$manager->unregister();
					$out('Unregistered');
					break;
				case 'status':
					$out(print_r($manager->status(), true));
					break;
				default:
					$out('Usage: bot register|update|unregister|status');
			}
			break;

		case 'kb':
			$service = new DocumentService();
			switch ((string)$arg)
			{
				case 'reindex':
					$service->indexDocument((int)($argv[3] ?? 0));
					$out('Reindexed');
					break;
				case 'reindex-all':
					$out('Reindexed: ' . $service->reindexAll());
					break;
				case 'delete':
					$service->delete((int)($argv[3] ?? 0));
					$out('Deleted');
					break;
				default:
					$rows = \Mtai\Aiagent\Orm\DocumentTable::getList(['order' => ['ID' => 'DESC']])->fetchAll();
					foreach ($rows as $row)
					{
						$out(sprintf(
							"#%d %s [%s] chunks=%d %s",
							$row['ID'],
							$row['NAME'],
							$row['STATUS'],
							$row['CHUNKS'],
							$row['ERROR'] ? 'error: ' . mb_substr((string)$row['ERROR'], 0, 120) : ''
						));
					}
			}
			break;

		case 'jobs':
			$rows = JobTable::getList([
				'order' => ['ID' => 'DESC'],
				'limit' => 20,
			])->fetchAll();
			foreach ($rows as $row)
			{
				$out(sprintf(
					'#%d %s [%s] attempts=%d %s',
					$row['ID'],
					$row['TYPE'],
					$row['STATUS'],
					$row['ATTEMPTS'],
					$row['ERROR'] ? 'error: ' . mb_substr((string)$row['ERROR'], 0, 120) : ''
				));
			}
			break;

		case 'log':
			$rows = LogTable::getList([
				'order' => ['ID' => 'DESC'],
				'limit' => max(1, (int)($arg ?? 20)),
			])->fetchAll();
			foreach ($rows as $row)
			{
				$out(sprintf(
					'%s [%s/%s] %s %s',
					$row['DATE_CREATE']->format('d.m.Y H:i:s'),
					$row['LEVEL'],
					$row['CATEGORY'],
					$row['MESSAGE'],
					$row['CONTEXT'] ? ' :: ' . mb_substr((string)$row['CONTEXT'], 0, 200) : ''
				));
			}
			break;

		case 'rest':
			$method = (string)$arg;
			$params = [];
			if (isset($argv[3]) && trim((string)$argv[3]) !== '')
			{
				$params = (array)\Bitrix\Main\Web\Json::decode((string)$argv[3]);
			}
			$out(print_r((new Client())->call($method, $params), true));
			break;

		case 'tool':
			$name = (string)$arg;
			$params = [];
			if (isset($argv[3]) && trim((string)$argv[3]) !== '')
			{
				$params = (array)\Bitrix\Main\Web\Json::decode((string)$argv[3]);
			}
			$forUserId = (int)($argv[4] ?? 1);
			$out(print_r(\Mtai\Aiagent\Tools\ToolRegistry::execute($name, $params, $forUserId, new Client()), true));
			break;

		case 'options':
			$out('portal_url: ' . Config::portalUrl());
			$out('app configured: ' . (Client::isConfigured() ? 'yes' : 'no'));
			$out('bot_user_id: ' . Config::get('bot_user_id'));
			$out('event_offset: ' . Config::get('event_offset'));
			$out('last fetch: ' . (Config::get('last_fetch_time') ?: '—'));
			$out('llm provider: ' . Config::get('llm_provider'));
			$out('embedding provider: ' . Config::get('llm_embedding_provider'));
			$out('last schedule tick: ' . (Config::get('last_schedule_time') ?: '—'));
			break;

		default:
			$out('Usage: worker.php fetch|process|schedule|loop|tasks|agents|bot <cmd>|kb <cmd> [id]|jobs|log <n>|rest METHOD JSON|tool NAME JSON [userId]|options');
	}
}
catch (\Throwable $e)
{
	fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . ($e->getTraceAsString() ? '' : ''));
	exit(1);
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
