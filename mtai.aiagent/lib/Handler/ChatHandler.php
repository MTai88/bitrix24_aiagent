<?php

namespace Mtai\Aiagent\Handler;

use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Agent\ToolLoop;
use Mtai\Aiagent\Bot\Manager;
use Mtai\Aiagent\Chat\ToolExecutor;
use Mtai\Aiagent\Chat\Tools;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Conversation\Store;
use Mtai\Aiagent\LlmException;
use Mtai\Aiagent\Llm\Factory;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Rest\Client;

/**
 * Модуль 2: AI-агент личных чатов (сотрудник ↔ бот).
 *
 * Сообщение сотрудника разбирает LLM: с нативным function calling
 * (OpenAI-совместимые) — цикл tool call → результат → ответ; без него —
 * JSON-протокол (описание инструментов в промте, ответ строго JSON).
 * Ответ отправляется через imbot.v2.Chat.Message.send.
 *
 * Индикаторы присутствия (настройки chat_read_receipt / chat_typing_status):
 * Fetcher при приёме события сразу отмечает сообщение «прочитано» и шлёт
 * «AI-агент размышляет...» (ChatHandler::reactToIncoming), а на время
 * обработки ответа typing продлевается пингами между итерациями ToolLoop.
 */
final class ChatHandler
{
	private const MAX_TOOL_ITERATIONS = 6;

	private Client $rest;
	private Store $memory;

	public function __construct(?Client $rest = null, ?Store $memory = null)
	{
		$this->rest = $rest ?? new Client();
		$this->memory = $memory ?? new Store();
	}

	/**
	 * Приём входящего сообщения чата (общие условия для реакции бота).
	 * Используется и обработчиком (handle), и Fetcher'ом — индикаторы
	 * «прочитано»/«размышает» поднимаются уже при получении события,
	 * не дожидаясь тика очереди.
	 */
	public static function acceptMessage(array $data): bool
	{
		if (!Config::isEnabled('chat_enabled'))
		{
			return false;
		}

		$chat = (array)($data['chat'] ?? []);
		// Работаем только в личных диалогах с ботом.
		if (($chat['type'] ?? '') !== 'private')
		{
			return false;
		}

		$user = (array)($data['user'] ?? []);
		$userId = (int)($user['id'] ?? 0);
		if ($userId <= 0 || !empty($user['bot']))
		{
			return false;
		}

		return self::userAllowed($userId);
	}

	/** Сотрудник из списка chat_allowed_users (пусто = все). Чат при этом должен быть включён. */
	public static function userAllowed(int $userId): bool
	{
		if (!Config::isEnabled('chat_enabled') || $userId <= 0)
		{
			return false;
		}

		$allowed = Config::getCommaList('chat_allowed_users');

		return $allowed === [] || in_array((string)$userId, $allowed, true);
	}

	/** Индикаторы «прочитано» + «размышает...» (вызов — при приёме события). */
	public static function reactToIncoming(array $data, ?Client $rest = null): void
	{
		$chat = (array)($data['chat'] ?? []);
		$message = (array)($data['message'] ?? []);
		$botId = Manager::botUserId();
		$chatId = (int)($chat['id'] ?? 0);
		$messageId = (int)($message['id'] ?? 0);

		$indicator = new \Mtai\Aiagent\Chat\Indicator($rest);
		if (Config::isEnabled('chat_read_receipt'))
		{
			$indicator->markRead($botId, $chatId, $messageId);
		}
		if (Config::isEnabled('chat_typing_status'))
		{
			$indicator->showThinking($botId, $chatId);
		}
	}

	public function handle(array $data): void
	{
		if (!self::acceptMessage($data))
		{
			Logger::debug('chat', 'Message not accepted (disabled/wrong chat/bot author/allow-list), skipping');

			return;
		}

		$chat = (array)($data['chat'] ?? []);
		$user = (array)($data['user'] ?? []);
		$userId = (int)($user['id'] ?? 0);

		$message = (array)($data['message'] ?? []);
		$text = trim((string)($message['text'] ?? ''));

		$botId = Manager::botUserId();
		// dialogId персонализирован для бота: ID собеседника.
		$dialogId = (string)($chat['dialogId'] ?? '');
		if ($dialogId === '' || $dialogId === 'chat' . (int)($chat['id'] ?? -1))
		{
			$dialogId = (string)$userId;
		}

		$chatId = (int)($chat['id'] ?? 0);

		if ($text === '')
		{
			$text = (string)$this->transcribeVoice($message, $botId, $dialogId);
			if ($text === '')
			{
				return;
			}
		}

		// «размышает...» продлевается на время обработки (read-метка уже
		// сдвинута на fetch — повторный markRead не нужен).
		$refreshTyping = null;
		if (Config::isEnabled('chat_typing_status'))
		{
			$indicator = new \Mtai\Aiagent\Chat\Indicator($this->rest);
			$indicator->showThinking($botId, $chatId);
			$refreshTyping = static function () use ($indicator, $botId, $chatId): void
			{
				$indicator->showThinking($botId, $chatId);
			};
		}

		$key = 'im:' . $botId . ':' . $userId;
		$this->memory->append($key, 'user', $text);

		$result = $this->converse($key, $userId, (string)($user['name'] ?? ''), $refreshTyping);
		$reply = $result['error'] !== null
			? 'AI-агент временно недоступен: ' . $result['error']
			: $result['answer'];

		$this->send($botId, $dialogId, $reply);
		$this->memory->append($key, 'assistant', $reply, $result['refs'] ?? []);
	}

