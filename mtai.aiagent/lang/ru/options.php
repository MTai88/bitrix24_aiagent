<?php

$MESS['MTAI_AIAGENT_ACCESS_DENIED'] = 'Нет прав на управление модулем';

$MESS['MTAI_AIAGENT_TITLE'] = 'Настройки AI-агента';

$MESS['MTAI_AIAGENT_TAB_LLM'] = 'LLM';
$MESS['MTAI_AIAGENT_TAB_LLM_TITLE'] = 'Подключение языковой модели';
$MESS['MTAI_AIAGENT_TAB_MCP'] = 'MCP';
$MESS['MTAI_AIAGENT_TAB_MCP_TITLE'] = 'MCP-серверы: внешние инструменты для AI-агента';
$MESS['MTAI_AIAGENT_TAB_APP'] = 'Приложение и бот';
$MESS['MTAI_AIAGENT_TAB_APP_TITLE'] = 'Локальное REST-приложение и чат-бот imbot.v2';
$MESS['MTAI_AIAGENT_TAB_OL'] = 'Открытые линии';
$MESS['MTAI_AIAGENT_TAB_OL_TITLE'] = 'AI-агент открытых линий (RAG-ответы клиентам)';
$MESS['MTAI_AIAGENT_TAB_CHAT'] = 'Чаты';
$MESS['MTAI_AIAGENT_TAB_CHAT_TITLE'] = 'AI-агент чатов (действия в CRM)';
$MESS['MTAI_AIAGENT_TAB_RAG'] = 'Поиск (RAG)';
$MESS['MTAI_AIAGENT_TAB_RAG_TITLE'] = 'Параметры поиска по базе знаний';

$MESS['MTAI_AIAGENT_TAB_SVC'] = 'Служебное';
$MESS['MTAI_AIAGENT_TAB_SVC_TITLE'] = 'Журнал, уведомления и состояние конвейера';

$MESS['MTAI_AIAGENT_SAVED'] = 'Настройки сохранены.';
$MESS['MTAI_AIAGENT_SAVE'] = 'Сохранить';
$MESS['MTAI_AIAGENT_RESET'] = 'Сбросить';

$MESS['MTAI_AIAGENT_PROVIDER_MOCK'] = 'Мок (без внешних вызовов)';

$MESS['MTAI_AIAGENT_LLM_PROVIDER_HEAD'] = 'Языковая модель';
$MESS['MTAI_AIAGENT_LLM_PROVIDER'] = 'Провайдер';
$MESS['MTAI_AIAGENT_LLM_BASE_URL'] = 'Базовый URL API';
$MESS['MTAI_AIAGENT_LLM_MODEL'] = 'Модель';
$MESS['MTAI_AIAGENT_LLM_API_KEY'] = 'API-ключ';
$MESS['MTAI_AIAGENT_LLM_API_KEY_HINT'] = 'Хранится в БД зашифрованным; можно задать переменной окружения MTAI_AIAGENT_LLM_API_KEY (она приоритетнее). Для GigaChat сюда вставляется Authorization Key (Base64 из кабинета API keys) — access-токен модуль получает и обновляет сам.';
$MESS['MTAI_AIAGENT_GIGACHAT_SCOPE'] = 'Scope GigaChat (OAuth)';
$MESS['MTAI_AIAGENT_GIGACHAT_SCOPE_HINT'] = 'Код доступа, передаваемый при получении access-токена: GIGACHAT_API_PERS (персональный), GIGACHAT_API_B2B, GIGACHAT_API_CORP и т.п. — должен соответствовать выпущенному authorization key.';
$MESS['MTAI_AIAGENT_LLM_YANDEX_FOLDER'] = 'Каталог Yandex Cloud (folder id)';
$MESS['MTAI_AIAGENT_LLM_PARAMS'] = 'Параметры генерации';
$MESS['MTAI_AIAGENT_LLM_TEMPERATURE'] = 'Температура';
$MESS['MTAI_AIAGENT_LLM_MAX_TOKENS'] = 'Макс. токенов';
$MESS['MTAI_AIAGENT_LLM_TIMEOUT'] = 'Таймаут, сек';
$MESS['MTAI_AIAGENT_LLM_SSL'] = 'Проверять SSL-сертификат API';

