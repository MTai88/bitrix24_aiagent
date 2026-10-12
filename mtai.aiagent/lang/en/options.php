<?php

$MESS['MTAI_AIAGENT_ACCESS_DENIED'] = 'Insufficient rights to manage the module';

$MESS['MTAI_AIAGENT_TITLE'] = 'AI Agent settings';

$MESS['MTAI_AIAGENT_TAB_LLM'] = 'LLM';
$MESS['MTAI_AIAGENT_TAB_LLM_TITLE'] = 'Language model connection';
$MESS['MTAI_AIAGENT_TAB_MCP'] = 'MCP';
$MESS['MTAI_AIAGENT_TAB_MCP_TITLE'] = 'MCP servers: external tools for the AI agent';
$MESS['MTAI_AIAGENT_TAB_APP'] = 'App & bot';
$MESS['MTAI_AIAGENT_TAB_APP_TITLE'] = 'Local REST application and imbot.v2 chat bot';
$MESS['MTAI_AIAGENT_TAB_OL'] = 'Open lines';
$MESS['MTAI_AIAGENT_TAB_OL_TITLE'] = 'Open lines AI agent (RAG answers)';
$MESS['MTAI_AIAGENT_TAB_CHAT'] = 'Chats';
$MESS['MTAI_AIAGENT_TAB_CHAT_TITLE'] = 'Chats AI agent (CRM actions)';
$MESS['MTAI_AIAGENT_TAB_RAG'] = 'Search (RAG)';
$MESS['MTAI_AIAGENT_TAB_RAG_TITLE'] = 'Knowledge base search parameters';

$MESS['MTAI_AIAGENT_TAB_SVC'] = 'Service';
$MESS['MTAI_AIAGENT_TAB_SVC_TITLE'] = 'Log, notifications and pipeline status';

$MESS['MTAI_AIAGENT_SAVED'] = 'Settings saved.';
$MESS['MTAI_AIAGENT_SAVE'] = 'Save';
$MESS['MTAI_AIAGENT_RESET'] = 'Reset';

$MESS['MTAI_AIAGENT_PROVIDER_MOCK'] = 'Mock (no external calls)';

$MESS['MTAI_AIAGENT_LLM_PROVIDER_HEAD'] = 'Language model';
$MESS['MTAI_AIAGENT_LLM_PROVIDER'] = 'Provider';
$MESS['MTAI_AIAGENT_LLM_BASE_URL'] = 'API base URL';
$MESS['MTAI_AIAGENT_LLM_MODEL'] = 'Model';
$MESS['MTAI_AIAGENT_LLM_API_KEY'] = 'API key';
$MESS['MTAI_AIAGENT_LLM_API_KEY_HINT'] = 'Stored encrypted in DB; can be set via MTAI_AIAGENT_LLM_API_KEY environment variable (takes priority). For GigaChat put the Authorization Key here (Base64 from the API keys cabinet) — the module obtains and refreshes the access token itself.';
$MESS['MTAI_AIAGENT_GIGACHAT_SCOPE'] = 'GigaChat scope (OAuth)';
$MESS['MTAI_AIAGENT_GIGACHAT_SCOPE_HINT'] = 'Access code sent when obtaining the access token: GIGACHAT_API_PERS (personal), GIGACHAT_API_B2B, GIGACHAT_API_CORP etc. — must match the issued authorization key.';
$MESS['MTAI_AIAGENT_LLM_YANDEX_FOLDER'] = 'Yandex Cloud folder id';
$MESS['MTAI_AIAGENT_LLM_PARAMS'] = 'Generation parameters';
$MESS['MTAI_AIAGENT_LLM_TEMPERATURE'] = 'Temperature';
$MESS['MTAI_AIAGENT_LLM_MAX_TOKENS'] = 'Max tokens';
$MESS['MTAI_AIAGENT_LLM_TIMEOUT'] = 'Timeout, sec';
$MESS['MTAI_AIAGENT_LLM_SSL'] = 'Verify API SSL certificate';

$MESS['MTAI_AIAGENT_EMBED_HEAD'] = 'Embeddings (knowledge base vectorization)';
$MESS['MTAI_AIAGENT_EMBED_PROVIDER'] = 'Mode';
$MESS['MTAI_AIAGENT_EMBED_AUTO'] = 'Auto (provider, else built-in)';
$MESS['MTAI_AIAGENT_EMBED_PROVIDER_MODE'] = 'Provider only';
$MESS['MTAI_AIAGENT_EMBED_HASH'] = 'Built-in lexical (no API)';
$MESS['MTAI_AIAGENT_EMBED_OFF'] = 'Disable search';
$MESS['MTAI_AIAGENT_EMBED_MODEL'] = 'Embedding model';

$MESS['MTAI_AIAGENT_SECRET_STORED'] = '● set, type a new one to replace';
$MESS['MTAI_AIAGENT_SECRET_EMPTY'] = 'not set';

