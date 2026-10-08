<?php
/**
 * Значения настроек модуля по умолчанию.
 *
 * Секреты (ключи API, токены приложения) хранятся в БД в зашифрованном виде
 * (см. Mtai\Aiagent\Secrets) и здесь намеренно пустые. Их можно задать
 * переменными окружения: MTAI_AIAGENT_LLM_API_KEY, MTAI_AIAGENT_APP_CLIENT_SECRET,
 * MTAI_AIAGENT_APP_REFRESH_TOKEN — переменные окружения приоритетнее БД.
 */

// Формат переменной обязателен для ядра (Option::getDefaults).
$mtai_aiagent_default_option = [
	// ---------- LLM ----------
	'llm_provider' => 'mock',            // openai | yandex | gigachat | mock
	'llm_base_url' => 'https://api.openai.com/v1',
	'llm_model' => 'gpt-4o-mini',
	'llm_api_key' => '',                 // зашифровано
	'llm_yandex_folder' => '',
	'llm_embedding_provider' => 'auto',  // auto | provider | hash | off
	'llm_embedding_model' => '',            // OpenAI: text-embedding-3-small; YandexGPT: text-search-query (пусто = дефолт провайдера)
	'llm_temperature' => '0.2',
	'llm_max_tokens' => '1500',
	'llm_timeout' => '45',
	'llm_ssl_verify' => 'Y',
	'gigachat_token_expires' => '0',  // кеш access-токена GigaChat (сам токен — секрет)
	'gigachat_scope' => 'GIGACHAT_API_PERS', // scope OAuth-запроса GigaChat (PERS/B2B/API)

	// ---------- RAG ----------
	'rag_top_k' => '5',
	'rag_min_score' => '0.20',           // порог косинусной близости 0..1
	'rag_chunk_size' => '1000',          // символов на фрагмент
	'rag_chunk_overlap' => '180',

	// ---------- Открытые линии ----------
	'ol_enabled' => 'N',
	'ol_lines' => '',                    // ID линий через запятую; пусто = все
	'ol_system_prompt' => "Ты — консультант компании и отвечаешь клиентам в чате открытой линии.\nОтвечай дружелюбно и по делу, на языке клиента.",
	'ol_handoff_text' => 'Передаю диалог живому оператору, он продолжит общение.',
	'ol_no_answer_escalate' => 'Y',      // нет релевантного контекста → оператор
	'ol_self_check' => 'N',               // second-opinion проверка ответа LLM перед отправкой
	'ol_create_lead' => 'Y',
	'ol_welcome_message' => '',          // ответ на первое сообщение сессии до RAG-поиска (пусто = отключено)
	'transfer_user_id' => '0',           // 0 = первый доступный оператор очереди; >0 = конкретный сотрудник
	'ol_work_hours' => '',               // «09:00-19:00»; пусто = круглосуточно
	'ol_work_days' => '1,2,3,4,5',       // ISO-дни (1=пн)
	'ol_offhours_text' => 'Сейчас нерабочее время. Передаю ваш вопрос оператору — ответим в ближайшее рабочее время.',

	// ---------- Чаты (CRM-агент) ----------
	'chat_enabled' => 'N',
	'chat_system_prompt' => "Ты — ассистент сотрудников портала Битрикс24. Выполняй действия в CRM и задачах через доступные инструменты: создавай и изменяйте лиды, сделки, контакты, компании, ставь задачи с привязкой к сущностям CRM.\nЕсли данных не хватает — задай один короткий уточняющий вопрос. Отвечай по-русски, кратко; после действия подтверждай, что именно сделано (название, сумма, номер).",
	'chat_allowed_users' => '',          // ID пользователей через запятую; пусто = все сотрудники
	'history_depth' => '12',             // сообщений контекста на диалог

	// ---------- REST-приложение и бот ----------
	// Авторизация: oauth — локальное приложение (client_id/secret/refresh),
	// webhook — входящий вебхук + botToken (для стендов без проверки лицензии).
	'rest_auth_mode' => 'oauth',
	'portal_url' => '',                  // https://bitrix.local — заполняется при установке
	'portal_ssl_verify' => 'N',          // самоподписанный сертификат стенда
	'app_client_id' => '',
	'app_client_secret' => '',           // зашифровано
	'app_refresh_token' => '',           // зашифровано
	'app_access_token' => '',            // зашифровано (кеш)
	'app_access_expires' => '0',
	'webhook_user_id' => '1',            // владелец входящего вебхука
	'webhook_password' => '',            // код вебхука, зашифровано; он же botToken
	'bot_code' => 'mtai.aiagent',
	'bot_name' => 'AI-агент',
	'bot_user_id' => '0',
	'event_offset' => '0',
	'last_fetch_time' => '',
	'last_process_time' => '',

	// ---------- Служебное ----------
	'log_level' => 'info',               // debug | info | warn | error
	'notify_admin_user' => '1',          // кому слать уведомления об ошибках
];
