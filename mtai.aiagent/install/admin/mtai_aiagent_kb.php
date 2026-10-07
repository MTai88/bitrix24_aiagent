<?php
/**
 * База знаний AI-агента: загрузка документов, статус индексации,
 * переиндексация и удаление.
 */

use Bitrix\Main\Localization\Loc;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Orm\DocumentTable;
use Mtai\Aiagent\Rag\DocumentService;
use Mtai\Aiagent\Rag\VectorIndex;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

Loc::loadMessages(__FILE__);

$moduleId = 'mtai.aiagent';
if ($APPLICATION->GetGroupRight($moduleId) < 'W')
{
	$APPLICATION->AuthForm(GetMessage('MTAI_AIAGENT_ACCESS_DENIED'));
}

\Bitrix\Main\Loader::includeModule($moduleId);

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid())
{
	$action = (string)($_POST['action'] ?? '');

	try
	{
		switch ($action)
		{
			case 'upload':
				if (empty($_FILES['document']))
				{
					throw new RuntimeException(GetMessage('MTAI_AIAGENT_KB_NO_FILE'));
				}
				$scope = ($_POST['scope'] ?? '') === \Mtai\Aiagent\Orm\DocumentTable::SCOPE_INTERNAL
					? \Mtai\Aiagent\Orm\DocumentTable::SCOPE_INTERNAL
					: \Mtai\Aiagent\Orm\DocumentTable::SCOPE_PUBLIC;
				$documentId = (new DocumentService())->upload($_FILES['document'], $scope);
				LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANG . '&mtai_msg=' . urlencode('OK|' . GetMessage('MTAI_AIAGENT_KB_UPLOADED') . ' #' . $documentId));
				break;
			case 'reindex':
				(new DocumentService())->indexDocument((int)$_POST['id']);
				LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANG . '&mtai_msg=' . urlencode('OK|' . GetMessage('MTAI_AIAGENT_KB_REINDEXED')));
				break;
			case 'reindex_all':
				$count = (new DocumentService())->reindexAll();
				LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANG . '&mtai_msg=' . urlencode('OK|' . GetMessage('MTAI_AIAGENT_KB_REINDEX_ALL') . ': ' . $count));
				break;
			case 'delete':
				(new DocumentService())->delete((int)$_POST['id']);
				LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANG . '&mtai_msg=' . urlencode('OK|' . GetMessage('MTAI_AIAGENT_KB_DELETED')));
				break;
		}
	}
	catch (Throwable $e)
	{
		$message = new CAdminMessage([
			'MESSAGE' => GetMessage('MTAI_AIAGENT_KB_ERROR'),
			'DETAILS' => $e->getMessage(),
			'TYPE' => 'ERROR',
		]);
	}
}

$APPLICATION->SetTitle(GetMessage('MTAI_AIAGENT_KB_TITLE'));

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

if ($message)
{
	echo $message->Show();
}

if (!empty($_GET['mtai_msg']))
{
	[$msgType, $msgText] = array_pad(explode('|', (string)$_GET['mtai_msg'], 2), 2, '');
	echo (new CAdminMessage([
		'MESSAGE' => $msgText,
		'TYPE' => $msgType === 'OK' ? 'OK' : 'ERROR',
	]))->Show();
}

$scopeFilter = ($_GET['scope'] ?? '');
$filterSql = in_array($scopeFilter, ['public', 'internal'], true) ? ['=SCOPE' => $scopeFilter] : [];

$documents = DocumentTable::getList([
	'order' => ['ID' => 'DESC'],
	'filter' => $filterSql,
])->fetchAll();

$chunkTotal = (new VectorIndex())->size();

// Тест-поиск: как агент увидит базу по конкретному запросу.
$testQuery = trim((string)($_GET['q'] ?? ''));
$testResults = [];
if ($testQuery !== '')
{
	$testResults = (new VectorIndex())->search($testQuery, 10);
}
?>

