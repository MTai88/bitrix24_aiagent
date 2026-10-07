<?php

namespace Mtai\Aiagent\Llm;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\LlmException;
use Mtai\Aiagent\Logger;

/**
 * Провайдер YandexGPT (Yandex Foundation Models).
 *
 * Ключ — API-ключ сервиса Yandex Cloud (заголовок Api-Key),
 * каталог — опция llm_yandex_folder. Нативного function calling
 * в формат не заворачиваем: Tools работают через JSON-протокол
 * (описание инструментов в системном промте, ответ строго JSON).
 */
final class YandexProvider implements ProviderInterface
{
	private const API = 'https://llm.api.cloud.yandex.net/foundationModels/v1';

	public static function code(): string
	{
		return 'yandex';
	}

	public function title(): string
	{
		return 'YandexGPT';
	}

	public function supportsTools(): bool
	{
		return false;
	}

	public function supportsEmbedding(): bool
	{
		return true;
	}

	public function chat(array $messages, array $tools = [], array $options = []): array
	{
		$body = [
			'modelUri' => $this->modelUri('gpt', $options['model'] ?? null),
			'completionOptions' => [
				'temperature' => $options['temperature'] ?? Config::getFloat('llm_temperature', 0.2),
				'maxTokens' => (int)($options['maxTokens'] ?? Config::getInt('llm_max_tokens', 1500)),
			],
			'messages' => array_values(array_map(
				static fn(array $m) => [
					'role' => in_array($m['role'] ?? '', ['system', 'user', 'assistant'], true)
						? $m['role']
						: 'user',
					'text' => (string)($m['content'] ?? ''),
				],
				array_filter($messages, 'is_array')
			)),
		];

		$response = $this->request('/completion', $body);

		$text = (string)($response['result']['alternatives'][0]['message']['text'] ?? '');
		Logger::debug('llm', 'yandex completion: ' . mb_substr($text, 0, 200));

		return [
			'content' => $text !== '' ? $text : null,
			'toolCalls' => [],
			'usage' => (array)($response['result']['usage'] ?? []),
			'finishReason' => (string)($response['result']['alternatives'][0]['status'] ?? ''),
		];
	}

	public function embed(array $texts): array
	{
		$vectors = [];
		foreach ($texts as $text)
		{
			$response = $this->request('/text/embeddings', [
				'modelUri' => $this->modelUri('emb', Config::get('llm_embedding_model', 'text-search/latest')),
				'text' => (string)$text,
			]);

			$vector = array_map('floatval', (array)($response['embedding'] ?? []));
			OpenAiProvider::normalize($vector);
			$vectors[] = $vector;
		}

		return $vectors;
	}

	private function modelUri(string $family, ?string $model): string
	{
		$folder = Config::get('llm_yandex_folder', '');

		return $family . '://' . $folder . '/' . ($model ?? Config::get('llm_model', 'gpt'));
	}

	private function request(string $path, array $body): array
	{
		$apiKey = Config::getSecret('llm_api_key');
		if ($apiKey === '')
		{
			throw new LlmException('YandexGPT API key is not configured');
		}

		$http = new HttpClient([
			'socketTimeout' => Config::getInt('llm_timeout', 45),
			'streamTimeout' => Config::getInt('llm_timeout', 45),
			'version' => '1.1',
		]);
		$http->setHeader('Content-Type', 'application/json');
		$http->setHeader('Authorization', 'Api-Key ' . $apiKey);
		$http->setHeader('x-folder-id', Config::get('llm_yandex_folder', ''));

		$raw = $http->post(self::API . $path, Json::encode($body, JSON_UNESCAPED_UNICODE));
		$status = (int)$http->getStatus();

		$decoded = is_string($raw) ? Json::decode($raw) : null;
		$decoded = is_array($decoded) ? $decoded : [];

		if ($status < 200 || $status >= 300)
		{
			$error = (string)($decoded['error'] ?? ($decoded['message'] ?? ($raw ?: "HTTP {$status}")));
			Logger::error('llm', "YandexGPT error (HTTP {$status}): " . mb_substr($error, 0, 300));

			throw new LlmException("YandexGPT error (HTTP {$status}): " . mb_substr($error, 0, 300));
		}

		return $decoded;
	}
}
