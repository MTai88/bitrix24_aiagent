<?php

namespace Mtai\Aiagent\Llm;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\LlmException;
use Mtai\Aiagent\Logger;

/**
 * Провайдер OpenAI и любых OpenAI-совместимых API:
 * OpenAI, OpenRouter, локальные инференсы (vLLM, Ollama, LiteLLM).
 * Базовый URL задаётся опцией llm_base_url.
 */
class OpenAiProvider implements ProviderInterface
{
	public static function code(): string
	{
		return 'openai';
	}

	public function title(): string
	{
		return 'OpenAI / OpenAI-совместимый';
	}

	public function supportsTools(): bool
	{
		return true;
	}

	public function supportsEmbedding(): bool
	{
		return Config::get('llm_embedding_model', '') !== '';
	}

	public function chat(array $messages, array $tools = [], array $options = []): array
	{
		$body = [
			'model' => $options['model'] ?? Config::get('llm_model', 'gpt-4o-mini'),
			'messages' => $messages,
			'temperature' => $options['temperature'] ?? Config::getFloat('llm_temperature', 0.2),
		];

		$maxTokens = (int)($options['maxTokens'] ?? Config::getInt('llm_max_tokens', 1500));
		if ($maxTokens > 0)
		{
			$body['max_tokens'] = $maxTokens;
		}

		if ($tools !== [])
		{
			$body['tools'] = $tools;
			$body['tool_choice'] = 'auto';
		}

		$response = $this->request('/chat/completions', $body);
		$choice = (array)($response['choices'][0] ?? []);
		$message = (array)($choice['message'] ?? []);

		$toolCalls = [];
		foreach ((array)($message['tool_calls'] ?? []) as $call)
		{
			$call = (array)$call;
			$function = (array)($call['function'] ?? []);
			$arguments = [];
			if (!empty($function['arguments']))
			{
				try
				{
					$arguments = (array)Json::decode((string)$function['arguments']);
				}
				catch (\Throwable)
				{
					$arguments = ['_raw' => (string)$function['arguments']];
				}
			}

			$toolCalls[] = [
				'id' => (string)($call['id'] ?? uniqid('call_', true)),
				'name' => (string)($function['name'] ?? ''),
				'arguments' => $arguments,
			];
		}

		Logger::debug('llm', 'chat completion: ' . mb_substr((string)($message['content'] ?? ''), 0, 200));

		return [
			'content' => isset($message['content']) ? (string)$message['content'] : null,
			'toolCalls' => $toolCalls,
			'usage' => (array)($response['usage'] ?? []),
			'finishReason' => (string)($choice['finish_reason'] ?? ''),
		];
	}

	public function embed(array $texts): array
	{
		if ($texts === [])
		{
			return [];
		}

		$response = $this->request('/embeddings', [
			'model' => Config::get('llm_embedding_model', 'text-embedding-3-small'),
			'input' => array_values($texts),
		]);

		$byIndex = [];
		foreach ((array)($response['data'] ?? []) as $item)
		{
			$item = (array)$item;
			$byIndex[(int)$item['index']] = array_map('floatval', (array)$item['embedding']);
		}
		ksort($byIndex);

		$vectors = array_values($byIndex);

		foreach ($vectors as $vector)
		{
			self::normalize($vector);
		}

		return $vectors;
	}

	/** Нормировка вектора к единичной длине (на месте). */
	public static function normalize(array &$vector): void
	{
		$norm = sqrt((float)array_sum(array_map(static fn($v) => $v * $v, $vector)));
		if ($norm > 0)
		{
			$vector = array_map(static fn($v) => $v / $norm, $vector);
		}
	}

	/**
	 * Значение HTTP-заголовка Authorization для запросов к API.
	 * Провайдеры с собственным токен-менеджментом (GigaChat) переопределяют.
	 */
	protected function authHeader(): string
	{
		$apiKey = Config::getSecret('llm_api_key');
		if ($apiKey === '')
		{
			throw new LlmException('LLM API key is not configured');
		}

		return 'Bearer ' . $apiKey;
	}

	private function request(string $path, array $body): array
	{
		$baseUrl = rtrim(Config::get('llm_base_url', 'https://api.openai.com/v1'), '/');

		$http = new HttpClient([
			'socketTimeout' => Config::getInt('llm_timeout', 45),
			'streamTimeout' => Config::getInt('llm_timeout', 45),
			'version' => '1.1',
		]);
		$http->setHeader('Content-Type', 'application/json');
		$http->setHeader('Authorization', $this->authHeader());
		if (Config::get('llm_ssl_verify', 'Y') !== 'Y')
		{
			$http->disableSslVerification();
		}

		$raw = $http->post($baseUrl . $path, Json::encode($body, JSON_UNESCAPED_UNICODE));
		$status = (int)$http->getStatus();

		$decoded = is_string($raw) ? Json::decode($raw) : null;
		$decoded = is_array($decoded) ? $decoded : [];

		if ($status < 200 || $status >= 300)
		{
			$error = (string)($decoded['error']['message'] ?? ($decoded['detail'] ?? ($raw ?: "HTTP {$status}")));
			Logger::error('llm', "OpenAI-compatible API error (HTTP {$status}): " . mb_substr($error, 0, 300));

			throw new LlmException("LLM API error (HTTP {$status}): " . mb_substr($error, 0, 300));
		}

		return $decoded;
	}
}
