<?php

namespace Mtai\Aiagent\Handler;

use Mtai\Aiagent\Bot\Manager;
use Mtai\Aiagent\Conversation\Store;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Orm\ChunkTable;
use Mtai\Aiagent\Orm\JobTable;
use Mtai\Aiagent\Rest\Client;

/**
 * Слэш-команды бота (imbot.v2.Command, события ONIMBOTV2COMMANDADD):
 * /ai-help — что умеет агент; /ai-reset — сбросить память диалога;
 * /ai-status — состояние модуля. Ответ — imbot.v2.Command.answer.
 */
final class CommandHandler
{
	private Client $rest;
	private Store $memory;

	public function __construct(?Client $rest = null, ?Store $memory = null)
	{
		$this->rest = $rest ?? new Client();
		$this->memory = $memory ?? new Store();
	}

	public function handle(array $data): void
	{
		$command = (array)($data['command'] ?? []);
		$name = ltrim((string)($command['command'] ?? ''), '/');
		$chat = (array)($data['chat'] ?? []);
		$user = (array)($data['user'] ?? []);
		$message = (array)($data['message'] ?? []);

		$chatId = (int)($chat['id'] ?? 0);
		$userId = (int)($user['id'] ?? 0);
		$dialogId = (string)($chat['dialogId'] ?? '');
		if ($dialogId === '' && $userId > 0)
		{
			$dialogId = (string)$userId;
		}

		Logger::info('command', "Command /{$name} from user #{$userId}");

		$reply = match ($name)
		{
			'ai-help' => $this->helpText(),
			'ai-reset' => $this->resetDialog($chat, $userId),
			'ai-status' => $this->statusText(),
			'ai-add' => $this->addKnowledge($message),
			default => null,
		};

		if ($reply === null || $chatId <= 0)
		{
			return;
		}

		$this->answer(
			(int)($command['id'] ?? 0),
			(int)($message['id'] ?? 0),
			$dialogId,
			$reply
		);
	}

	private function helpText(): string
	{
		return "Я AI-агент. Умею:\n"
			. "— отвечать по базе знаний компании (просто спросите);\n"
			. "— создавать/искать/изменять лиды, сделки, контакты, компании;\n"
			. "— ставить задачи с привязкой к CRM и без, показывать мои задачи, завершать их;\n"
			. "— показывать мой календарь, создавать и удалять события и встречи;\n"
			. "— писать комментарии в таймлайн карточек CRM;\n"
			. "— откладывать поручения: «запусти рассылку через два часа», «завтра в 9 напомни…» — выполню в срок и доложу о результате здесь и в колокольчик;\n"
			. "— присылать уведомления в колокольчик.\n\n"
			. "Команды:\n"
			. "/ai-help — эта справка\n"
			. "/ai-add — добавить в базу знаний документ, приложенный к этому сообщению\n"
			. "/ai-reset — забыть контекст диалога\n"
			. "/ai-status — состояние модуля";
	}

	/**
	 * /ai-add: индексация файлов, приложенных к сообщению с командой,
	 * в базу знаний. Файлы читаются нативно (модуль работает внутри
	 * портала) через \Bitrix\Im\V2\Message->getFiles().
	 */
	private function addKnowledge(array $message): string
	{
		$messageId = (int)($message['id'] ?? 0);
		if ($messageId <= 0)
		{
			return 'Не вижу сообщения с командой — приложите файл и повторите.';
		}

		if (!\Bitrix\Main\Loader::includeModule('im') || !\Bitrix\Main\Loader::includeModule('disk'))
		{
			return 'Модули im/disk недоступны — пополнение базы знаний невозможно.';
		}

		$imMessage = new \Bitrix\Im\V2\Message($messageId);
		$files = $imMessage->getFiles();
		if ($files->count() === 0)
		{
			return 'К сообщению нет файла. Приложите документ (PDF/DOCX/TXT/MD/CSV) '
				. 'и напишите в сообщении /ai-add — я добавлю его в базу знаний.';
		}

		$service = new \Mtai\Aiagent\Rag\DocumentService();
		$lines = [];
		$index = 0;
		foreach ($files as $fileItem)
		{
			$index++;
			$diskFile = $fileItem->getDiskFile();
			if ($diskFile === null)
			{
				$lines[] = "{$index}. файл недоступен";

				continue;
			}

			$fileArray = \CFile::MakeFileArray($diskFile->getFileId());
			if ($fileArray === null || empty($fileArray['tmp_name']))
			{
				$lines[] = "{$index}. не удалось прочитать файл";

				continue;
			}
			$fileArray['name'] = $diskFile->getName();

			try
			{
				$documentId = $service->upload($fileArray);
				$document = \Mtai\Aiagent\Orm\DocumentTable::getById($documentId)->fetch();
				$lines[] = sprintf(
					'%d. «%s» — проиндексирован, %d фрагментов',
					$index,
					$diskFile->getName(),
					(int)($document['CHUNKS'] ?? 0)
				);
			}
			catch (\Throwable $e)
			{
				$lines[] = sprintf('%d. «%s» — ошибка: %s', $index, $diskFile->getName(), mb_substr($e->getMessage(), 0, 200));
			}
		}

		return "База знаний пополнена:\n" . implode("\n", $lines);
	}

	private function resetDialog(array $chat, int $userId): string
	{
		if (($chat['type'] ?? '') === 'private')
		{
			$this->memory->clear('im:' . Manager::botUserId() . ':' . $userId);
		}
		else
		{
			$this->memory->clear('ol:' . (int)($chat['id'] ?? 0));
		}

		return 'Память диалога сброшена — начинаем с чистого листа.';
	}

	private function statusText(): string
	{
		$jobs = [];
		foreach ([JobTable::STATUS_NEW, JobTable::STATUS_ERROR] as $status)
		{
			$jobs[$status] = (int)JobTable::getCount(['=STATUS' => $status]);
		}

		return "Состояние AI-агента:\n"
			. '— бот: ' . (Manager::isRegistered() ? 'зарегистрирован (id ' . Manager::botUserId() . ')' : 'не зарегистрирован') . "\n"
			. '— база знаний: ' . (int)ChunkTable::getCount() . " фрагментов\n"
			. '— очередь: новых ' . $jobs[JobTable::STATUS_NEW] . ', ошибок ' . $jobs[JobTable::STATUS_ERROR] . "\n"
			. '— события: последний опрос ' . (\Mtai\Aiagent\Config::get('last_fetch_time') ?: '—');
	}

	private function answer(int $commandId, int $messageId, string $dialogId, string $text): void
	{
		$params = [
			'botId' => Manager::botUserId(),
			'commandId' => $commandId,
			'messageId' => $messageId,
			'dialogId' => $dialogId,
			'fields' => ['message' => $text],
		];

		[$ok] = $this->rest->callSafe('imbot.v2.Command.answer', $params);

		if (!$ok)
		{
			// Ответ на команду не прошёл (например, команда перерегистрирована) —
			// отправляем обычным сообщением.
			unset($params['commandId'], $params['messageId']);
			$this->rest->callSafe('imbot.v2.Chat.Message.send', $params);
		}
	}
}
