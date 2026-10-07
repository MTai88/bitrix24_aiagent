<?php

namespace Mtai\Aiagent\Handler;

use Mtai\Aiagent\Bot\Manager;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Conversation\Store;
use Mtai\Aiagent\LlmException;
use Mtai\Aiagent\Llm\Factory;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Rag\VectorIndex;
use Mtai\Aiagent\Rest\Client;

/**
 * Модуль 1: AI-агент открытых линий.
 *
 * Сообщение клиента → поиск по базе знаний (RAG) → ответ через
 * imopenlines.bot.session.message.send. Если ответа нет, клиент
 * просит оператора или LLM не справилась — диалог передаётся
 * живому оператору (imopenlines.bot.session.operator / .transfer),
 * после чего агент в этом чате замолкает (сессия бота завершается).
 */
final class OpenLineHandler
{
	private const ESCALATE_MARKER = '[ESCALATE]';

	/** Ключевые слова запроса человека (в любом регистре). */
	private const OPERATOR_KEYWORDS = '/оператор|менеджер|живой человек|связать с человеком|сотрудник|позвонить/ui';

	private Client $rest;
	private Store $memory;

	public function __construct(?Client $rest = null, ?Store $memory = null)
	{
		$this->rest = $rest ?? new Client();
		$this->memory = $memory ?? new Store();
	}

	public function handle(array $data): void
	{
		if (!Config::isEnabled('ol_enabled'))
		{
			return;
		}

		$chat = (array)($data['chat'] ?? []);
		$chatId = (int)($chat['id'] ?? 0);
		if ($chatId <= 0)
		{
			return;
		}

		if (!$this->isLineAllowed($chat))
		{
			Logger::debug('openline', "Chat #{$chatId}: line not in allow-list, skipping");

			return;
		}

		$message = (array)($data['message'] ?? []);
		$text = trim((string)($message['text'] ?? ''));

		// Пустой текст (файл/стикер) — сразу оператору.
		if ($text === '')
		{
			$this->escalate($chatId, 'empty message body');

			return;
		}

		// Вне рабочих часов агента — сообщение клиенту и передача оператору.
		if (!$this->isWithinWorkHours())
		{
			$this->escalate($chatId, 'outside work hours', Config::get('ol_offhours_text', ''));

			return;
		}

		$key = 'ol:' . $chatId;
		$isNewDialog = $this->memory->load($key) === [];
		$this->memory->append($key, 'user', $text);

		// Приветственное сообщение на первое сообщение сессии (если задано).
		if ($isNewDialog && Config::get('ol_welcome_message', '') !== '')
		{
			$this->sendSessionMessage($chatId, Config::get('ol_welcome_message'));
		}

		$this->createLeadIfNeeded($chatId, $isNewDialog);

		// ---------- RAG ----------
		// Клиентам — только публичная область базы знаний; внутренние
		// документы (регламенты, инструкции для сотрудников) не отдаются.
		$contexts = (new VectorIndex())->search($text, null, null, ['public']);
		$hasContext = $contexts !== [];

		$system = $this->buildSystemPrompt($hasContext, $this->sessionCrmContext($chatId));

		$userContent = $text;
		if ($hasContext)
		{
			$numbered = [];
			foreach ($contexts as $index => $context)
			{
				$numbered[] = '[Фрагмент ' . ($index + 1) . "]\n" . $context['content'];
			}
			$userContent .= "\n\nКонтекст из базы знаний:\n" . implode("\n\n", $numbered);
		}

		$messages = [['role' => 'system', 'content' => $system]];
		foreach ($this->memory->load($key) as $item)
		{
			$messages[] = ['role' => $item['role'], 'content' => $item['content']];
		}
		// Последнее сообщение пользователя — с контекстом RAG.
		$messages[count($messages) - 1]['content'] = $userContent;

		// ---------- Ответ LLM ----------
		$wantOperator = false;
		$reason = '';

		if (preg_match(self::OPERATOR_KEYWORDS, $text))
		{
			$wantOperator = true;
			$reason = 'client asked for human';
		}
		elseif (!$hasContext && Config::isEnabled('ol_no_answer_escalate'))
		{
			$wantOperator = true;
			$reason = 'no relevant context in knowledge base';
		}

		try
		{
			$provider = Factory::create();
			$answer = $provider->chat($messages);
			$reply = trim((string)($answer['content'] ?? ''));

			if ($reply === '' || str_contains($reply, self::ESCALATE_MARKER))
			{
				$wantOperator = true;
				$reason = $reason !== '' ? $reason : 'LLM unsure (ESCALATE marker)';
			}

			// Self-check: вторая быстрая проверка LLM «подтверждается ли
			// ответ контекстом» — страховка от уверенной выдумки модели.
			if (!$wantOperator && $hasContext && Config::isEnabled('ol_self_check'))
			{
				if (!$this->isAnswerGrounded($provider, $text, $reply, $contexts))
				{
					$wantOperator = true;
					$reason = 'self-check: answer not grounded in context';
				}
			}

			Logger::info('openline', "Chat #{$chatId}: llm answered" . ($wantOperator ? ' with escalation' : ''), [
				'topScore' => $contexts[0]['score'] ?? null,
				'selfCheck' => Config::isEnabled('ol_self_check') ? 'on' : 'off',
			]);
		}
		catch (LlmException $e)
		{
			Logger::error('openline', "Chat #{$chatId}: LLM failed: " . $e->getMessage());
			$wantOperator = true;
			$reason = 'LLM error: ' . $e->getMessage();
		}

		if ($wantOperator)
		{
			$this->escalate($chatId, $reason);

			return;
		}

		$this->sendSessionMessage($chatId, $reply);
		$this->memory->append($key, 'assistant', $reply);
	}