$MESS['MTAI_AIAGENT_EMBED_HEAD'] = 'Эмбеддинги (векторизация базы знаний)';
$MESS['MTAI_AIAGENT_EMBED_PROVIDER'] = 'Режим';
$MESS['MTAI_AIAGENT_EMBED_AUTO'] = 'Авто (провайдер, иначе встроенный)';
$MESS['MTAI_AIAGENT_EMBED_PROVIDER_MODE'] = 'Только провайдер';
$MESS['MTAI_AIAGENT_EMBED_HASH'] = 'Встроенный лексический (без API)';
$MESS['MTAI_AIAGENT_EMBED_OFF'] = 'Отключить поиск';
$MESS['MTAI_AIAGENT_EMBED_MODEL'] = 'Модель эмбеддингов';

$MESS['MTAI_AIAGENT_SECRET_STORED'] = '● задан, введите новый для замены';
$MESS['MTAI_AIAGENT_SECRET_EMPTY'] = 'не задан';

$MESS['MTAI_AIAGENT_MCP_ENABLED'] = 'Включить MCP';
$MESS['MTAI_AIAGENT_MCP_ENABLED_HINT'] = 'Мастер-выключатель: гасит инструменты MCP в активностях бизнес-процессов. Транспорт — streamable HTTP (JSON-RPC 2.0), авторизация — Bearer API-ключ. Инструменты MCP доступны агенту в режиме «Выполнить задачу».';
$MESS['MTAI_AIAGENT_MCP_HEAD'] = 'Серверы';
$MESS['MTAI_AIAGENT_MCP_COL_NAME'] = 'Имя';
$MESS['MTAI_AIAGENT_MCP_COL_URL'] = 'URL (конечная точка MCP)';
$MESS['MTAI_AIAGENT_MCP_COL_KEY'] = 'API-ключ';
$MESS['MTAI_AIAGENT_MCP_COL_ENABLED'] = 'Вкл.';
$MESS['MTAI_AIAGENT_MCP_COL_DELETE'] = 'Удалить';
$MESS['MTAI_AIAGENT_MCP_ADD'] = 'Добавить сервер';
$MESS['MTAI_AIAGENT_MCP_KEY_HINT'] = 'API-ключ отправляется заголовком Authorization: Bearer. Ключ хранится в БД зашифрованным; пустое поле при сохранении сохранённое значение не затирает. Имя лучше задавать латиницей — из него строятся имена инструментов mcp_{имя}_{инструмент}.';
$MESS['MTAI_AIAGENT_MCP_TEST'] = 'Проверка подключения';
$MESS['MTAI_AIAGENT_MCP_TEST_BTN'] = 'Проверить серверы';
$MESS['MTAI_AIAGENT_MCP_TEST_HINT'] = 'Handshake и количество инструментов по каждому включённому серверу. Проверяются сохранённые настройки, а не данные формы.';
$MESS['MTAI_AIAGENT_MCP_NO_SERVERS'] = 'Нет включённых серверов MCP.';
$MESS['MTAI_AIAGENT_MCP_TOOLS_COUNT'] = '%d инструментов';
$MESS['MTAI_AIAGENT_MCP_FAIL'] = 'ошибка:';

