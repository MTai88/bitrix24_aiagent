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

	public function handle(array $data): void
	{
		if (!Config::isEnabled('chat_enabled'))
		{
			return;
		}

		$chat = (array)($data['chat'] ?? []);
		$user = (array)($data['user'] ?? []);
		$userId = (int)($user['id'] ?? 0);

		// Работаем только в личных диалогах с ботом.
		if (($chat['type'] ?? '') !== 'private')
		{
			return;
		}

		if ($userId <= 0 || !empty($user['bot']))
		{
			return;
		}

		$allowed = Config::getCommaList('chat_allowed_users');
		if ($allowed !== [] && !in_array((string)$userId, $allowed, true))
		{
			Logger::debug('chat', "User #{$userId} not in allow-list, skipping");

			return;
		}

		$message = (array)($data['message'] ?? []);
		$text = trim((string)($message['text'] ?? ''));
		if ($text === '')
		{
			return;
		}

		$botId = Manager::botUserId();
		// dialogId персонализирован для бота: ID собеседника.
		$dialogId = (string)($chat['dialogId'] ?? '');
		if ($dialogId === '' || $dialogId === 'chat' . (int)($chat['id'] ?? -1))
		{
			$dialogId = (string)$userId;
		}

		$key = 'im:' . $botId . ':' . $userId;
		$this->memory->append($key, 'user', $text);

		$reply = $this->converse($key, $text, $userId, (string)($user['name'] ?? ''));

		$this->send($botId, $dialogId, $reply);
		$this->memory->append($key, 'assistant', $reply);
	}

	/** Диалог с LLM с исполнением инструментов. */
	private function converse(string $key, string $text, int $userId, string $userName = ''): string
	{
		$provider = Factory::create();
		$executor = new ToolExecutor($this->rest);
		$tools = Tools::definitions();

		$messages = $this->buildMessages($key, $provider->supportsTools(), $userName, $userId);

		$result = ToolLoop::run($messages, $provider, $executor, $userId);

		return $result['error'] !== null
			? 'AI-агент временно недоступен: ' . $result['error']
			: $result['answer'];
	}

		private function buildMessages(string $key, bool $nativeTools, string $userName = '', int $userId = 0): array
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
			$system .= "\n\n" . $this->jsonProtocolPrompt();
		}

		$messages = [['role' => 'system', 'content' => $system]];
		foreach ($this->memory->load($key) as $item)
		{
			$messages[] = ['role' => $item['role'], 'content' => $item['content']];
		}

		return $messages;
	}

	private function weekdayRu(int $isoNumber): string
	{
		$days = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];

		return $days[$isoNumber] ?? '';
	}

	private function jsonProtocolPrompt(): string
	{
		return "Инструменты, доступные тебе:\n"
			. Tools::forPrompt()
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
