<?php

namespace Mtai\Aiagent\Controller;

use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Mtai\Aiagent\Chat\VoicePublisher;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Handler\ChatHandler;
use Mtai\Aiagent\Stt\Factory;
use Mtai\Aiagent\Stt\Limits;
use Mtai\Aiagent\Stt\Wav;

/** Приём WAV из кнопки микрофона: файл уходит в чат с ботом от имени сотрудника. */
final class Voice extends Controller
{
	protected function getDefaultPreFilters()
	{
		return [
			new Authentication(),
			new HttpMethod([HttpMethod::METHOD_POST]),
			new Csrf(),
		];
	}

	public function sendAction(): ?array
	{
		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		if (!Config::isEnabled('voice_enabled') || !ChatHandler::userAllowed($userId))
		{
			$this->addError(new Error('Голосовые сообщения недоступны', 'VOICE_DENIED'));

			return null;
		}
		if (!Factory::isAvailable())
		{
			$this->addError(new Error('Распознавание речи не настроено', 'VOICE_UNAVAILABLE'));

			return null;
		}

		$file = $this->getRequest()->getFile('file');
		if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']))
		{
			$this->addError(new Error('Запись не получена', 'VOICE_FILE'));

			return null;
		}
		if ((int)($file['size'] ?? 0) > Limits::maxBytes())
		{
			$this->addError(new Error('Слишком длинная запись', 'VOICE_LONG'));

			return null;
		}

		try
		{
			$parsed = Wav::parse((string)$file['tmp_name']);
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'VOICE_FORMAT'));

			return null;
		}
		if ($parsed['duration'] > Limits::maxSeconds() + 0.25)
		{
			$this->addError(new Error('Слишком длинная запись', 'VOICE_LONG'));

			return null;
		}

		try
		{
			$messageId = VoicePublisher::publish($userId, (string)$file['tmp_name']);
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'VOICE_SEND'));

			return null;
		}

		return ['messageId' => $messageId];
	}
}
