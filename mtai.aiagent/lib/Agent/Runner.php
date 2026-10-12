<?php

namespace Mtai\Aiagent\Agent;

use Mtai\Aiagent\Chat\ToolExecutor;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Conversation\Store;
use Mtai\Aiagent\Llm\Factory;
use Mtai\Aiagent\LlmException;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Rag\VectorIndex;

/**
 * Оркестратор AI-агента для активности бизнес-процесса (в духе n8n AI Agent):
 * на входе — вопрос/задача, системный промпт, ключ памяти и режим; на выходе —
 * ответ модели.
 *
 * Режимы:
 * - assistant — RAG-ответ: поиск по базе знаний + системный промпт;
 * - agent — выполнение задачи через инструменты CRM (function calling /
 *   JSON-протокол), как у чат-агента;
 * - direct — прямой запрос к модели: без базы знаний и инструментов,
 *   только системный промпт и память.
 *
 * Память (опционально): ключ вида «bp:{memoryKey}» — контекст сохраняется
 * между запусками активности в рамках одного ключа (например, ID документа).
 */
final class Runner
{
	public const MODE_ASSISTANT = 'assistant';
	public const MODE_AGENT = 'agent';
	public const MODE_DIRECT = 'direct';

	/** @var Store|null */
	private ?Store $memory = null;

	public function __construct(?Store $memory = null)
	{
		$this->memory = $memory;
	}

	/**
	 * Запуск агента.
	 * @param string|array $mcpServers выбранные в активности серверы MCP
	 *        (имена из настроек модуля; пусто = без MCP — по умолчанию
	 *        в активности MCP выключены). Строка — разбор ведётся по
	 *        формату старого текстового поля (обратная совместимость).
	 * @return array{answer: string, error: ?string}
	 */
	public function run(
		string $mode,
		string $question,
		?string $systemPrompt = null,
		string $memoryKey = '',
		int $userId = 0,
		string $model = '',
		string|array $mcpServers = [],
	): array {
		$question = trim($question);
		if ($question === '')
		{
			return ['answer' => '', 'error' => 'Пустой запрос к AI-агенту'];
		}

		$memoryKey = trim($memoryKey);
		$key = $memoryKey !== '' ? 'bp:' . $memoryKey : '';
		$memory = $this->memory ?? new Store();

		if ($key !== '')
		{
			$memory->append($key, 'user', $question);
		}

		$result = match ($mode)
		{
			self::MODE_AGENT => $this->runAgent($question, $systemPrompt, $key, $memory, $userId, $model, $mcpServers),
			self::MODE_DIRECT => $this->runDirect($question, $systemPrompt, $key, $memory, $model),
			default => $this->runAssistant($question, $systemPrompt, $key, $memory, $model),
		};

		if ($result['error'] === null && $key !== '')
		{
			$memory->append($key, 'assistant', $result['answer']);
		}

		return $result;
	}

	/** RAG-ответ по базе знаний. */
	private function runAssistant(string $question, ?string $systemPrompt, string $key, Store $memory, string $model = ''): array
	{
		$contexts = (new VectorIndex())->search($question);

		$system = trim((string)$systemPrompt);
		if ($system === '')
		{
			$system = Config::get('bp_assistant_prompt', '');
		}
		if ($system === '')
		{
			$system = 'Ты — ассистент компании. Отвечай кратко и по делу.';
		}
		$system .= "\n\nОтвечай только на основе приведённого контекста базы знаний. "
			. 'Не выдумывай факты, которых нет в контексте. '
			. 'Если ответа в контексте нет — честно скажи об этом одним предложением.';

		$userContent = $question;
		if ($contexts !== [])
		{
			$numbered = [];
			foreach ($contexts as $index => $context)
			{
				$numbered[] = '[Фрагмент ' . ($index + 1) . "]\n" . $context['content'];
			}
			$userContent .= "\n\nКонтекст из базы знаний:\n" . implode("\n\n", $numbered);
		}
		else
		{
			$userContent .= "\n\nКонтекст из базы знаний: релевантных фрагментов не найдено.";
		}

		$messages = [['role' => 'system', 'content' => $system]];
		foreach ($memory->load($key) as $item)
		{
			$messages[] = ['role' => $item['role'], 'content' => $item['content']];
		}
		if ($messages[count($messages) - 1]['role'] === 'user')
		{
			$messages[count($messages) - 1]['content'] = $userContent;
		}
		else
		{
			$messages[] = ['role' => 'user', 'content' => $userContent];
		}

		try
		{
			$answer = Factory::create()->chat($messages, [], $this->modelOptions($model));
			Logger::info('bp', 'assistant answer: ' . mb_substr((string)($answer['content'] ?? ''), 0, 200));

			return ['answer' => trim((string)($answer['content'] ?? '')), 'error' => null];
		}
		catch (LlmException $e)
		{
			Logger::error('bp', 'assistant failed: ' . $e->getMessage());

			return ['answer' => '', 'error' => $e->getMessage()];
		}
	}