$MESS['MTAI_AIAGENT_APP_HEAD'] = 'Локальное REST-приложение';
$MESS['MTAI_AIAGENT_AUTH_MODE'] = 'Режим авторизации';
$MESS['MTAI_AIAGENT_AUTH_OAUTH'] = 'Локальное приложение (OAuth)';
$MESS['MTAI_AIAGENT_AUTH_WEBHOOK'] = 'Входящий вебхук + botToken';
$MESS['MTAI_AIAGENT_WEBHOOK_USER'] = 'Владелец вебхука (ID пользователя)';
$MESS['MTAI_AIAGENT_WEBHOOK_PASSWORD'] = 'Код вебхука (botToken)';
$MESS['MTAI_AIAGENT_WEBHOOK_PASSWORD_HINT'] = 'Хвост URL входящего вебхука: /rest/{ID пользователя}/{код}/. В этом режиме бот регистрируется с botToken и тем же токеном подписывает вызовы imbot.v2 / imopenlines (CLIENT_ID). Подходит стендам без проверки лицензии (OAuth-авторизация на box требует связи с контроллером Битрикса).';
$MESS['MTAI_AIAGENT_APP_PORTAL_URL'] = 'Адрес портала';
$MESS['MTAI_AIAGENT_APP_CLIENT_ID'] = 'Client ID';
$MESS['MTAI_AIAGENT_APP_CLIENT_SECRET'] = 'Client Secret';
$MESS['MTAI_AIAGENT_APP_REFRESH'] = 'Refresh-токен';
$MESS['MTAI_AIAGENT_APP_REFRESH_HINT'] = 'Получается одноразово при авторизации приложения (см. README модуля, раздел «Установка»). Ротируется автоматически: модуль всегда сохраняет свежий.';
$MESS['MTAI_AIAGENT_APP_STATUS'] = 'Состояние связи';
$MESS['MTAI_AIAGENT_APP_NOT_CONFIGURED'] = 'Не настроено: заполните адрес портала, Client ID, Client Secret и Refresh-токен.';
$MESS['MTAI_AIAGENT_APP_OK'] = 'Соединение установлено, токен получен.';
$MESS['MTAI_AIAGENT_APP_FAIL'] = 'Ошибка соединения';
$MESS['MTAI_AIAGENT_APP_TEST_HINT'] = 'Креды заданы. Нажмите «Проверить соединение» — статус обновится (при рендере страницы портал не опрашивается, чтобы не блокировать её).';
$MESS['MTAI_AIAGENT_APP_TEST'] = 'Проверить соединение';

$MESS['MTAI_AIAGENT_BOT_HEAD'] = 'Чат-бот (imbot.v2)';
$MESS['MTAI_AIAGENT_BOT_CODE'] = 'Код бота';
$MESS['MTAI_AIAGENT_BOT_NAME'] = 'Имя бота';
$MESS['MTAI_AIAGENT_BOT_STATUS'] = 'Состояние';
$MESS['MTAI_AIAGENT_BOT_NOT_REGISTERED'] = 'Бот не зарегистрирован.';
$MESS['MTAI_AIAGENT_BOT_REGISTERED_OK'] = 'Зарегистрирован';
$MESS['MTAI_AIAGENT_BOT_DO_REGISTER'] = 'Зарегистрировать';
$MESS['MTAI_AIAGENT_BOT_DO_UPDATE'] = 'Обновить';
$MESS['MTAI_AIAGENT_BOT_DO_UNREGISTER'] = 'Удалить';
$MESS['MTAI_AIAGENT_BOT_DO_TEST'] = 'Проверить бота тестовым сообщением';
$MESS['MTAI_AIAGENT_BOT_TEST_OK'] = 'Тестовое сообщение отправлено: ответ бота уже должен быть в чате (владельца вебхука).';
$MESS['MTAI_AIAGENT_BOT_TEST_TEXT'] = 'Тест сообщения AI-агенту: сколько стоит Продукт-360?';
$MESS['MTAI_AIAGENT_BOT_CONFIRM'] = 'Удалить бота? Диалоги останутся, бот перестанет отвечать.';
$MESS['MTAI_AIAGENT_BOT_HINT'] = 'Режим доставки событий: fetch (imbot.v2.Event.get), поддержка открытых линий включена.';
$MESS['MTAI_AIAGENT_BOT_REGISTERED'] = 'Бот зарегистрирован';
$MESS['MTAI_AIAGENT_BOT_UPDATED'] = 'Бот обновлён';
$MESS['MTAI_AIAGENT_BOT_UNREGISTERED'] = 'Бот удалён';
$MESS['MTAI_AIAGENT_REST_OK'] = 'REST-приложение работает: получен свежий access-токен.';
$MESS['MTAI_AIAGENT_ACTION_ERROR'] = 'Ошибка';

