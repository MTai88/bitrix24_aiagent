<?php

namespace Mtai\Aiagent\Chat;

/**
 * Голосовое, которое отправила кнопка модуля: файл ai-voice-*.wav у сообщения.
 * Прочие аудио (мобильное приложение и чужие файлы) не берём.
 */
final class VoiceMessage
{
	/**
	 * @return array{path: string, name: string}|null
	 */
	public static function extract(int $messageId): ?array
	{
		if ($messageId <= 0)
		{
			return null;
		}
		if (!\Bitrix\Main\Loader::includeModule('im') || !\Bitrix\Main\Loader::includeModule('disk'))
		{
			return null;
		}

		$files = (new \Bitrix\Im\V2\Message($messageId))->getFiles();
		foreach ($files as $fileItem)
		{
			$diskFile = $fileItem->getDiskFile();
			if ($diskFile === null)
			{
				continue;
			}

			$name = (string)$diskFile->getName();
			$lower = mb_strtolower($name);
			if (!str_starts_with($lower, 'ai-voice-') || !str_contains($lower, '.wav'))
			{
				continue;
			}

			$fileArray = \CFile::MakeFileArray((int)$diskFile->getFileId());
			$path = (string)($fileArray['tmp_name'] ?? '');
			if ($path === '' || !is_readable($path))
			{
				continue;
			}

			return ['path' => $path, 'name' => $name];
		}

		return null;
	}
}
