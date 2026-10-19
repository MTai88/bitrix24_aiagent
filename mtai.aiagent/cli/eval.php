<?php
/**
 * Проверка выбора инструментов и чтения CRM/задач.
 *
 *   php local/modules/mtai.aiagent/cli/eval.php check
 *     — ожидаемые инструменты зарегистрированы, в описании есть триггер.
 *   php local/modules/mtai.aiagent/cli/eval.php probe [userId]
 *     — read-only вызовы от сотрудника (по умолчанию 3, test.user).
 *   php local/modules/mtai.aiagent/cli/eval.php live
 *     — один вызов LLM на фразу: первый инструмент должен совпасть.
 *       На мок-провайдере пропускается.
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
define('CHK_EVENT', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Mtai\Aiagent\Llm\Factory;
use Mtai\Aiagent\Rest\Client;
use Mtai\Aiagent\Tools\ToolRegistry;

if (!Loader::includeModule('mtai.aiagent'))
{
	fwrite(STDERR, "Module mtai.aiagent is not installed\n");
	exit(1);
}

$command = (string)($argv[1] ?? 'check');

/**
 * Фраза → инструмент, который модель должна выбрать первым,
 * и фрагмент, который обязан быть в описании инструмента.
 */
$scenarios = [
	// first — допустимый первый вызов. Без ID сущности правильный шаг — поиск, не изменение.
	['phrase' => 'Поставь Иванову задачу по сделке Ромашка на пятницу', 'tool' => 'crm_create_task', 'hint' => 'поставить задачу', 'first' => ['crm_create_task', 'crm_find_entities']],
	['phrase' => 'Мои просроченные задачи', 'tool' => 'tasks_list', 'hint' => 'просрочен'],
	['phrase' => 'Переведи сделку на этап Счёт выставлен', 'tool' => 'crm_update_entity', 'hint' => 'стадия', 'first' => ['crm_find_entities', 'crm_update_entity']],
	['phrase' => 'Что у меня сегодня', 'tool' => 'my_day', 'hint' => 'сегодня'],
	['phrase' => 'Запланируй звонок клиенту по сделке на завтра', 'tool' => 'crm_activity_add', 'hint' => 'звонок', 'first' => ['crm_find_entities', 'crm_activity_add']],
	['phrase' => 'Что по сделке Ромашка', 'tool' => 'crm_find_entities', 'hint' => 'найди'],
	['phrase' => 'Каждое утро в 9 присылай сводку дня', 'tool' => 'schedule_task', 'hint' => 'repeat'],
	['phrase' => 'Перенеси задачу 15 на пятницу', 'tool' => 'task_update', 'hint' => 'срок'],
];

$failed = 0;
$out = static fn(string $line) => print($line . "\n");

if ($command === 'check')
{
	$names = ToolRegistry::names();
	foreach ($scenarios as $scenario)
	{
		$meta = null;
		foreach (ToolRegistry::definitions() as $tool)
		{
			if (($tool['function']['name'] ?? '') === $scenario['tool'])
			{
				$meta = $tool['function'];
				break;
			}
		}
		if ($meta === null || !in_array($scenario['tool'], $names, true))
		{
			$failed++;
			$out('FAIL ' . $scenario['tool'] . ' не зарегистрирован');
			continue;
		}
		$description = mb_strtolower((string)$meta['description']);
		if (!str_contains($description, mb_strtolower($scenario['hint'])))
		{
			$failed++;
			$out('FAIL ' . $scenario['tool'] . ' — в описании нет «' . $scenario['hint'] . '»');
			continue;
		}
		$out('ok   ' . $scenario['tool']);
	}
	$out($failed === 0 ? 'check passed' : "check failed: {$failed}");
	exit($failed === 0 ? 0 : 1);
}

if ($command === 'probe')
{
	$userId = (int)($argv[2] ?? 3);
	$calls = [
		['tasks_list', ['status' => 'open', 'limit' => 3]],
		['tasks_list', ['overdue' => true, 'limit' => 3]],
		['crm_find_entities', ['entity_type' => 'deal', 'limit' => 3]],
		['crm_activities_list', ['period' => 'all', 'limit' => 3]],
		['my_day', []],
		['list_scheduled_tasks', []],
	];
	foreach ($calls as [$name, $args])
	{
		$result = ToolRegistry::execute($name, $args, $userId, new Client());
		$error = $result['error'] ?? null;
		$out(($error ? 'FAIL' : 'ok  ') . " {$name} user={$userId}" . ($error ? " — {$error}" : ''));
		if ($error)
		{
			$failed++;
		}
	}
	$out($failed === 0 ? 'probe passed' : "probe failed: {$failed}");
	exit($failed === 0 ? 0 : 1);
}

if ($command === 'live')
{
	$provider = Factory::create();
	$out('provider: ' . $provider::code());
	if ($provider::code() === 'mock' || !$provider->supportsTools())
	{
		$out('live skipped: нужен провайдер с function calling');
		exit(0);
	}

	$tools = ToolRegistry::definitions();
	$system = 'Ты выбираешь инструмент портала Битрикс24. Вызови ровно один инструмент и не отвечай текстом. Текущая дата ' . date('Y-m-d') . '.';
	foreach ($scenarios as $scenario)
	{
		try
		{
			$answer = $provider->chat([
				['role' => 'system', 'content' => $system],
				['role' => 'user', 'content' => $scenario['phrase']],
			], $tools);
		}
		catch (\Throwable $e)
		{
			$failed++;
			$out('FAIL «' . $scenario['phrase'] . '» — ' . $e->getMessage());
			continue;
		}

		$called = (string)($answer['toolCalls'][0]['name'] ?? '');
		$accepted = $scenario['first'] ?? [$scenario['tool']];
		if (!in_array($called, $accepted, true))
		{
			$failed++;
			$out('FAIL «' . $scenario['phrase'] . '» ждали ' . implode('|', $accepted) . ', вызван ' . ($called !== '' ? $called : '(нет вызова)'));
			continue;
		}
		$out('ok   ' . $called . ' ← ' . $scenario['phrase']);
	}
	$out($failed === 0 ? 'live passed' : "live failed: {$failed}");
	exit($failed === 0 ? 0 : 1);
}

$out('Usage: eval.php check|probe [userId]|live');
exit(1);
