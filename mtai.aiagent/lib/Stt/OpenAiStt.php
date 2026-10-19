<?php

namespace Mtai\Aiagent\Stt;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;

/** POST {llm_base_url}/audio/transcriptions, тот же ключ, что у чата. */
final class OpenAiStt implements SttInterface
{
	public function isAvailable(): bool
	{
		return Config::getSecret('llm_api_key') !== '';
	}

	public function transcribe(string $path, string $mime): string
	{
		$apiKey = Config::getSecret('llm_api_key');
		if ($apiKey === '')
		{
			throw new \RuntimeException('Ключ API не задан');
		}
		if (!is_file($path))
		{
			throw new \RuntimeException('Файл записи не найден');
		}

		$handle = fopen($path, 'rb');
		if ($handle === false)
		{
			throw new \RuntimeException('Не удалось прочитать запись');
		}

		$model = Config::get('voice_model', 'whisper-1');
		if ($model === '')
		{
			$model = 'whisper-1';
		}

		$timeout = Config::httpTimeout();
		$http = new HttpClient([
			'connectTimeout' => $timeout,
			'socketTimeout' => $timeout,
			'streamTimeout' => $timeout,
			'version' => '1.1',
		]);
		$http->setHeader('Authorization', 'Bearer ' . $apiKey);
		if (Config::get('llm_ssl_verify', 'Y') !== 'Y')
		{
			$http->disableSslVerification();
		}

		$baseUrl = rtrim(Config::get('llm_base_url', 'https://api.openai.com/v1'), '/');
		try
		{
			$raw = $http->post($baseUrl . '/audio/transcriptions', [
				'file' => [
					'resource' => $handle,
					'filename' => basename($path),
					'contentType' => $mime !== '' ? $mime : 'audio/wav',
				],
				'model' => $model,
				'language' => 'ru',
			], true);
		}
		finally
		{
			if (is_resource($handle))
			{
				fclose($handle);
			}
		}

		return $this->textFrom($http, $raw, 'OpenAI');
	}

	private function textFrom(HttpClient $http, string|bool $raw, string $name): string
	{
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
			$error = (string)($decoded['error']['message'] ?? ($raw ?: "HTTP {$status}"));
			Logger::error('stt', "{$name} transcription error (HTTP {$status}): " . mb_substr($error, 0, 300));

			throw new \RuntimeException("{$name} (HTTP {$status}): " . mb_substr($error, 0, 200));
		}

		return trim((string)($decoded['text'] ?? ''));
	}
}
