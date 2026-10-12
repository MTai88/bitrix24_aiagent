<?php

$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_QUESTION'] = 'Вопрос / задача';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MODE'] = 'Режим';
$MESS['MTAI_AIAGENT_ACTIVITY_MODE_ASSISTANT'] = 'Ответ ассистента (по базе знаний)';
$MESS['MTAI_AIAGENT_ACTIVITY_MODE_DIRECT'] = 'Прямой запрос к модели (без базы знаний)';
$MESS['MTAI_AIAGENT_ACTIVITY_MODE_AGENT'] = 'Выполнить задачу (инструменты CRM)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_SYSTEM_PROMPT'] = 'Системный промпт (переопределяет настройки модуля)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MEMORY_KEY'] = 'Ключ памяти диалога';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_USER_ID'] = 'ID пользователя (от чьего имени действия в CRM)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_ANSWER_FIELD'] = 'Поле документа для ответа (пусто = не записывать)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MODEL'] = 'Модель (пусто = из настроек модуля)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MODEL_HINT'] = 'Переопределяет модель LLM для этого узла. Примеры: gpt-4o-mini, yandexgpt-lite/latest, GigaChat-Pro.';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MCP_SERVERS'] = 'MCP-серверы';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MCP_SERVERS_HINT'] = 'Серверы из настроек модуля, доступные этому узлу. По умолчанию MCP выключены — выберите один или несколько серверов, чтобы агент мог вызывать их инструменты (имена вида mcp_имя_инструмент; только режим «Выполнить задачу»).';
$MESS['MTAI_AIAGENT_ACTIVITY_ERROR_FIELD_WRITE'] = 'Не удалось записать ответ в поле документа';
$MESS['MTAI_AIAGENT_ACTIVITY_ERROR_QUESTION_EMPTY'] = 'Не заполнен вопрос к AI-агенту';
$MESS['MTAI_AIAGENT_ACTIVITY_ERROR_MODULE'] = 'Модуль mtai.aiagent не установлен';
