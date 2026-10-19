<?php
/**
 * Подключение модуля mtai.aiagent.
 *
 * Модуль самодостаточен: внешних composer-зависимостей нет, HTTP работает
 * через штатный \Bitrix\Main\Web\HttpClient.
 */

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
	'mtai.aiagent',
	[
		// --- ядро ---
		'Mtai\\Aiagent\\Config' => 'lib/Config.php',
		'Mtai\\Aiagent\\Secrets' => 'lib/Secrets.php',
		'Mtai\\Aiagent\\Logger' => 'lib/Logger.php',
		'Mtai\\Aiagent\\Agent' => 'lib/Agent.php',
		'Mtai\\Aiagent\\EventHandler' => 'lib/EventHandler.php',
		'Mtai\\Aiagent\\LlmException' => 'lib/LlmException.php',
		'Mtai\\Aiagent\\RestException' => 'lib/RestException.php',
		'Mtai\\Aiagent\\McpException' => 'lib/McpException.php',
		// --- ORM ---
		'Mtai\\Aiagent\\Orm\\DocumentTable' => 'lib/Orm/DocumentTable.php',
		'Mtai\\Aiagent\\Orm\\ChunkTable' => 'lib/Orm/ChunkTable.php',
		'Mtai\\Aiagent\\Orm\\ConversationTable' => 'lib/Orm/ConversationTable.php',
		'Mtai\\Aiagent\\Orm\\JobTable' => 'lib/Orm/JobTable.php',
		'Mtai\\Aiagent\\Orm\\LogTable' => 'lib/Orm/LogTable.php',
		// --- REST ---
		'Mtai\\Aiagent\\Rest\\Client' => 'lib/Rest/Client.php',
		'Mtai\\Aiagent\\Bot\\Manager' => 'lib/Bot/Manager.php',
		// --- события и очередь ---
		'Mtai\\Aiagent\\Event\\Fetcher' => 'lib/Event/Fetcher.php',
		'Mtai\\Aiagent\\Queue\\Processor' => 'lib/Queue/Processor.php',
		// --- LLM ---
		'Mtai\\Aiagent\\Llm\\ProviderInterface' => 'lib/Llm/ProviderInterface.php',
		'Mtai\\Aiagent\\Llm\\Factory' => 'lib/Llm/Factory.php',
		'Mtai\\Aiagent\\Llm\\OpenAiProvider' => 'lib/Llm/OpenAiProvider.php',
		'Mtai\\Aiagent\\Llm\\YandexProvider' => 'lib/Llm/YandexProvider.php',
		'Mtai\\Aiagent\\Llm\\GigaChatProvider' => 'lib/Llm/GigaChatProvider.php',
		'Mtai\\Aiagent\\Llm\\MockProvider' => 'lib/Llm/MockProvider.php',
		'Mtai\\Aiagent\\Llm\\HashEmbedder' => 'lib/Llm/HashEmbedder.php',
		// --- распознавание речи ---
		'Mtai\\Aiagent\\Stt\\SttInterface' => 'lib/Stt/SttInterface.php',
		'Mtai\\Aiagent\\Stt\\Factory' => 'lib/Stt/Factory.php',
		'Mtai\\Aiagent\\Stt\\Wav' => 'lib/Stt/Wav.php',
		'Mtai\\Aiagent\\Stt\\Limits' => 'lib/Stt/Limits.php',
		'Mtai\\Aiagent\\Stt\\OpenAiStt' => 'lib/Stt/OpenAiStt.php',
		'Mtai\\Aiagent\\Stt\\YandexStt' => 'lib/Stt/YandexStt.php',
		'Mtai\\Aiagent\\Stt\\MockStt' => 'lib/Stt/MockStt.php',
		'Mtai\\Aiagent\\Stt\\UnavailableStt' => 'lib/Stt/UnavailableStt.php',
		// --- RAG ---
		'Mtai\\Aiagent\\Rag\\Chunker' => 'lib/Rag/Chunker.php',
		'Mtai\\Aiagent\\Rag\\VectorIndex' => 'lib/Rag/VectorIndex.php',
		'Mtai\\Aiagent\\Rag\\DocumentService' => 'lib/Rag/DocumentService.php',
		'Mtai\\Aiagent\\Rag\\Parser\\ParserInterface' => 'lib/Rag/Parser/ParserInterface.php',
		'Mtai\\Aiagent\\Rag\\Parser\\TxtParser' => 'lib/Rag/Parser/TxtParser.php',
		'Mtai\\Aiagent\\Rag\\Parser\\DocxParser' => 'lib/Rag/Parser/DocxParser.php',
		'Mtai\\Aiagent\\Rag\\Parser\\PdfParser' => 'lib/Rag/Parser/PdfParser.php',
		// --- диалоги и обработчики ---
		'Mtai\\Aiagent\\Conversation\\Store' => 'lib/Conversation/Store.php',
		'Mtai\\Aiagent\\Handler\\OpenLineHandler' => 'lib/Handler/OpenLineHandler.php',
		'Mtai\\Aiagent\\Handler\\ChatHandler' => 'lib/Handler/ChatHandler.php',
		'Mtai\\Aiagent\\Handler\\CommandHandler' => 'lib/Handler/CommandHandler.php',
		'Mtai\\Aiagent\\Agent\\Runner' => 'lib/Agent/Runner.php',
		'Mtai\\Aiagent\\Chat\\Markdown' => 'lib/Chat/Markdown.php',
		'Mtai\\Aiagent\\Chat\\IncomingFiles' => 'lib/Chat/IncomingFiles.php',
		'Mtai\\Aiagent\\Chat\\VoiceMessage' => 'lib/Chat/VoiceMessage.php',
		'Mtai\\Aiagent\\Chat\\VoicePublisher' => 'lib/Chat/VoicePublisher.php',
		'Mtai\\Aiagent\\Controller\\Voice' => 'lib/Controller/Voice.php',
		'Mtai\\Aiagent\\Agent\\ToolLoop' => 'lib/Agent/ToolLoop.php',
		'Mtai\\Aiagent\\Tools\\ToolRegistry' => 'lib/Tools/ToolRegistry.php',
		'Mtai\\Aiagent\\Tools\\Context' => 'lib/Tools/Context.php',
		'Mtai\\Aiagent\\Chat\\AccessGuard' => 'lib/Chat/AccessGuard.php',
		'Mtai\\Aiagent\\Chat\\Tools' => 'lib/Chat/Tools.php',
		'Mtai\\Aiagent\\Chat\\ToolExecutor' => 'lib/Chat/ToolExecutor.php',
		// --- MCP (Model Context Protocol) ---
		'Mtai\\Aiagent\\Mcp\\ServerConfig' => 'lib/Mcp/ServerConfig.php',
		'Mtai\\Aiagent\\Mcp\\Client' => 'lib/Mcp/Client.php',
		'Mtai\\Aiagent\\Mcp\\Manager' => 'lib/Mcp/Manager.php',
	]
);