$MESS['MTAI_AIAGENT_MCP_ENABLED'] = 'Enable MCP';
$MESS['MTAI_AIAGENT_MCP_ENABLED_HINT'] = 'Master switch: disables MCP tools in employee chats with the bot and in business process activities (client open lines stay unchanged: RAG over the knowledge base only). Transport: streamable HTTP (JSON-RPC 2.0), authorization: Bearer API key. MCP tools are available to the agent in "Execute task" mode.';
$MESS['MTAI_AIAGENT_MCP_HEAD'] = 'Servers';
$MESS['MTAI_AIAGENT_MCP_COL_NAME'] = 'Name';
$MESS['MTAI_AIAGENT_MCP_COL_URL'] = 'URL (MCP endpoint)';
$MESS['MTAI_AIAGENT_MCP_COL_KEY'] = 'API key';
$MESS['MTAI_AIAGENT_MCP_COL_ENABLED'] = 'On';
$MESS['MTAI_AIAGENT_MCP_COL_DELETE'] = 'Delete';
$MESS['MTAI_AIAGENT_MCP_ADD'] = 'Add server';
$MESS['MTAI_AIAGENT_MCP_KEY_HINT'] = 'The API key is sent as the Authorization: Bearer header. Stored encrypted; leaving the field empty keeps the saved value. Prefer a latin name — tool names are built as mcp_{name}_{tool}.';
$MESS['MTAI_AIAGENT_MCP_TEST'] = 'Connection test';
$MESS['MTAI_AIAGENT_MCP_TEST_BTN'] = 'Test servers';
$MESS['MTAI_AIAGENT_MCP_TEST_HINT'] = 'Handshake and tool count for each enabled server. Tests the saved settings, not the form data.';
$MESS['MTAI_AIAGENT_MCP_NO_SERVERS'] = 'No enabled MCP servers.';
$MESS['MTAI_AIAGENT_MCP_TOOLS_COUNT'] = '%d tools';
$MESS['MTAI_AIAGENT_MCP_FAIL'] = 'error:';

$MESS['MTAI_AIAGENT_APP_HEAD'] = 'Local REST application';
$MESS['MTAI_AIAGENT_AUTH_MODE'] = 'Authorization mode';
$MESS['MTAI_AIAGENT_AUTH_OAUTH'] = 'Local application (OAuth)';
$MESS['MTAI_AIAGENT_AUTH_WEBHOOK'] = 'Incoming webhook + botToken';
$MESS['MTAI_AIAGENT_WEBHOOK_USER'] = 'Webhook owner (user ID)';
$MESS['MTAI_AIAGENT_WEBHOOK_PASSWORD'] = 'Webhook code (botToken)';
$MESS['MTAI_AIAGENT_WEBHOOK_PASSWORD_HINT'] = 'Tail of the incoming webhook URL: /rest/{user ID}/{code}/. The bot registers with botToken and signs imbot.v2 / imopenlines calls with the same token (CLIENT_ID).';
$MESS['MTAI_AIAGENT_APP_PORTAL_URL'] = 'Portal URL';
$MESS['MTAI_AIAGENT_APP_CLIENT_ID'] = 'Client ID';
$MESS['MTAI_AIAGENT_APP_CLIENT_SECRET'] = 'Client Secret';
$MESS['MTAI_AIAGENT_APP_REFRESH'] = 'Refresh token';
$MESS['MTAI_AIAGENT_APP_REFRESH_HINT'] = 'Obtained once during application authorization (see module README). Rotates automatically.';
$MESS['MTAI_AIAGENT_APP_STATUS'] = 'Connection status';
$MESS['MTAI_AIAGENT_APP_NOT_CONFIGURED'] = 'Not configured: fill portal URL, Client ID, Client Secret and refresh token.';
$MESS['MTAI_AIAGENT_APP_OK'] = 'Connected, token obtained.';
$MESS['MTAI_AIAGENT_APP_FAIL'] = 'Connection failed';
$MESS['MTAI_AIAGENT_APP_TEST_HINT'] = 'Credentials are set. Press "Test connection" to refresh the status (the page does not call the portal during render).';
$MESS['MTAI_AIAGENT_APP_TEST'] = 'Test connection';

$MESS['MTAI_AIAGENT_BOT_HEAD'] = 'Chat bot (imbot.v2)';
$MESS['MTAI_AIAGENT_BOT_CODE'] = 'Bot code';
$MESS['MTAI_AIAGENT_BOT_NAME'] = 'Bot name';
$MESS['MTAI_AIAGENT_BOT_STATUS'] = 'Status';
$MESS['MTAI_AIAGENT_BOT_NOT_REGISTERED'] = 'Bot is not registered.';
$MESS['MTAI_AIAGENT_BOT_REGISTERED_OK'] = 'Registered';
$MESS['MTAI_AIAGENT_BOT_DO_REGISTER'] = 'Register';
$MESS['MTAI_AIAGENT_BOT_DO_UPDATE'] = 'Update';
$MESS['MTAI_AIAGENT_BOT_DO_UNREGISTER'] = 'Delete';
$MESS['MTAI_AIAGENT_BOT_DO_TEST'] = 'Send test message';
$MESS['MTAI_AIAGENT_BOT_TEST_OK'] = 'Test message sent: the bot reply should already be in the chat of the webhook owner.';
$MESS['MTAI_AIAGENT_BOT_TEST_TEXT'] = 'AI agent test message: how much does Product-360 cost?';
$MESS['MTAI_AIAGENT_BOT_CONFIRM'] = 'Delete the bot? Dialogs remain, the bot stops responding.';
$MESS['MTAI_AIAGENT_BOT_HINT'] = 'Event mode: fetch (imbot.v2.Event.get); open lines support enabled.';
$MESS['MTAI_AIAGENT_BOT_REGISTERED'] = 'Bot registered';
$MESS['MTAI_AIAGENT_BOT_UPDATED'] = 'Bot updated';
$MESS['MTAI_AIAGENT_BOT_UNREGISTERED'] = 'Bot unregistered';
$MESS['MTAI_AIAGENT_REST_OK'] = 'REST application works: fresh access token obtained.';
$MESS['MTAI_AIAGENT_ACTION_ERROR'] = 'Error';