	/**
	 * Self-check: отдельный запрос LLM — «подтверждается ли ответ
	 * фрагментами базы знаний». Ответ должен начинаться с «ДА».
	 * Ошибки проверки не блокируют отправку (fail-open), кроме явного «НЕТ».
	 */
	private function isAnswerGrounded(
		\Mtai\Aiagent\Llm\ProviderInterface $provider,
		string $question,
		string $answer,
		array $contexts
	): bool {
		$context = implode("\n---\n", array_column($contexts, 'content'));

		$messages = [
			[
				'role' => 'system',
				'content' => 'Ты — строгий контролёр качества. Твоя задача — поймать выдумку.',
			],
			[
				'role' => 'user',
				'content' => "Фрагменты базы знаний:\n{$context}\n\nВопрос клиента:\n{$question}\n\n"
					. "Предлагаемый ответ:\n{$answer}\n\n"
					. "Полностью ли подтверждается предлагаемый ответ приведёнными фрагментами "
					. "(факты, цены, сроки)? Ответь одним словом: ДА или НЕТ.",
			],
		];

		try
		{
			$check = $provider->chat($messages, [], ['maxTokens' => 10, 'temperature' => 0.0]);
			$verdict = mb_strtoupper(trim((string)($check['content'] ?? '')));

			Logger::debug('openline', 'self-check verdict: ' . mb_substr($verdict, 0, 20));

			// Начинается с «ДА» — подтверждён; «НЕТ» — выдумка; прочее —
			// неопределённость, доверяем основному ответу.
			if (str_starts_with($verdict, 'ДА') || str_starts_with($verdict, 'YES'))
			{
				return true;
			}

			return !str_starts_with($verdict, 'НЕТ') && !str_starts_with($verdict, 'NO');
		}
		catch (LlmException $e)
		{
			Logger::warn('openline', 'self-check failed (fail-open): ' . $e->getMessage());

			return true;
		}
	}

