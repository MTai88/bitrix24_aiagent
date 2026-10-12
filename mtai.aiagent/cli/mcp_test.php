<?php
/**
 * CLI-диагностика MCP-серверов модуля mtai.aiagent.
 *
 *   docker compose exec php php local/modules/mtai.aiagent/cli/mcp_test.php status
 *       — handshake + инструменты по всем включённым серверам из настроек
 *   docker compose exec php php local/modules/mtai.aiagent/cli/mcp_test.php list <url> [apiKey]
 *       — инструменты одного сервера
 *   docker compose exec php php local/modules/mtai.aiagent/cli/mcp_test.php call <url> <tool> '{"a":1}' [apiKey]
 *       — вызов одного инструмента
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli')
{
	die('CLI only');
}

$documentRoot = __DIR__;
while ($documentRoot !== '/' && !is_dir($documentRoot . '/bitrix/modules'))
{
	$documentRoot = dirname($documentRoot);
}
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Mcp\Client;
use Mtai\Aiagent\Mcp\ServerConfig;

if (!Loader::includeModule('mtai.aiagent'))
{
	fwrite(STDERR, "Module mtai.aiagent is not installed\n");
	exit(1);
}

$command = (string)($argv[1] ?? 'status');
$out = static fn(string $line = '') => print($line . "\n");

try
{
	switch ($command)
	{
		case 'status':
			Client::resetCache();
			// Все добавленные серверы — активности БП могут использовать
			// любой, не только отмеченные «в чате».
			$rows = (new \Mtai\Aiagent\Mcp\Manager(ServerConfig::moduleServers(false)))->status();
			if ($rows === [])
			{
				$out('Нет добавленных серверов (Настройки → AI-агент → вкладка MCP).');
				break;
			}
			foreach ($rows as $row)
			{
				$out($row['name'] . ': ' . ($row['ok'] ? $row['tools'] . ' инструментов' : 'ОШИБКА ' . $row['error']));
			}
			break;

		case 'list':
			$url = (string)($argv[2] ?? '');
			$key = (string)($argv[3] ?? '');
			if ($url === '')
			{
				fwrite(STDERR, "Usage: mcp_test.php list <url> [apiKey]\n");
				exit(1);
			}
			foreach (Client::forServer($url, $key)->listTools() as $tool)
			{
				$out('- ' . $tool['name'] . ': ' . (string)($tool['description'] ?? ''));
			}
			break;

		case 'call':
			$url = (string)($argv[2] ?? '');
			$tool = (string)($argv[3] ?? '');
			$args = (array)Json::decode((string)($argv[4] ?? '{}'));
			$key = (string)($argv[5] ?? '');
			if ($url === '' || $tool === '')
			{
				fwrite(STDERR, "Usage: mcp_test.php call <url> <tool> '{\"a\":1}' [apiKey]\n");
				exit(1);
			}
			$out(print_r(Client::forServer($url, $key)->callTool($tool, $args), true));
			break;

		default:
			$out('Usage: mcp_test.php status|list <url> [key]|call <url> <tool> <json> [key]');
	}
}
catch (\Throwable $e)
{
	fwrite(STDERR, 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
	exit(1);
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