$MESS['MTAI_AIAGENT_OL_ENABLED'] = 'Enable open lines agent';
$MESS['MTAI_AIAGENT_OL_LINES'] = 'Lines (comma-separated IDs)';
$MESS['MTAI_AIAGENT_OL_LINES_HINT'] = 'Empty = all lines where the bot is added to the queue.';
$MESS['MTAI_AIAGENT_OL_PROMPT'] = 'System prompt (role, rules)';
$MESS['MTAI_AIAGENT_OL_HANDOFF'] = 'Handoff message';
$MESS['MTAI_AIAGENT_OL_ESCALATE'] = 'Escalate to operator when no relevant context found';
$MESS['MTAI_AIAGENT_OL_SELFCHECK'] = 'Answer self-check';
$MESS['MTAI_AIAGENT_OL_SELFCHECK_HINT'] = 'Before sending, a second LLM call verifies the answer is grounded in the knowledge base fragments; hallucination goes to an operator. Not compatible with the Mock provider.';
$MESS['MTAI_AIAGENT_OL_LEAD'] = 'Create lead from dialog without CRM bindings';
$MESS['MTAI_AIAGENT_OL_WELCOME'] = 'Welcome message (on first session message)';
$MESS['MTAI_AIAGENT_OL_TRANSFER_USER'] = 'Transfer to specific user (ID)';
$MESS['MTAI_AIAGENT_OL_WORK_HOURS'] = 'Agent working hours';
$MESS['MTAI_AIAGENT_OL_WORK_DAYS'] = 'days (1=Mon)';
$MESS['MTAI_AIAGENT_OL_WORK_HOURS_HINT'] = 'Format "HH:MM-HH:MM"; empty = 24/7. Outside the window the client gets the message below and the dialog goes to an operator.';
$MESS['MTAI_AIAGENT_OL_OFFHOURS_TEXT'] = 'Off-hours message';

$MESS['MTAI_AIAGENT_OL_TRANSFER_USER_HINT'] = '0 = first available queue operator; otherwise transfer to the employee.';

$MESS['MTAI_AIAGENT_CHAT_ENABLED'] = 'Enable chats agent';
$MESS['MTAI_AIAGENT_CHAT_PROMPT'] = 'CRM agent system prompt';
$MESS['MTAI_AIAGENT_CHAT_ALLOWED'] = 'Allowed users (IDs)';
$MESS['MTAI_AIAGENT_CHAT_ALLOWED_HINT'] = 'Comma-separated; empty = all employees.';
$MESS['MTAI_AIAGENT_HISTORY'] = 'History depth (messages)';

$MESS['MTAI_AIAGENT_RAG_TOPK'] = 'Context chunks (top-K)';
$MESS['MTAI_AIAGENT_RAG_MINSCORE'] = 'Relevance threshold (0..1)';
$MESS['MTAI_AIAGENT_RAG_MINSCORE_HINT'] = 'Cosine similarity. For the built-in lexical embedder use 0.15–0.25, for neural ones 0.3–0.45.';
$MESS['MTAI_AIAGENT_RAG_CHUNK'] = 'Chunking';
$MESS['MTAI_AIAGENT_RAG_CHUNK_SIZE'] = 'size, chars';
$MESS['MTAI_AIAGENT_RAG_CHUNK_OVERLAP'] = 'overlap';
$MESS['MTAI_AIAGENT_RAG_KB'] = 'Documents';
$MESS['MTAI_AIAGENT_RAG_KB_LINK'] = 'Open knowledge base';

$MESS['MTAI_AIAGENT_LOG_LEVEL'] = 'Log level';
$MESS['MTAI_AIAGENT_NOTIFY_USER'] = 'Notify user (ID) about errors';
$MESS['MTAI_AIAGENT_LAST_FETCH'] = 'Last event fetch';
$MESS['MTAI_AIAGENT_LAST_PROCESS'] = 'Last queue processing';
$MESS['MTAI_AIAGENT_LOGS_LINK'] = 'Log';
$MESS['MTAI_AIAGENT_LOGS_LINK_TEXT'] = 'Open module log';