	/**
	 * Пустое сообщение с файлом ai-voice-*.wav → текст.
	 * null — это не наше голосовое (обработчик молчит, как и раньше).
	 * Пустая строка не возвращается: ошибка распознавания уже отправлена в чат.
	 */
	private function transcribeVoice(array $message, int $botId, string $dialogId): ?string
	{
		if (!Config::isEnabled('voice_enabled'))
		{
			return null;
		}

		$file = \Mtai\Aiagent\Chat\VoiceMessage::extract((int)($message['id'] ?? 0));
		if ($file === null)
		{
			return null;
		}

		try
		{
			$text = trim(\Mtai\Aiagent\Stt\Factory::make()->transcribe($file['path'], 'audio/wav'));
		}
		catch (\Throwable $e)
		{
			Logger::error('chat', 'voice transcribe failed: ' . $e->getMessage());
			$this->send($botId, $dialogId, 'Не удалось распознать голосовое: ' . mb_substr($e->getMessage(), 0, 200));

			return '';
		}

		if ($text === '')
		{
			$this->send($botId, $dialogId, 'Не удалось распознать голосовое: речь не распознана.');

			return '';
		}

		Logger::info('chat', 'voice transcribed message ' . (int)($message['id'] ?? 0) . ': ' . mb_substr($text, 0, 200));
		$this->send($botId, $dialogId, 'Распознал: «' . $text . '»');

		return $text;
	}

	/**
	 * Исполнение отложенного задания (lib/Schedule): поручение выполняется
	 * всеми доступными инструментами от имени сотрудника, результат
	 * докладывается сообщением в его чат с ботом. При сбое инструкция
	 * снимается из памяти — ретрай планировщика не должен дублировать её
	 * в истории.
	 *
	 * @return array{answer: string, error: ?string}
	 */
	public function runScheduled(int $userId, string $dialogId, string $instruction): array
	{
		$botId = Manager::botUserId();
		$key = 'im:' . $botId . ':' . $userId;

		$this->memory->append($key, 'user', $instruction);

		$result = $this->converse($key, $userId, $this->resolveUserName($userId));
		if ($result['error'] !== null)
		{
			$this->memory->trimLast($key, 'user');

			return $result;
		}

		$this->send($botId, $dialogId, $result['answer']);
		$this->memory->append($key, 'assistant', $result['answer'], $result['refs'] ?? []);

		return $result;
	}

	private function resolveUserName(int $userId): string
	{
		$user = \Bitrix\Main\UserTable::getRowById($userId);
		if (!$user)
		{
			return '';
		}

		return trim($user['NAME'] . ' ' . $user['LAST_NAME']);
	}

	/** Диалог с LLM с исполнением инструментов. */
	private function converse(string $key, int $userId, string $userName = '', ?callable $onProgress = null): array
	{
		$provider = Factory::create();

		// MCP: внешние инструменты для чата сотрудников — мастер-выключатель
		// «Включить в чате» + галочки «Вкл.» у серверов. Открытые линии
		// клиентов остаются строго на RAG (OpenLineHandler MCP не получает);
		// активности БП выбирают серверы своим параметром.
		$mcpServers = \Mtai\Aiagent\Mcp\ServerConfig::serversForChat();
		$mcp = $mcpServers === [] ? null : new \Mtai\Aiagent\Mcp\Manager($mcpServers);
		if ($mcp !== null)
		{
			Logger::info('chat', 'MCP: серверы ' . implode(', ', array_column($mcpServers, 'name')));
		}

		$executor = new ToolExecutor($this->rest, $mcp);

		$messages = $this->buildMessages($key, $provider->supportsTools(), $userName, $userId, $mcp);

		return ToolLoop::run($messages, $provider, $executor, $userId, '', $mcp, $onProgress);
	}

