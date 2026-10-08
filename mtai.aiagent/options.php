<?php
/**
 * Страница настроек модуля mtai.aiagent.
 */

use Bitrix\Main\Localization\Loc;
use Mtai\Aiagent\Bot\Manager;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Rest\Client;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

Loc::loadMessages(__FILE__);

$moduleId = 'mtai.aiagent';
if ($APPLICATION->GetGroupRight($moduleId) < 'W')
{
	$APPLICATION->AuthForm(GetMessage('MTAI_AIAGENT_ACCESS_DENIED'));
}

\Bitrix\Main\Loader::includeModule($moduleId);

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$postAction = (string)($_POST['mtai_action'] ?? '');

$tabControl = new CAdminTabControl('mtaiAiagentTabs', [
	['DIV' => 'tab_llm', 'TAB' => GetMessage('MTAI_AIAGENT_TAB_LLM'), 'TITLE' => GetMessage('MTAI_AIAGENT_TAB_LLM_TITLE')],
	['DIV' => 'tab_app', 'TAB' => GetMessage('MTAI_AIAGENT_TAB_APP'), 'TITLE' => GetMessage('MTAI_AIAGENT_TAB_APP_TITLE')],
	['DIV' => 'tab_ol', 'TAB' => GetMessage('MTAI_AIAGENT_TAB_OL'), 'TITLE' => GetMessage('MTAI_AIAGENT_TAB_OL_TITLE')],
	['DIV' => 'tab_chat', 'TAB' => GetMessage('MTAI_AIAGENT_TAB_CHAT'), 'TITLE' => GetMessage('MTAI_AIAGENT_TAB_CHAT_TITLE')],
	['DIV' => 'tab_rag', 'TAB' => GetMessage('MTAI_AIAGENT_TAB_RAG'), 'TITLE' => GetMessage('MTAI_AIAGENT_TAB_RAG_TITLE')],
	['DIV' => 'tab_svc', 'TAB' => GetMessage('MTAI_AIAGENT_TAB_SVC'), 'TITLE' => GetMessage('MTAI_AIAGENT_TAB_SVC_TITLE')],
]);

// ---------------------------------------------------------------------
// Действия по кнопкам (проверка соединения, регистрация бота)
// ---------------------------------------------------------------------
if ($isPost && check_bitrix_sessid() && $postAction !== '' && $postAction !== 'save')
{
	try
	{
		switch ($postAction)
		{
			case 'rest_test':
				(new Client())->refreshAccessToken();
				$message = ['OK', GetMessage('MTAI_AIAGENT_REST_OK')];
				break;
			case 'bot_register':
				$botId = (new Manager())->register();
				$message = ['OK', GetMessage('MTAI_AIAGENT_BOT_REGISTERED') . " (botId={$botId})"];
				break;
			case 'bot_update':
				(new Manager())->update();
				$message = ['OK', GetMessage('MTAI_AIAGENT_BOT_UPDATED')];
				break;
			case 'bot_unregister':
				(new Manager())->unregister();
				$message = ['OK', GetMessage('MTAI_AIAGENT_BOT_UNREGISTERED')];
				break;
			case 'bot_test':
				$botId = Manager::botUserId();
				if ($botId <= 0)
				{
					throw new RuntimeException(GetMessage('MTAI_AIAGENT_BOT_NOT_REGISTERED'));
				}
				(new Client())->call('im.message.add', [
					'DIALOG_ID' => (string)$botId,
					'MESSAGE' => GetMessage('MTAI_AIAGENT_BOT_TEST_TEXT'),
				]);
				// Полный цикл сразу: забрать событие и обработать — ответ бота
				// появится в чате мгновенно, без ожидания агентов.
				(new \Mtai\Aiagent\Event\Fetcher())->tick();
				(new \Mtai\Aiagent\Queue\Processor())->tick();
				$message = ['OK', GetMessage('MTAI_AIAGENT_BOT_TEST_OK')];
				break;
			default:
				$message = null;
		}
	}
	catch (\Throwable $e)
	{
		$message = ['ERROR', GetMessage('MTAI_AIAGENT_ACTION_ERROR') . ': ' . $e->getMessage()];
	}

	LocalRedirect(
		$APPLICATION->GetCurPage() . '?mid=' . $moduleId . '&lang=' . LANG
		. '&mtai_msg=' . urlencode($message[0] . '|' . $message[1])
		. '&' . $tabControl->ActiveTabParam()
	);
}