<form method="GET" action="<?= $APPLICATION->GetCurPage() ?>" name="mtai_aiagent_kb_search">
	<input type="hidden" name="lang" value="<?= LANG ?>">
	<table class="internal" style="width: 100%;">
		<tr class="heading">
			<td colspan="3"><?= GetMessage('MTAI_AIAGENT_KB_TEST_HEAD') ?></td>
		</tr>
		<tr>
			<td width="40%"><?= GetMessage('MTAI_AIAGENT_KB_TEST_QUERY') ?></td>
			<td>
				<input type="text" size="50" name="q" value="<?= htmlspecialcharsbx($testQuery) ?>">
				<button type="submit"><?= GetMessage('MTAI_AIAGENT_KB_TEST_RUN') ?></button>
			</td>
		</tr>
		<?php if ($testQuery !== ''): ?>
			<tr>
				<td valign="top"><?= GetMessage('MTAI_AIAGENT_KB_TEST_RESULTS') ?> (<?= count($testResults) ?>)</td>
				<td>
					<?php if ($testResults === []): ?>
						<span style="color:#c00"><?= GetMessage('MTAI_AIAGENT_KB_TEST_EMPTY') ?></span>
					<?php else: ?>
						<?php foreach ($testResults as $i => $item): ?>
							<div style="margin-bottom: 10px;">
								<b>#<?= $i + 1 ?> score <?= number_format($item['score'], 4) ?></b>
								<span style="color:#888"> doc #<?= (int)$item['documentId'] ?></span>
								<div style="white-space: pre-wrap; max-width: 900px;"><?= htmlspecialcharsbx(mb_substr($item['content'], 0, 400)) ?>…</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endif; ?>
	</table>
</form>

<br>

<form method="POST" action="<?= $APPLICATION->GetCurPageParam('lang=' . LANG) ?>" enctype="multipart/form-data" name="mtai_aiagent_kb">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="action" value="upload">

	<table class="internal" style="width: 100%;">
		<tr class="heading">
			<td colspan="2"><?= GetMessage('MTAI_AIAGENT_KB_UPLOAD_HEAD') ?></td>
		</tr>
		<tr>
			<td width="40%" valign="top"><?= GetMessage('MTAI_AIAGENT_KB_FILE') ?></td>
			<td>
				<input type="file" name="document">
				<div class="adm-option-comment">
					<?= GetMessage('MTAI_AIAGENT_KB_FORMATS') ?>: <?= DocumentService::allowedExtensions() ?>
					(&le; 30 <?= GetMessage('MTAI_AIAGENT_KB_MB') ?>)
				</div>
				<?= GetMessage('MTAI_AIAGENT_KB_SCOPE') ?>: 
				<select name="scope">
					<option value="public"><?= GetMessage('MTAI_AIAGENT_KB_SCOPE_PUBLIC') ?></option>
					<option value="internal"><?= GetMessage('MTAI_AIAGENT_KB_SCOPE_INTERNAL') ?></option>
				</select>
				<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_KB_SCOPE_HINT') ?></div>
				<br><br>
				<button type="submit" name="upload_btn" value="Y"><?= GetMessage('MTAI_AIAGENT_KB_UPLOAD_BTN') ?></button>
			</td>
		</tr>
	</table>
</form>

<br>