	/** Ответ клиенту в текущей сессии бота. */
	private function sendSessionMessage(int $chatId, string $text): void
	{
		// Мессенджер рендерит BBCode — markdown-ответ LLM конвертируем.
		$text = \Mtai\Aiagent\Chat\Markdown::toBb($text);

		[$ok] = $this->rest->callSafe('imopenlines.bot.session.message.send', [
			'CHAT_ID' => $chatId,
			'MESSAGE' => $text,
			'NAME' => 'DEFAULT',
		]);

		if ($ok)
		{
			return;
		}

		// Сессия могла закрыться — падаем на обычное сообщение бота в чат
		// (ключ message в lowerCamelCase, см. ChatHandler::send).
		$this->rest->callSafe('imbot.v2.Chat.Message.send', [
			'botId' => Manager::botUserId(),
			'dialogId' => 'chat' . $chatId,
			'fields' => ['message' => $text],
		]);
	}

	/**
	 * Передача диалога оператору: завершаем сессию бота и отдаём
	 * диалог в очередь линии (первый доступный). Диалог может быть
	 * передан конкретному сотруднику через опцию transfer_user_id —
	 * тогда используется imopenlines.bot.session.transfer.
	 */
	private function escalate(int $chatId, string $reason, ?string $handoffText = null): void
	{
		Logger::info('openline', "Chat #{$chatId}: escalate to operator ({$reason})");

		$text = $handoffText ?? Config::get('ol_handoff_text', '');
		if ($text !== '')
		{
			$this->rest->callSafe('imopenlines.bot.session.message.send', [
				'CHAT_ID' => $chatId,
				'MESSAGE' => $text,
				'NAME' => 'DEFAULT',
			]);
		}

		$transferUserId = Config::getInt('transfer_user_id');
		if ($transferUserId > 0)
		{
			$this->rest->callSafe('imopenlines.bot.session.transfer', [
				'CHAT_ID' => $chatId,
				'USER_ID' => $transferUserId,
				'LEAVE' => 'Y',
			]);
		}
		else
		{
			// Перевод на первого доступного оператора очереди линии.
			$this->rest->callSafe('imopenlines.bot.session.operator', [
				'CHAT_ID' => $chatId,
			]);
		}

		// Сессия бота завершена — память чистим, чтобы следующая
		// сессия начиналась с чистого листа.
		$this->memory->clear('ol:' . $chatId);
	}

	/**
	 * Лид из диалога: создаём через imopenlines.crm.lead.create один раз
	 * на диалог (маркер в хранилище диалогов — REST не отдаёт привязки
	 * линии к CRM, а дублировать лиды на каждое сообщение нельзя).
	 */
	private function createLeadIfNeeded(int $chatId, bool $isNewDialog): void
	{
		if (!Config::isEnabled('ol_create_lead') || !$isNewDialog)
		{
			return;
		}

		$markerKey = 'ol:lead:' . $chatId;
		if ($this->memory->load($markerKey) !== [])
		{
			return;
		}

		[$okLead, $leadError] = $this->rest->callSafe('imopenlines.crm.lead.create', [
			'CHAT_ID' => $chatId,
		]);

		if ($okLead)
		{
			$this->memory->append($markerKey, 'assistant', date('d.m.Y H:i:s'));
		}

		Logger::info(
			'openline',
			"Chat #{$chatId}: lead creation " . ($okLead ? 'ok' : 'failed: ' . (string)$leadError)
		);
	}

	private function isLineAllowed(array $chat): bool
	{
		$allowed = Config::getCommaList('ol_lines');
		if ($allowed === [])
		{
			return true;
		}

		// Идентификатор линии — из entityId чата (config|ID|...).
		$entityId = explode('|', (string)($chat['entityId'] ?? ''));

		return isset($entityId[1]) && in_array($entityId[1], $allowed, true);
	}