// ---------------------------------------------------------------------
// Сохранение настроек
// ---------------------------------------------------------------------
if ($isPost && check_bitrix_sessid() && $postAction === 'save')
{
	$textFields = [
		'llm_provider', 'llm_base_url', 'llm_model', 'llm_yandex_folder',
		'llm_embedding_provider', 'llm_embedding_model', 'llm_temperature',
		'llm_max_tokens', 'llm_timeout',
		'rag_top_k', 'rag_min_score', 'rag_chunk_size', 'rag_chunk_overlap',
		'ol_lines', 'ol_system_prompt', 'ol_handoff_text', 'ol_welcome_message',
		'transfer_user_id', 'ol_work_hours', 'ol_work_days', 'ol_offhours_text',
		'chat_system_prompt', 'chat_allowed_users', 'history_depth',
		'portal_url', 'app_client_id', 'bot_code', 'bot_name',
		'rest_auth_mode', 'webhook_user_id', 'gigachat_scope',
		'log_level', 'notify_admin_user',
	];

	foreach ($textFields as $field)
	{
		if (isset($_POST[$field]))
		{
			Config::set($field, trim((string)$_POST[$field]));
		}
	}

	// Чекбоксы: отсутствующий в POST = N.
	foreach (['ol_enabled', 'ol_no_answer_escalate', 'ol_self_check', 'ol_create_lead', 'chat_enabled', 'llm_ssl_verify'] as $flag)
	{
		Config::set($flag, isset($_POST[$flag]) ? 'Y' : 'N');
	}

	// Секреты: пустое поле в POST не затирает сохранённое значение.
	foreach (['llm_api_key', 'app_client_secret', 'app_refresh_token', 'webhook_password'] as $secret)
	{
		if (!empty($_POST[$secret]))
		{
			Config::set($secret, trim((string)$_POST[$secret]));
		}
	}

	// Подстановка базового URL по умолчанию для GigaChat.
	if (Config::get('llm_provider') === 'gigachat')
	{
		$baseUrl = Config::get('llm_base_url', '');
		if ($baseUrl === '' || $baseUrl === 'https://api.openai.com/v1')
		{
			Config::set('llm_base_url', \Mtai\Aiagent\Llm\GigaChatProvider::DEFAULT_BASE_URL);
		}
	}

	if (Config::get('portal_url', '') === '')
	{
		Config::set('portal_url', Config::detectPortalUrl());
	}

	LocalRedirect(
		$APPLICATION->GetCurPage() . '?mid=' . $moduleId . '&lang=' . LANG
		. '&mtai_msg=' . urlencode('OK|' . GetMessage('MTAI_AIAGENT_SAVED'))
		. '&' . $tabControl->ActiveTabParam()
	);
}

// ---------------------------------------------------------------------
// Подготовка данных формы
// ---------------------------------------------------------------------
$llmKeySet = Config::getSecret('llm_api_key') !== '';
$secretSet = Config::getSecret('app_client_secret') !== '';
$refreshSet = Config::getSecret('app_refresh_token') !== '';
$webhookSet = Config::getSecret('webhook_password') !== '';

$appOk = null;
// ВАЖНО: при рендере страницы НЕ проверяем связь с порталом — синхронный
// REST-вызов самого себя из запроса страницы при занятом пуле FPM
// превращается в самоблокировку (страница ждёт ответа от себя).
// Статус обновляется только явно: кнопка «Проверить соединение»
// или «Зарегистрировать» (POST + редирект).
$botStatus = null;

$APPLICATION->SetTitle(GetMessage('MTAI_AIAGENT_TITLE'));

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

if (!empty($_GET['mtai_msg']))
{
	[$msgType, $msgText] = array_pad(explode('|', (string)$_GET['mtai_msg'], 2), 2, '');
	echo (new CAdminMessage([
		'MESSAGE' => $msgText,
		'TYPE' => $msgType === 'OK' ? 'OK' : 'ERROR',
	]))->Show();
}

