<?php

namespace Mtai\Aiagent\Chat;

use Mtai\Aiagent\Bot\Manager;

/**
 * Кладёт WAV в личный чат сотрудника с ботом от имени сотрудника.
 * Имя ai-voice-*.wav — признак, по которому бот забирает запись на распознавание.
 */
final class VoicePublisher
{
	public static function publish(int $userId, string $sourcePath): int
	{
		if (!is_file($sourcePath))
		{
			throw new \RuntimeException('Файл записи не найден');
		}
		if (!\Bitrix\Main\Loader::includeModule('im') || !\Bitrix\Main\Loader::includeModule('disk'))
		{
			throw new \RuntimeException('Модули чата или диска недоступны');
		}

		$botId = Manager::botUserId();
		if ($botId <= 0)
		{
			throw new \RuntimeException('Бот не зарегистрирован');
		}

		$chatId = (int)\CIMMessage::GetChatId($userId, $botId);
		if ($chatId <= 0)
		{
			throw new \RuntimeException('Не удалось открыть чат с ботом');
		}

		$name = 'ai-voice-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.wav';
		$fileId = (int)\CFile::SaveFile([
			'name' => $name,
			'size' => (int)filesize($sourcePath),
			'tmp_name' => $sourcePath,
			'type' => 'audio/wav',
			'MODULE_ID' => 'im',
		], 'im');
		if ($fileId <= 0)
		{
			throw new \RuntimeException('Не удалось сохранить запись');
		}

		$diskIds = \CIMDisk::UploadFileFromMain($chatId, [$fileId], $userId);
		if (!is_array($diskIds) || $diskIds === [])
		{
			\CFile::Delete($fileId);

			throw new \RuntimeException('Не удалось прикрепить запись к чату');
		}

		$messageId = \CIMMessage::Add([
			'TO_CHAT_ID' => $chatId,
			'FROM_USER_ID' => $userId,
			'MESSAGE_TYPE' => IM_MESSAGE_PRIVATE,
			'FILES' => $diskIds,
		]);
		if (!$messageId)
		{
			throw new \RuntimeException('Не удалось отправить голосовое в чат');
		}

		return (int)$messageId;
	}
}