	private function buildMessages(string $key, bool $nativeTools, string $userName = '', int $userId = 0, ?\Mtai\Aiagent\Mcp\Manager $mcp = null): array
	{
		$system = Config::get('chat_system_prompt', '');

		// Контекст «кто и когда»: без текущей даты модель не может
		// вычислить «завтра»/«в пятницу» для дедлайнов задач.
		$now = new \Bitrix\Main\Type\DateTime();
		$system .= "\n\nКонтекст: текущая дата и время — "
			. $now->format('d.m.Y (D) H:i')
			. ', день недели: '. $this->weekdayRu((int)$now->format('N')) . '.';
		if ($userName !== '')
		{
			$system .= ' Обращается сотрудник ' . $userName . ' (ID ' . $userId . ')'
				. ' — действия в CRM и задачах выполняй от его имени.';
		}
		$system = $this->withToolRules($system);

		// Адрес портала и шаблоны ссылок: без них модель выдумывает URL
		// вида https://example.com/crm/deal/view/456/.
		$portal = rtrim(Config::portalUrl(), '/');
		if ($portal !== '')
		{
			$system .= "\nАдрес портала: {$portal}. Ссылку на карточку CRM строй ТОЛЬКО по шаблонам "
				. "(вместо {id} — номер сущности):\n"
				. "- сделка: {$portal}/crm/deal/details/{id}/\n"
				. "- лид: {$portal}/crm/lead/details/{id}/\n"
				. "- контакт: {$portal}/crm/contact/details/{id}/\n"
				. "- компания: {$portal}/crm/company/details/{id}/\n"
				. "Никогда не используй другие домены (example.com и т.п.) и пути. "
				. 'Если не уверен в ID — сначала найди сущность инструментом.';
		}

		if (!$nativeTools)
		{
			$system .= "\n\n" . $this->jsonProtocolPrompt($mcp);
		}
		elseif ($mcp !== null && $mcp->forPrompt() !== '')
		{
			// Нативный function calling: без явного указания модель
			// игнорирует mcp_*-инструменты среди CRM-тулсов (поймано
			// на GigaChat + DeepWiki).
			$system .= "\n\nУ тебя есть внешние инструменты MCP (имена с префиксом mcp_): "
				. 'они обращаются к системам вне портала (документация библиотек, '
				. 'внешние сервисы). Когда вопрос явно про внешнюю систему/документацию — '
				. 'вызывай именно их, а не CRM-инструменты и не kb_search.';
		}

		$messages = [['role' => 'system', 'content' => $system]];
		foreach (Store::forModel($this->memory->load($key)) as $item)
		{
			$messages[] = $item;
		}

		return $messages;
	}

	/**
	 * Правила выбора инструментов. Дописываются, если сохранённый промпт
	 * портала ещё без них (настройка не сброшена к новому умолчанию).
	 */
	private function withToolRules(string $system): string
	{
		if (str_contains($system, 'crm_activity_add'))
		{
			return $system;
		}

		return $system . "\n\nПравила: если поиск вернул несколько записей — перечисли их и спроси, какую выбрать, не угадывай. "
			. 'Перед созданием контакта, компании или сделки проверь дубль через crm_find_entities (телефон и email тоже). '
			. 'Дело в карточке CRM (звонок, встреча с клиентом) — crm_activity_add; задача сотруднику — crm_create_task. '
			. 'Ссылку на карточку бери из поля url результата, не выдумывай. '
			. '«Что у меня сегодня» — my_day. Регулярное поручение («каждое утро») — schedule_task с repeat, действие сразу не выполняй.';
	}

	private function weekdayRu(int $isoNumber): string
	{
		$days = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];

		return $days[$isoNumber] ?? '';
	}

	private function jsonProtocolPrompt(?\Mtai\Aiagent\Mcp\Manager $mcp = null): string
	{
		$prompt = "Инструменты, доступные тебе:\n" . Tools::forPrompt();
		if ($mcp !== null)
		{
			$mcpPrompt = $mcp->forPrompt();
			if ($mcpPrompt !== '')
			{
				$prompt .= "\n\nИнструменты внешних MCP-серверов:\n" . $mcpPrompt;
			}
		}

		return $prompt
			. "\n\nОтвечай СТРОГО одним JSON-объектом без markdown-обёрток:\n"
			. "- для вызова инструмента: {\"tool\": \"имя\", \"arguments\": {…}}\n"
			. "- для финального ответа пользователю: {\"answer\": \"текст\"}\n"
			. "Не выдумывай инструменты и параметры. Данные бери из результатов вызовов.";
	}

	/** Извлечение JSON-объекта из ответа модели (в т.ч. из ```json …```). */
	public static function parseJsonCommand(string $content): ?array
	{
		$content = trim((string)preg_replace('/^```(?:json)?|```$/m', '', trim($content)));

		if (preg_match('/\{.*\}/s', $content, $match) || preg_match('/\{[^{}]*\}/s', $content, $match))
		{
			try
			{
				$decoded = Json::decode($match[0]);
				if (is_array($decoded))
				{
					return $decoded;
				}
			}
			catch (\Throwable)
			{
			}
		}

		return null;
	}

	private function finalizeReply(?string $content): string
	{
		$reply = trim((string)$content);

		return $reply !== '' ? $reply : 'Не смог сформировать ответ — попробуйте переформулировать запрос.';
	}

	private function send(int $botId, string $dialogId, string $text): void
	{
		// Мессенджер рендерит BBCode — markdown-ответ LLM конвертируем.
		$text = \Mtai\Aiagent\Chat\Markdown::toBb($text);

		// Ключ message — в lowerCamelCase: REST-шлюз конвертирует ключи
		// fields, а контроллер читает сырое значение именно из 'message'.
		[$ok, $error] = $this->rest->callSafe('imbot.v2.Chat.Message.send', [
			'botId' => $botId,
			'dialogId' => $dialogId,
			'fields' => ['message' => $text],
		]);

		if (!$ok)
		{
			Logger::error('chat', "Cannot send message to dialog {$dialogId}: " . (string)$error);
		}
	}
}