$tabControl->Begin();
?>
<form method="POST" action="<?= $APPLICATION->GetCurPageParam('mid=' . $moduleId . '&lang=' . LANG) ?>" name="mtai_aiagent_settings">
	<?= bitrix_sessid_post() ?>
	<input type="hidden" name="mid" value="<?= htmlspecialcharsbx($moduleId) ?>">
	<input type="hidden" name="lang" value="<?= LANG ?>">
	<input type="hidden" name="mtai_action" value="save">

	<?php $tabControl->BeginNextTab(); ?>

	<tr class="heading">
		<td colspan="2"><?= GetMessage('MTAI_AIAGENT_LLM_PROVIDER_HEAD') ?></td>
	</tr>
	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_LLM_PROVIDER') ?></td>
		<td>
			<select name="llm_provider" onchange="document.getElementById('tr_gigachat_scope').style.display=(this.value==='gigachat'?'':'none');document.getElementById('tr_yandex_folder').style.display=(this.value==='yandex'?'':'none')">
				<?php
				$titles = [
					'openai' => 'OpenAI / OpenAI-совместимый',
					'yandex' => 'YandexGPT',
					'gigachat' => 'GigaChat',
					'mock' => GetMessage('MTAI_AIAGENT_PROVIDER_MOCK'),
				];
				$current = Config::get('llm_provider', 'mock');
				foreach ($titles as $code => $title):
					?>
					<option value="<?= $code ?>" <?= $current === $code ? 'selected' : '' ?>><?= $title ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LLM_BASE_URL') ?></td>
		<td><input type="text" size="50" name="llm_base_url" value="<?= htmlspecialcharsbx(Config::get('llm_base_url')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LLM_MODEL') ?></td>
		<td><input type="text" size="50" name="llm_model" value="<?= htmlspecialcharsbx(Config::get('llm_model')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LLM_API_KEY') ?></td>
		<td>
			<input type="password" size="50" name="llm_api_key" autocomplete="new-password"
				   placeholder="<?= $llmKeySet ? GetMessage('MTAI_AIAGENT_SECRET_STORED') : GetMessage('MTAI_AIAGENT_SECRET_EMPTY') ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_LLM_API_KEY_HINT') ?></div>
		</td>
	</tr>
	<tr id="tr_yandex_folder" style="display:<?= Config::get('llm_provider') === 'yandex' ? '' : 'none' ?>">
		<td><?= GetMessage('MTAI_AIAGENT_LLM_YANDEX_FOLDER') ?></td>
		<td><input type="text" size="30" name="llm_yandex_folder" value="<?= htmlspecialcharsbx(Config::get('llm_yandex_folder')) ?>"></td>
	</tr>
	<tr id="tr_gigachat_scope" style="display:<?= Config::get('llm_provider') === 'gigachat' ? '' : 'none' ?>">
		<td><?= GetMessage('MTAI_AIAGENT_GIGACHAT_SCOPE') ?></td>
		<td>
			<input type="text" size="30" name="gigachat_scope" value="<?= htmlspecialcharsbx(Config::get('gigachat_scope', \Mtai\Aiagent\Llm\GigaChatProvider::DEFAULT_SCOPE)) ?>" placeholder="<?= \Mtai\Aiagent\Llm\GigaChatProvider::DEFAULT_SCOPE ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_GIGACHAT_SCOPE_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LLM_PARAMS') ?></td>
		<td>
			<?= GetMessage('MTAI_AIAGENT_LLM_TEMPERATURE') ?>:
			<input type="text" size="5" name="llm_temperature" value="<?= htmlspecialcharsbx(Config::get('llm_temperature')) ?>">
			&nbsp; <?= GetMessage('MTAI_AIAGENT_LLM_MAX_TOKENS') ?>:
			<input type="text" size="7" name="llm_max_tokens" value="<?= htmlspecialcharsbx(Config::get('llm_max_tokens')) ?>">
			&nbsp; <?= GetMessage('MTAI_AIAGENT_LLM_TIMEOUT') ?>:
			<input type="text" size="5" name="llm_timeout" value="<?= htmlspecialcharsbx(Config::get('llm_timeout')) ?>">
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LLM_SSL') ?></td>
		<td><input type="checkbox" name="llm_ssl_verify" value="Y" <?= Config::isEnabled('llm_ssl_verify') ? 'checked' : '' ?>></td>
	</tr>

	<tr class="heading">
		<td colspan="2"><?= GetMessage('MTAI_AIAGENT_EMBED_HEAD') ?></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_EMBED_PROVIDER') ?></td>
		<td>
			<select name="llm_embedding_provider">
				<?php
				$modes = [
					'auto' => GetMessage('MTAI_AIAGENT_EMBED_AUTO'),
					'provider' => GetMessage('MTAI_AIAGENT_EMBED_PROVIDER_MODE'),
					'hash' => GetMessage('MTAI_AIAGENT_EMBED_HASH'),
					'off' => GetMessage('MTAI_AIAGENT_EMBED_OFF'),
				];
				$embedMode = Config::get('llm_embedding_provider', 'auto');
				foreach ($modes as $modeCode => $modeTitle):
					?>
					<option value="<?= $modeCode ?>" <?= $embedMode === $modeCode ? 'selected' : '' ?>><?= $modeTitle ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_EMBED_MODEL') ?></td>
		<td><input type="text" size="50" name="llm_embedding_model" value="<?= htmlspecialcharsbx(Config::get('llm_embedding_model')) ?>"></td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>

	<tr class="heading">
		<td colspan="2"><?= GetMessage('MTAI_AIAGENT_APP_HEAD') ?></td>
	</tr>
	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_AUTH_MODE') ?></td>
		<td>
			<select name="rest_auth_mode">
				<option value="oauth" <?= Config::get('rest_auth_mode', 'oauth') === 'oauth' ? 'selected' : '' ?>>
					<?= GetMessage('MTAI_AIAGENT_AUTH_OAUTH') ?>
				</option>
				<option value="webhook" <?= Config::get('rest_auth_mode') === 'webhook' ? 'selected' : '' ?>>
					<?= GetMessage('MTAI_AIAGENT_AUTH_WEBHOOK') ?>
				</option>
			</select>
		</td>
	</tr>
	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_APP_PORTAL_URL') ?></td>
		<td><input type="text" size="50" name="portal_url" value="<?= htmlspecialcharsbx(Config::get('portal_url')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_WEBHOOK_USER') ?></td>
		<td><input type="text" size="5" name="webhook_user_id" value="<?= htmlspecialcharsbx(Config::get('webhook_user_id')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_WEBHOOK_PASSWORD') ?></td>
		<td>
			<input type="password" size="40" name="webhook_password" autocomplete="new-password"
				   placeholder="<?= $webhookSet ? GetMessage('MTAI_AIAGENT_SECRET_STORED') : GetMessage('MTAI_AIAGENT_SECRET_EMPTY') ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_WEBHOOK_PASSWORD_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_APP_CLIENT_ID') ?></td>
		<td><input type="text" size="40" name="app_client_id" value="<?= htmlspecialcharsbx(Config::get('app_client_id')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_APP_CLIENT_SECRET') ?></td>
		<td>
			<input type="password" size="60" name="app_client_secret" autocomplete="new-password"
				   placeholder="<?= $secretSet ? GetMessage('MTAI_AIAGENT_SECRET_STORED') : GetMessage('MTAI_AIAGENT_SECRET_EMPTY') ?>">
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_APP_REFRESH') ?></td>
		<td>
			<input type="password" size="60" name="app_refresh_token" autocomplete="new-password"
				   placeholder="<?= $refreshSet ? GetMessage('MTAI_AIAGENT_SECRET_STORED') : GetMessage('MTAI_AIAGENT_SECRET_EMPTY') ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_APP_REFRESH_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_APP_STATUS') ?></td>
		<td>
			<?php if (!Client::isConfigured()): ?>
				<span style="color:#c00"><?= GetMessage('MTAI_AIAGENT_APP_NOT_CONFIGURED') ?></span>
			<?php else: ?>
				<?= GetMessage('MTAI_AIAGENT_APP_TEST_HINT') ?>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<td></td>
		<td>
			<button type="submit" name="rest_test_btn" value="Y"
					onclick="this.form.mtai_action.value='rest_test'; this.form.submit(); return false;">
				<?= GetMessage('MTAI_AIAGENT_APP_TEST') ?>
			</button>
		</td>
	</tr>

	<tr class="heading">
		<td colspan="2"><?= GetMessage('MTAI_AIAGENT_BOT_HEAD') ?></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_BOT_CODE') ?></td>
		<td><input type="text" size="30" name="bot_code" value="<?= htmlspecialcharsbx(Config::get('bot_code')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_BOT_NAME') ?></td>
		<td><input type="text" size="30" name="bot_name" value="<?= htmlspecialcharsbx(Config::get('bot_name')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_BOT_STATUS') ?></td>
		<td>
			<?php if (Manager::botUserId() <= 0): ?>
				<?= GetMessage('MTAI_AIAGENT_BOT_NOT_REGISTERED') ?>
			<?php else: ?>
				<span style="color:#070">
					<?= GetMessage('MTAI_AIAGENT_BOT_REGISTERED_OK') ?>:
					id=<?= Manager::botUserId() ?>
				</span>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<td></td>
		<td>
			<button type="submit" name="bot_register_btn" value="Y"
					onclick="this.form.mtai_action.value='bot_register'; this.form.submit(); return false;">
				<?= GetMessage('MTAI_AIAGENT_BOT_DO_REGISTER') ?>
			</button>
			<button type="submit" name="bot_update_btn" value="Y"
					onclick="this.form.mtai_action.value='bot_update'; this.form.submit(); return false;">
				<?= GetMessage('MTAI_AIAGENT_BOT_DO_UPDATE') ?>
			</button>
			<button type="submit" name="bot_unregister_btn" value="Y"
					onclick="return confirm('<?= CUtil::JSEscape(GetMessage('MTAI_AIAGENT_BOT_CONFIRM')) ?>') ? (this.form.mtai_action.value='bot_unregister', this.form.submit(), false) : false;">
				<?= GetMessage('MTAI_AIAGENT_BOT_DO_UNREGISTER') ?>
			</button>
			<button type="submit" name="bot_test_btn" value="Y"
					onclick="this.form.mtai_action.value='bot_test'; this.form.submit(); return false;">
				<?= GetMessage('MTAI_AIAGENT_BOT_DO_TEST') ?>
			</button>
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_BOT_HINT') ?></div>
		</td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>

	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_OL_ENABLED') ?></td>
		<td><input type="checkbox" name="ol_enabled" value="Y" <?= Config::isEnabled('ol_enabled') ? 'checked' : '' ?>></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_LINES') ?></td>
		<td>
			<input type="text" size="30" name="ol_lines" value="<?= htmlspecialcharsbx(Config::get('ol_lines')) ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_OL_LINES_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_PROMPT') ?></td>
		<td><textarea name="ol_system_prompt" rows="8" cols="60"><?= htmlspecialcharsbx(Config::get('ol_system_prompt')) ?></textarea></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_HANDOFF') ?></td>
		<td><input type="text" size="60" name="ol_handoff_text" value="<?= htmlspecialcharsbx(Config::get('ol_handoff_text')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_ESCALATE') ?></td>
		<td><input type="checkbox" name="ol_no_answer_escalate" value="Y" <?= Config::isEnabled('ol_no_answer_escalate') ? 'checked' : '' ?>></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_SELFCHECK') ?></td>
		<td>
			<input type="checkbox" name="ol_self_check" value="Y" <?= Config::isEnabled('ol_self_check') ? 'checked' : '' ?>>
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_OL_SELFCHECK_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_LEAD') ?></td>
		<td><input type="checkbox" name="ol_create_lead" value="Y" <?= Config::isEnabled('ol_create_lead') ? 'checked' : '' ?>></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_WELCOME') ?></td>
		<td><input type="text" size="60" name="ol_welcome_message" value="<?= htmlspecialcharsbx(Config::get('ol_welcome_message')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_TRANSFER_USER') ?></td>
		<td>
			<input type="text" size="10" name="transfer_user_id" value="<?= htmlspecialcharsbx(Config::get('transfer_user_id')) ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_OL_TRANSFER_USER_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_WORK_HOURS') ?></td>
		<td>
			<input type="text" size="12" name="ol_work_hours" value="<?= htmlspecialcharsbx(Config::get('ol_work_hours')) ?>" placeholder="09:00-19:00">
			&nbsp; <?= GetMessage('MTAI_AIAGENT_OL_WORK_DAYS') ?>:
			<input type="text" size="12" name="ol_work_days" value="<?= htmlspecialcharsbx(Config::get('ol_work_days')) ?>" placeholder="1,2,3,4,5">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_OL_WORK_HOURS_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_OL_OFFHOURS_TEXT') ?></td>
		<td><input type="text" size="60" name="ol_offhours_text" value="<?= htmlspecialcharsbx(Config::get('ol_offhours_text')) ?>"></td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>

	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_CHAT_ENABLED') ?></td>
		<td><input type="checkbox" name="chat_enabled" value="Y" <?= Config::isEnabled('chat_enabled') ? 'checked' : '' ?>></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_CHAT_PROMPT') ?></td>
		<td><textarea name="chat_system_prompt" rows="8" cols="60"><?= htmlspecialcharsbx(Config::get('chat_system_prompt')) ?></textarea></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_CHAT_ALLOWED') ?></td>
		<td>
			<input type="text" size="40" name="chat_allowed_users" value="<?= htmlspecialcharsbx(Config::get('chat_allowed_users')) ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_CHAT_ALLOWED_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_HISTORY') ?></td>
		<td><input type="text" size="5" name="history_depth" value="<?= htmlspecialcharsbx(Config::get('history_depth')) ?>"></td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>

	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_RAG_TOPK') ?></td>
		<td><input type="text" size="5" name="rag_top_k" value="<?= htmlspecialcharsbx(Config::get('rag_top_k')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_RAG_MINSCORE') ?></td>
		<td>
			<input type="text" size="7" name="rag_min_score" value="<?= htmlspecialcharsbx(Config::get('rag_min_score')) ?>">
			<div class="adm-option-comment"><?= GetMessage('MTAI_AIAGENT_RAG_MINSCORE_HINT') ?></div>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_RAG_CHUNK') ?></td>
		<td>
			<?= GetMessage('MTAI_AIAGENT_RAG_CHUNK_SIZE') ?>:
			<input type="text" size="6" name="rag_chunk_size" value="<?= htmlspecialcharsbx(Config::get('rag_chunk_size')) ?>">
			&nbsp; <?= GetMessage('MTAI_AIAGENT_RAG_CHUNK_OVERLAP') ?>:
			<input type="text" size="6" name="rag_chunk_overlap" value="<?= htmlspecialcharsbx(Config::get('rag_chunk_overlap')) ?>">
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_RAG_KB') ?></td>
		<td><a href="/bitrix/admin/mtai_aiagent_kb.php?lang=<?= LANG ?>"><?= GetMessage('MTAI_AIAGENT_RAG_KB_LINK') ?></a></td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>

	<tr>
		<td width="40%"><?= GetMessage('MTAI_AIAGENT_LOG_LEVEL') ?></td>
		<td>
			<select name="log_level">
				<?php foreach (['debug', 'info', 'warn', 'error'] as $level): ?>
					<option value="<?= $level ?>" <?= Config::get('log_level') === $level ? 'selected' : '' ?>><?= $level ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_NOTIFY_USER') ?></td>
		<td><input type="text" size="5" name="notify_admin_user" value="<?= htmlspecialcharsbx(Config::get('notify_admin_user')) ?>"></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LAST_FETCH') ?></td>
		<td><?= htmlspecialcharsbx(Config::get('last_fetch_time') ?: '—') ?> (offset <?= (int)Config::get('event_offset') ?>)</td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LAST_PROCESS') ?></td>
		<td><?= htmlspecialcharsbx(Config::get('last_process_time') ?: '—') ?></td>
	</tr>
	<tr>
		<td><?= GetMessage('MTAI_AIAGENT_LOGS_LINK') ?></td>
		<td><a href="/bitrix/admin/mtai_aiagent_logs.php?lang=<?= LANG ?>"><?= GetMessage('MTAI_AIAGENT_LOGS_LINK_TEXT') ?></a></td>
	</tr>

	<?php $tabControl->Buttons(['disabled' => false]); ?>
	<input type="submit" name="save_btn" value="<?= GetMessage('MTAI_AIAGENT_SAVE') ?>">
	<input type="reset" value="<?= GetMessage('MTAI_AIAGENT_RESET') ?>">
	<?php $tabControl->End(); ?>
</form>
<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