	/** Опции чата с переопределением модели (пусто/пробелы = модель из настроек модуля). */
	private function modelOptions(string $model): array
	{
		$model = trim($model);

		return $model !== '' ? ['model' => $model] : [];
	}

	/** Прямой запрос к модели: без базы знаний и инструментов. */
	private function runDirect(string $question, ?string $systemPrompt, string $key, Store $memory, string $model = ''): array
	{
		$system = trim((string)$systemPrompt);
		if ($system === '')
		{
			$system = 'Ты — ассистент. Отвечай кратко, точно и по делу.';
		}

		$now = new \Bitrix\Main\Type\DateTime();
		$system .= "\n\nКонтекст: текущая дата и время — " . $now->format('d.m.Y (D) H:i') . '.';

		// Адрес портала и шаблоны ссылок — иначе модель выдумывает URL.
		$portal = rtrim(Config::portalUrl(), '/');
		if ($portal !== '')
		{
			$system .= "\nАдрес портала: {$portal}. Ссылку на карточку CRM строй только по шаблонам: "
				. "{$portal}/crm/deal/details/{id}/ (сделка), {$portal}/crm/lead/details/{id}/ (лид), "
				. "{$portal}/crm/contact/details/{id}/ (контакт), {$portal}/crm/company/details/{id}/ (компания). "
				. 'Другие домены и пути запрещены.';
		}

		$messages = [['role' => 'system', 'content' => $system]];
		foreach ($memory->load($key) as $item)
		{
			$messages[] = ['role' => $item['role'], 'content' => $item['content']];
		}
		// Без ключа памяти история пуста — вопрос попадает в диалог напрямую.
		if ($key === '')
		{
			$messages[] = ['role' => 'user', 'content' => $question];
		}

		try
		{
			$answer = Factory::create()->chat($messages, [], $this->modelOptions($model));
			Logger::info('bp', 'direct answer: ' . mb_substr((string)($answer['content'] ?? ''), 0, 200));

			return ['answer' => trim((string)($answer['content'] ?? '')), 'error' => null];
		}
		catch (LlmException $e)
		{
			Logger::error('bp', 'direct failed: ' . $e->getMessage());

			return ['answer' => '', 'error' => $e->getMessage()];
		}
	}

	/** Выполнение задачи инструментами CRM (+ инструменты MCP, если выбраны в активности). */
	private function runAgent(string $task, ?string $systemPrompt, string $key, Store $memory, int $userId, string $model = '', string|array $mcpServers = []): array
	{
		$system = trim((string)$systemPrompt);
		if ($system === '')
		{
			$system = Config::get('chat_system_prompt', '');
		}

		$now = new \Bitrix\Main\Type\DateTime();
		$system .= "\n\nКонтекст: текущая дата и время — " . $now->format('d.m.Y (D) H:i') . '.';

		// MCP: только то, что выбрано в параметрах активности (по умолчанию
		// MCP выключены). Чатовый мастер-выключатель здесь не участвует.
		$mcpServers = \Mtai\Aiagent\Mcp\ServerConfig::serversForActivity($mcpServers);
		$mcp = $mcpServers === [] ? null : new \Mtai\Aiagent\Mcp\Manager($mcpServers);
		if ($mcp !== null)
		{
			Logger::info('bp', 'MCP: серверы ' . implode(', ', array_column($mcpServers, 'name')));
		}

		if (!Factory::create()->supportsTools())
		{
			$system .= "\n\nИнструменты, доступные тебе:\n" . \Mtai\Aiagent\Chat\Tools::forPrompt();
			if ($mcp !== null)
			{
				$mcpPrompt = $mcp->forPrompt();
				if ($mcpPrompt !== '')
				{
					$system .= "\n\nИнструменты MCP-серверов:\n" . $mcpPrompt;
				}
			}

			$system .= "\n\nОтвечай СТРОГО одним JSON-объектом без markdown-обёрток:\n"
				. "- для вызова инструмента: {\"tool\": \"имя\", \"arguments\": {…}}\n"
				. "- для финального ответа: {\"answer\": \"текст\"}";
		}

		$messages = [['role' => 'system', 'content' => $system]];
		foreach ($memory->load($key) as $item)
		{
			$messages[] = ['role' => $item['role'], 'content' => $item['content']];
		}
		// Без ключа памяти история пуста — задача попадает в диалог напрямую.
		if ($key === '')
		{
			$messages[] = ['role' => 'user', 'content' => $task];
		}

		$result = ToolLoop::run($messages, Factory::create(), new ToolExecutor(null, $mcp), $userId, $model, $mcp);
		if ($result['error'] !== null)
		{
			Logger::error('bp', 'agent failed: ' . $result['error']);
		}

		return $result;
	}
}