$MESS['MTAI_AIAGENT_OL_ENABLED'] = 'Включить агента открытых линий';
$MESS['MTAI_AIAGENT_OL_LINES'] = 'Линии (ID через запятую)';
$MESS['MTAI_AIAGENT_OL_LINES_HINT'] = 'Пусто = все линии, в очередь которых добавлен бот. ID видны в адресе страницы редактирования линии.';
$MESS['MTAI_AIAGENT_OL_PROMPT'] = 'Системный промт (роль, правила)';
$MESS['MTAI_AIAGENT_OL_HANDOFF'] = 'Сообщение при передаче оператору';
$MESS['MTAI_AIAGENT_OL_ESCALATE'] = 'Передавать оператору, если в базе нет релевантного контекста';
$MESS['MTAI_AIAGENT_OL_SELFCHECK'] = 'Контроль качества ответа (self-check)';
$MESS['MTAI_AIAGENT_OL_SELFCHECK_HINT'] = 'Перед отправкой клиенту второй запрос LLM проверяет, что ответ подтверждается фрагментами базы знаний; выдумка — диалог оператору. Не работает с провайдером «Мок».';
$MESS['MTAI_AIAGENT_OL_LEAD'] = 'Создавать лид из диалога без привязок CRM';
$MESS['MTAI_AIAGENT_OL_WELCOME'] = 'Приветствие (на первое сообщение сессии)';
$MESS['MTAI_AIAGENT_OL_TRANSFER_USER'] = 'Передавать конкретному сотруднику (ID)';
$MESS['MTAI_AIAGENT_OL_WORK_HOURS'] = 'Рабочие часы агента';
$MESS['MTAI_AIAGENT_OL_WORK_DAYS'] = 'дни (1=пн)';
$MESS['MTAI_AIAGENT_OL_WORK_HOURS_HINT'] = 'Формат «ЧЧ:ММ-ЧЧ:ММ»; пусто = круглосуточно. Вне окна и в выходные клиент получает сообщение ниже, а диалог передаётся оператору.';
$MESS['MTAI_AIAGENT_OL_OFFHOURS_TEXT'] = 'Сообщение вне рабочих часов';

$MESS['MTAI_AIAGENT_OL_TRANSFER_USER_HINT'] = '0 = первый доступный оператор очереди (imopenlines.bot.session.operator); иначе imopenlines.bot.session.transfer на сотрудника.';

$MESS['MTAI_AIAGENT_CHAT_ENABLED'] = 'Включить агента чатов';
$MESS['MTAI_AIAGENT_CHAT_PROMPT'] = 'Системный промт агента CRM';
$MESS['MTAI_AIAGENT_CHAT_ALLOWED'] = 'Разрешённые пользователи (ID)';
$MESS['MTAI_AIAGENT_CHAT_ALLOWED_HINT'] = 'Через запятую; пусто = все сотрудники могут писать боту.';
$MESS['MTAI_AIAGENT_HISTORY'] = 'Глубина истории (сообщений)';

$MESS['MTAI_AIAGENT_RAG_TOPK'] = 'Фрагментов в контексте (top-K)';
$MESS['MTAI_AIAGENT_RAG_MINSCORE'] = 'Порог релевантности (0..1)';
$MESS['MTAI_AIAGENT_RAG_MINSCORE_HINT'] = 'Косинусная близость. Для встроенного лексического эмбеддера ориентируйтесь на 0.15–0.25, для нейросетевых — 0.3–0.45.';
$MESS['MTAI_AIAGENT_RAG_CHUNK'] = 'Чанкование';
$MESS['MTAI_AIAGENT_RAG_CHUNK_SIZE'] = 'размер, символов';
$MESS['MTAI_AIAGENT_RAG_CHUNK_OVERLAP'] = 'перекрытие';
$MESS['MTAI_AIAGENT_RAG_KB'] = 'Документы';
$MESS['MTAI_AIAGENT_RAG_KB_LINK'] = 'Перейти к базе знаний';

$MESS['MTAI_AIAGENT_LOG_LEVEL'] = 'Уровень журнала';
$MESS['MTAI_AIAGENT_NOTIFY_USER'] = 'Уведомлять пользователя (ID) об ошибках';
$MESS['MTAI_AIAGENT_LAST_FETCH'] = 'Последний опрос событий';
$MESS['MTAI_AIAGENT_LAST_PROCESS'] = 'Последняя обработка очереди';
$MESS['MTAI_AIAGENT_LOGS_LINK'] = 'Журнал';
$MESS['MTAI_AIAGENT_LOGS_LINK_TEXT'] = 'Открыть журнал модуля';
