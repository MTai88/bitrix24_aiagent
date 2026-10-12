<?php

$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_QUESTION'] = 'Question / task';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MODE'] = 'Mode';
$MESS['MTAI_AIAGENT_ACTIVITY_MODE_ASSISTANT'] = 'Assistant answer (knowledge base)';
$MESS['MTAI_AIAGENT_ACTIVITY_MODE_DIRECT'] = 'Direct model request (no knowledge base)';
$MESS['MTAI_AIAGENT_ACTIVITY_MODE_AGENT'] = 'Execute task (CRM tools)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_SYSTEM_PROMPT'] = 'System prompt (overrides module settings)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MEMORY_KEY'] = 'Dialog memory key';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_USER_ID'] = 'User ID (on whose behalf CRM actions run)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_ANSWER_FIELD'] = 'Document field for the answer (empty = do not write)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MODEL'] = 'Model (empty = module settings)';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MODEL_HINT'] = 'Overrides the LLM model for this node. Examples: gpt-4o-mini, yandexgpt-lite/latest, GigaChat-Pro.';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MCP_SERVERS'] = 'MCP servers';
$MESS['MTAI_AIAGENT_ACTIVITY_PARAM_MCP_SERVERS_HINT'] = 'Servers from the module settings available to this node. MCP is disabled by default — select one or more servers to let the agent call their tools (mcp_<server>_<tool> names; agent mode only).';
$MESS['MTAI_AIAGENT_ACTIVITY_ERROR_FIELD_WRITE'] = 'Cannot write the answer to the document field';
$MESS['MTAI_AIAGENT_ACTIVITY_ERROR_QUESTION_EMPTY'] = 'AI agent question is empty';
$MESS['MTAI_AIAGENT_ACTIVITY_ERROR_MODULE'] = 'Module mtai.aiagent is not installed';
