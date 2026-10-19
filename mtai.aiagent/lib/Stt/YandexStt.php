<?php

namespace Mtai\Aiagent\Stt;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;

/**
 * Синхронный SpeechKit: сырой PCM без заголовка WAV, не дольше 30 секунд.
 * Ключ и каталог — те же, что у YandexGPT.
 */
final class YandexStt implements SttInterface
{
	private const API = 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize';

	public function isAvailable(): bool
	{
		return Config::getSecret('llm_api_key') !== ''
			&& Config::get('llm_yandex_folder', '') !== '';
	}

	public function transcribe(string $path, string $mime): string
	{
		$apiKey = Config::getSecret('llm_api_key');
		$folder = Config::get('llm_yandex_folder', '');
		if ($apiKey === '' || $folder === '')
		{
			throw new \RuntimeException('Ключ API или каталог Yandex Cloud не заданы');
		}

		$pcm = Wav::parse($path)['pcm'];
		if (strlen($pcm) > 1024 * 1024)
		{
			throw new \RuntimeException('Запись длиннее лимита SpeechKit (1 МБ)');
		}

		$timeout = Config::httpTimeout();
		$http = new HttpClient([
			'connectTimeout' => $timeout,
			'socketTimeout' => $timeout,
			'streamTimeout' => $timeout,
			'version' => '1.1',
		]);
		$http->setHeader('Authorization', 'Api-Key ' . $apiKey);
		$http->setHeader('Content-Type', 'application/octet-stream');

		$query = http_build_query([
			'folderId' => $folder,
			'lang' => 'ru-RU',
			'format' => 'lpcm',
			'sampleRateHertz' => (string)Wav::RATE,
		]);
		$raw = $http->post(self::API . '?' . $query, $pcm);
		$status = (int)$http->getStatus();

		$decoded = [];
		if (is_string($raw) && trim($raw) !== '')
		{
			try
			{
				$decoded = (array)Json::decode($raw);
			}
			catch (\Throwable)
			{
			}
		}

		if ($status < 200 || $status >= 300)
		{
			$error = (string)($decoded['error_message'] ?? ($decoded['message'] ?? ($raw ?: "HTTP {$status}")));
			Logger::error('stt', "Yandex STT error (HTTP {$status}): " . mb_substr($error, 0, 300));

			throw new \RuntimeException('Yandex SpeechKit (HTTP ' . $status . '): ' . mb_substr($error, 0, 200));
		}

		return trim((string)($decoded['result'] ?? ''));
	}
}