<table class="internal" style="width: 100%;">
	<tr class="heading">
		<td colspan="8"><?= GetMessage('MTAI_AIAGENT_KB_DOCS_HEAD') ?> (<?= count($documents) ?>, <?= (int)$chunkTotal ?> <?= GetMessage('MTAI_AIAGENT_KB_CHUNKS') ?>)</td>
	</tr>
	<tr>
		<td>ID</td>
		<td><?= GetMessage('MTAI_AIAGENT_KB_NAME') ?></td>
		<td><?= GetMessage('MTAI_AIAGENT_KB_SIZE') ?></td>
		<td><?= GetMessage('MTAI_AIAGENT_KB_CHUNKS_COL') ?></td>
		<td><?= GetMessage('MTAI_AIAGENT_KB_SCOPE') ?></td>
		<td><?= GetMessage('MTAI_AIAGENT_KB_STATUS') ?></td>
		<td><?= GetMessage('MTAI_AIAGENT_KB_DATE') ?></td>
		<td><?= GetMessage('MTAI_AIAGENT_KB_ACTIONS') ?></td>
	</tr>
	<?php if ($documents === []): ?>
		<tr>
			<td colspan="8" align="center"><?= GetMessage('MTAI_AIAGENT_KB_EMPTY') ?></td>
		</tr>
	<?php else: ?>
		<?php foreach ($documents as $document):
			$statusMap = [
				'new' => ['MTAI_AIAGENT_KB_ST_NEW', '#888'],
				'indexing' => ['MTAI_AIAGENT_KB_ST_INDEXING', '#a60'],
				'ready' => ['MTAI_AIAGENT_KB_ST_READY', '#070'],
				'error' => ['MTAI_AIAGENT_KB_ST_ERROR', '#c00'],
			];
			[$statusLang, $statusColor] = $statusMap[$document['STATUS']] ?? ['MTAI_AIAGENT_KB_ST_NEW', '#888'];
			?>
			<tr>
				<td><?= (int)$document['ID'] ?></td>
				<td><?= htmlspecialcharsbx((string)$document['NAME']) ?></td>
				<td><?= round((int)$document['FILE_SIZE'] / 1024) ?> <?= GetMessage('MTAI_AIAGENT_KB_KB') ?></td>
				<td><?= (int)$document['CHUNKS'] ?></td>
				<td>
					<span style="color: <?= $document['SCOPE'] === 'internal' ? '#a06a00' : '#070' ?>">
						<?= GetMessage($document['SCOPE'] === 'internal' ? 'MTAI_AIAGENT_KB_SCOPE_INTERNAL' : 'MTAI_AIAGENT_KB_SCOPE_PUBLIC') ?>
					</span>
				</td>
				<td>
					<span style="color: <?= $statusColor ?>"><?= GetMessage($statusLang) ?></span>
					<?php if ((string)$document['ERROR'] !== ''): ?>
						<div class="adm-option-comment"><?= htmlspecialcharsbx(mb_substr((string)$document['ERROR'], 0, 200)) ?></div>
					<?php endif; ?>
				</td>
				<td><?= $document['DATE_CREATE']->format('d.m.Y H:i') ?></td>
				<td>
					<form method="POST" style="display: inline"
						  action="<?= $APPLICATION->GetCurPageParam('lang=' . LANG) ?>">
						<?= bitrix_sessid_post() ?>
						<input type="hidden" name="id" value="<?= (int)$document['ID'] ?>">
						<input type="hidden" name="action" value="reindex">
						<button type="submit" value="Y"><?= GetMessage('MTAI_AIAGENT_KB_REINDEX') ?></button>
					</form>
					<form method="POST" style="display: inline"
						  action="<?= $APPLICATION->GetCurPageParam('lang=' . LANG) ?>"
						  onsubmit="return confirm('<?= CUtil::JSEscape(GetMessage('MTAI_AIAGENT_KB_DELETE_CONFIRM')) ?>')">
						<?= bitrix_sessid_post() ?>
						<input type="hidden" name="id" value="<?= (int)$document['ID'] ?>">
						<input type="hidden" name="action" value="delete">
						<button type="submit" value="Y"><?= GetMessage('MTAI_AIAGENT_KB_DELETE') ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
</table>

<?php if ($documents !== []): ?>
	<form method="POST" action="<?= $APPLICATION->GetCurPageParam('lang=' . LANG) ?>" style="margin-top: 10px">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="action" value="reindex_all">
		<button type="submit" value="Y"
				onclick="return confirm('<?= CUtil::JSEscape(GetMessage('MTAI_AIAGENT_KB_REINDEX_ALL_CONFIRM')) ?>')">
			<?= GetMessage('MTAI_AIAGENT_KB_REINDEX_ALL') ?>
		</button>
		<span class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_KB_REINDEX_ALL_HINT') ?></span>
	</form>
<?php endif; ?>

<?php require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