	private function buildSystemPrompt(bool $hasContext, string $crmContext = ''): string
	{
		$prompt = Config::get('ol_system_prompt', '');

		if ($crmContext !== '')
		{
			$prompt .= "\n\n" . $crmContext;
		}

		$rules = "\n\nТы отвечаешь клиенту автоматически. Правила:\n"
			. "- Отвечай только на основе приведённого контекста базы знаний.\n"
			. "- Не выдумывай цены, сроки и условия, которых нет в контексте.\n"
			. '- Если ответа в контексте нет — выведи ровно ' . self::ESCALATE_MARKER
			. ' и ничего больше: диалог займёт оператор.';

		if (!$hasContext)
		{
			$rules .= "\n- Релевантных фрагментов не найдено.";
		}

		return $prompt . $rules;
	}

	/**
	 * Рабочие часы агента (портальное время): опция ol_work_hours вида
	 * «09:00-19:00» (пусто = круглосуточно), дни — ol_work_days
	 * (ISO-номера через запятую, по умолчанию пн–пт).
	 */
	private function isWithinWorkHours(): bool
	{
		$hours = trim(Config::get('ol_work_hours', ''));
		if ($hours === '')
		{
			return true;
		}

		$days = Config::getCommaList('ol_work_days');
		$workDays = $days === [] ? ['1', '2', '3', '4', '5'] : $days;
		if (!in_array(date('N'), $workDays, true))
		{
			return false;
		}

		if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $hours, $m))
		{
			return true;
		}

		$from = (int)$m[1] * 60 + (int)$m[2];
		$to = (int)$m[3] * 60 + (int)$m[4];
		$now = (int)date('G') * 60 + (int)date('i');

		return $now >= $from && $now < $to;
	}

	/**
	 * CRM-контекст диалога: сущности, привязанные к сессиям этой линии
	 * (активности провайдера IMOPENLINES_SESSION). Название — через
	 * crm.item.get. Агент «видит», что за клиент пишет и на какой стадии
	 * сделка, не спрашивая оператора.
	 */
	private function sessionCrmContext(int $chatId): string
	{
		global $DB;

		$query = $DB->Query(
			'SELECT bind.OWNER_TYPE_ID, bind.OWNER_ID
			 FROM b_crm_act act
			 JOIN b_crm_act_bind bind ON bind.ACTIVITY_ID = act.ID
			 JOIN b_imopenlines_session s ON s.ID = act.ASSOCIATED_ENTITY_ID
			 WHERE act.PROVIDER_ID = \'IMOPENLINES_SESSION\' AND s.CHAT_ID = ' . (int)$chatId . '
			 ORDER BY act.ID DESC
			 LIMIT 5'
		);

		$rows = [];
		while ($row = $query->Fetch())
		{
			$rows[] = $row;
		}

		if ($rows === [])
		{
			return '';
		}

		$typeNames = [1 => 'лид', 2 => 'сделка', 3 => 'контакт', 4 => 'компания'];
		$items = [];
		$seen = [];
		foreach ($rows as $row)
		{
			$typeId = (int)$row['OWNER_TYPE_ID'];
			$id = (int)$row['OWNER_ID'];
			if (!isset($typeNames[$typeId]) || isset($seen[$typeId . ':' . $id]))
			{
				continue;
			}
			$seen[$typeId . ':' . $id] = true;

			$title = '';
			[$ok, $entity] = $this->rest->callSafe('crm.item.get', [
				'entityTypeId' => $typeId,
				'id' => $id,
			]);
			if ($ok && is_array($entity))
			{
				$entity = (array)($entity['item'] ?? $entity);
				$title = (string)($entity['title'] ?? '');
			}

			$items[] = $typeNames[$typeId] . ' #' . $id . ($title !== '' ? ' «' . $title . '»' : '');
		}

		return $items === []
			? ''
			: 'Диалог привязан к CRM: ' . implode('; ', $items)
				. '. Учитывай это в ответах (обращайся к клиенту по контексту его сущности).';
	}
}
