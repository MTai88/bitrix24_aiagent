<?php
/**
 * Журнал AI-агента: запросы к LLM, REST-вызовы, решения по диалогам.
 */

use Bitrix\Main\Localization\Loc;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Orm\LogTable;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

Loc::loadMessages(__FILE__);

$moduleId = 'mtai.aiagent';
if ($APPLICATION->GetGroupRight($moduleId) < 'W')
{
	$APPLICATION->AuthForm(GetMessage('MTAI_AIAGENT_ACCESS_DENIED'));
}

\Bitrix\Main\Loader::includeModule($moduleId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && ($_POST['action'] ?? '') === 'clear')
{
	\Mtai\Aiagent\Logger::clear();
	LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANG);
}

$filterLevel = (string)($_GET['level'] ?? '');
$filterCategory = trim((string)($_GET['category'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$filter = [];
if (in_array($filterLevel, ['debug', 'info', 'warn', 'error'], true))
{
	$filter['=LEVEL'] = $filterLevel;
}
if ($filterCategory !== '')
{
	$filter['=CATEGORY'] = mb_substr($filterCategory, 0, 32);
}

$total = (int)LogTable::getCount($filter);
$pages = max(1, (int)ceil($total / $perPage));

$rows = LogTable::getList([
	'filter' => $filter,
	'order' => ['ID' => 'DESC'],
	'limit' => $perPage,
	'offset' => ($page - 1) * $perPage,
])->fetchAll();

// Сводка за сутки: события по категориям, состояние очереди и базы знаний.
$since = (new \Bitrix\Main\Type\DateTime())->add('-1 day');
$byCategory = [];
$categoryRows = LogTable::getList([
	'select' => ['CATEGORY'],
	'filter' => ['>=DATE_CREATE' => $since],
])->fetchAll();
foreach ($categoryRows as $row)
{
	$byCategory[(string)$row['CATEGORY']] = ($byCategory[(string)$row['CATEGORY']] ?? 0) + 1;
}
arsort($byCategory);

$jobsNew = (int)\Mtai\Aiagent\Orm\JobTable::getCount(['=STATUS' => \Mtai\Aiagent\Orm\JobTable::STATUS_NEW]);
$jobsError = (int)\Mtai\Aiagent\Orm\JobTable::getCount(['=STATUS' => \Mtai\Aiagent\Orm\JobTable::STATUS_ERROR]);
$docsReady = (int)\Mtai\Aiagent\Orm\DocumentTable::getCount(['=STATUS' => 'ready']);
$chunksTotal = (int)\Mtai\Aiagent\Orm\ChunkTable::getCount();

$APPLICATION->SetTitle(GetMessage('MTAI_AIAGENT_LOGS_TITLE'));

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
?>

<table class="internal" style="width: 100%;">
	<tr class="heading">
		<td colspan="2"><?= GetMessage('MTAI_AIAGENT_LOGS_SUMMARY') ?></td>
	</tr>
	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_LOGS_SUMMARY_DAY') ?></td>
		<td>
			<?php if ($byCategory === []): ?>
				<?= GetMessage('MTAI_AIAGENT_LOGS_EMPTY') ?>
			<?php else: ?>
				<?php foreach ($byCategory as $category => $count): ?>
					<span style="margin-right: 16px; white-space: nowrap;"><?= htmlspecialcharsbx($category) ?>: <b><?= (int)$count ?></b></span>
				<?php endforeach; ?>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LOGS_SUMMARY_STATE') ?></td>
		<td>
			<?= GetMessage('MTAI_AIAGENT_LOGS_SUMMARY_QUEUE') ?>: <?= $jobsNew ?> / <?= GetMessage('MTAI_AIAGENT_LOGS_SUMMARY_ERRORS') ?>: <?= $jobsError;
			?>
			&nbsp;&nbsp; <?= GetMessage('MTAI_AIAGENT_LOGS_SUMMARY_KB') ?>: <?= $docsReady ?> / <?= $chunksTotal ?> <?= GetMessage('MTAI_AIAGENT_KB_CHUNKS') ?>
		</td>
	</tr>
</table>

<br>

<form method="GET" action="<?= $APPLICATION->GetCurPage() ?>">
	<input type="hidden" name="lang" value="<?= LANG ?>">
	<table class="internal" style="width: 100%;">
		<tr class="heading">
			<td colspan="4"><?= GetMessage('MTAI_AIAGENT_LOGS_FILTER') ?></td>
		</tr>
		<tr>
			<td><?= GetMessage('MTAI_AIAGENT_LOGS_LEVEL') ?>:</td>
			<td>
				<select name="level">
					<option value=""><?= GetMessage('MTAI_AIAGENT_LOGS_ALL') ?></option>
					<?php foreach (['debug', 'info', 'warn', 'error'] as $level): ?>
						<option value="<?= $level ?>" <?= $filterLevel === $level ? 'selected' : '' ?>><?= $level ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><?= GetMessage('MTAI_AIAGENT_LOGS_CATEGORY') ?>:</td>
			<td>
				<input type="text" name="category" value="<?= htmlspecialcharsbx($filterCategory) ?>" size="20">
				<button type="submit"><?= GetMessage('MTAI_AIAGENT_LOGS_APPLY') ?></button>
			</td>
		</tr>
	</table>
</form>

<br>

<table class="internal" style="width: 100%;">
	<tr class="heading">
		<td colspan="4">
			<?= GetMessage('MTAI_AIAGENT_LOGS_HEAD') ?> (<?= $total ?>)
			<form method="POST" style="display:inline; float:right"
				  onsubmit="return confirm('<?= CUtil::JSEscape(GetMessage('MTAI_AIAGENT_LOGS_CLEAR_CONFIRM')) ?>')">
				<?= bitrix_sessid_post() ?>
				<input type="hidden" name="action" value="clear">
				<button type="submit"><?= GetMessage('MTAI_AIAGENT_LOGS_CLEAR') ?></button>
			</form>
		</td>
	</tr>
	<tr>
		<td width="140"><?= GetMessage('MTAI_AIAGENT_LOGS_DATE') ?></td>
		<td width="60"><?= GetMessage('MTAI_AIAGENT_LOGS_LEVEL') ?></td>
		<td width="90"><?= GetMessage('MTAI_AIAGENT_LOGS_CATEGORY') ?></td>
		<td><?= GetMessage('MTAI_AIAGENT_LOGS_MESSAGE') ?></td>
	</tr>
	<?php if ($rows === []): ?>
		<tr><td colspan="4" align="center"><?= GetMessage('MTAI_AIAGENT_LOGS_EMPTY') ?></td></tr>
	<?php else: ?>
		<?php
		$colors = ['debug' => '#888', 'info' => '#070', 'warn' => '#a60', 'error' => '#c00'];
		foreach ($rows as $row):
			?>
			<tr valign="top">
				<td><?= $row['DATE_CREATE']->format('d.m.Y H:i:s') ?></td>
				<td style="color: <?= $colors[$row['LEVEL']] ?? '#000' ?>"><?= htmlspecialcharsbx($row['LEVEL']) ?></td>
				<td><?= htmlspecialcharsbx($row['CATEGORY']) ?></td>
				<td>
					<?= htmlspecialcharsbx($row['MESSAGE']) ?>
					<?php if ((string)$row['CONTEXT'] !== ''): ?>
						<details>
							<summary><?= GetMessage('MTAI_AIAGENT_LOGS_CONTEXT') ?></summary>
							<pre style="white-space: pre-wrap; max-width: 900px;"><?= htmlspecialcharsbx((string)$row['CONTEXT']) ?></pre>
						</details>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
</table>

<?php if ($pages > 1): ?>
	<div style="margin-top: 10px">
		<?php for ($p = 1; $p <= min($pages, 30); $p++): ?>
			<?php if ($p === $page): ?>
				<b><?= $p ?></b>
			<?php else: ?>
				<a href="?lang=<?= LANG ?>&page=<?= $p ?><?= $filterLevel !== '' ? '&level=' . urlencode($filterLevel) : '' ?><?= $filterCategory !== '' ? '&category=' . urlencode($filterCategory) : '' ?>"><?= $p ?></a>
			<?php endif; ?>
		<?php endfor; ?>
	</div>
<?php endif; ?>

<?php require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
