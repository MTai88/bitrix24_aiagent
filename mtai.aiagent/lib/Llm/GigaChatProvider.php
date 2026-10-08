<?php

namespace Mtai\Aiagent\Llm;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\LlmException;
use Mtai\Aiagent\Logger;

/**
 * Провайдер GigaChat (Сбер).
 *
 * В поле «API-ключ» вставляется НЕ access-токен (он живёт ~30 минут),
 * а authorization key — Base64(client_id:client_secret) из кабинета
 * GigaChat API keys, бессрочный. Модуль сам:
 *   1) обменяет authorization key на access-токен через OAuth-эндпоинт
 *      Сбера (POST /api/v2/oauth, Basic-авторизация + RqUID);
 *   2) закеширует токен в опциях модуля (зашифрованным) и переиспользует,
 *      пока не истечёт expires_at (с запасом 2 минуты);
 *   3) при HTTP 401 сбросит кеш и обменяет токен заново (один ретрай).
 *
 * SSL-сертификаты российского CA: при ошибках проверки цепочки выключите
 * опцию «Проверять SSL» — она действует и на OAuth-эндпоинт.
 * Формат /api/v1/chat/completions совместим с OpenAI (наследует
 * OpenAiProvider), кроме function calling: GigaChat ждёт functions/function_call
 * и отвечает message.function_call — адаптация в toolRequestParams/
 * parseToolCalls/prepareMessages.
 */
class GigaChatProvider extends OpenAiProvider
{
	public const DEFAULT_BASE_URL = 'https://gigachat.devices.sberbank.ru/api/v1';

	private const OAUTH_URL = 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';
	public const DEFAULT_SCOPE = 'GIGACHAT_API_PERS';
	private const TOKEN_EXPIRY_GUARD = 120; // секунд запаса до истечения токена

	public static function code(): string
	{
		return 'gigachat';
	}

	public function title(): string
	{
		return 'GigaChat (по authorization key; токен получается автоматически)';
	}

	public function supportsTools(): bool
	{
		return true;
	}

	/**
	 * Эмбеддинги доступны не на всех тарифах GigaChat API: модель указывается
	 * в опции llm_embedding_model (актуальные: Embeddings, EmbeddingsGigaR;
	 * на GIGACHAT_API_PERS без пакета API отвечает 402 Payment Required).
	 * Опция пуста — RAG работает на встроенном эмбеддере.
	 */
	public function supportsEmbedding(): bool
	{
		return Config::get('llm_embedding_model', '') !== '';
	}

	/** Основной вызов: при 401 сбрасываем токен и повторяем один раз. */
	public function chat(array $messages, array $tools = [], array $options = []): array
	{
		try
		{
			return parent::chat($messages, $tools, $options);
		}
		catch (LlmException $e)
		{
			if (!str_contains($e->getMessage(), 'HTTP 401'))
			{
				throw $e;
			}

			Logger::info('llm', 'GigaChat: 401, refreshing access token');
			self::dropAccessToken();

			return parent::chat($messages, $tools, $options);
		}
	}

	/**
	 * Нативный function calling у GigaChat v1 в СВОЁМ формате (не OpenAI):
	 * запрос — functions + function_call, ответ — message.function_call с
	 * аргументами объектом, результат — сообщение с ролью function.
	 * functions_state_id не требуется: продолжение работает и без него.
	 */
	protected function prepareMessages(array $messages): array
	{
		$out = [];
		foreach ($messages as $item)
		{
			$message = (array)$item;
			$role = (string)($message['role'] ?? '');

			if ($role === 'assistant' && isset($message['tool_calls']))
			{
				// OpenAI-форма (возможно несколько вызовов) → один function_call:
				// GigaChat v1 умеет ровно один вызов на сообщение.
				$calls = (array)$message['tool_calls'];
				$first = (array)reset($calls);
				$function = (array)($first['function'] ?? []);
				$arguments = $function['arguments'] ?? [];
				if (is_string($arguments))
				{
					try
					{
						$arguments = Json::decode($arguments);
					}
					catch (\Throwable)
					{
						$arguments = [];
					}
				}

				$out[] = [
					'role' => 'assistant',
					'content' => (string)($message['content'] ?? ''),
					'function_call' => [
						'name' => (string)($function['name'] ?? ''),
						'arguments' => (array)$arguments,
					],
				];
				continue;
			}

			if ($role === 'tool')
			{
				$out[] = ['role' => 'function', 'content' => (string)($message['content'] ?? '')];
				continue;
			}

			$out[] = $message;
		}

		return $out;
	}

	/** OpenAI tools {type:function, function:{…}} → GigaChat functions + function_call. */
	protected function toolRequestParams(array $tools): array
	{
		$functions = [];
		foreach ($tools as $tool)
		{
			$definition = (array)((array)$tool['function'] ?? $tool);
			$functions[] = [
				'name' => (string)($definition['name'] ?? ''),
				'description' => (string)($definition['description'] ?? ''),
				'parameters' => (array)($definition['parameters'] ?? ['type' => 'object', 'properties' => []]),
			];
		}

		return ['functions' => $functions, 'function_call' => 'auto'];
	}

	/** Ответ: message.function_call (аргументы объектом) → единая форма toolCalls. */
	protected function parseToolCalls(array $message): array
	{
		$call = (array)($message['function_call'] ?? []);
		if ($call === [])
		{
			return [];
		}

		$arguments = $call['arguments'] ?? [];
		if (is_string($arguments))
		{
			try
			{
				$arguments = Json::decode($arguments);
			}
			catch (\Throwable)
			{
				$arguments = ['_raw' => $arguments];
			}
		}

		return [[
			'id' => uniqid('call_', true),
			'name' => (string)($call['name'] ?? ''),
			'arguments' => (array)$arguments,
		]];
	}

	public function embed(array $texts): array
	{
		try
		{
			return parent::embed($texts);
		}
		catch (LlmException $e)
		{
			if (!str_contains($e->getMessage(), 'HTTP 401'))
			{
				throw $e;
			}

			self::dropAccessToken();

			return parent::embed($texts);
		}
	}

	protected function authHeader(): string
	{
		return 'Bearer ' . $this->getAccessToken();
	}

	/** Действующий access-токен: из кеша или свежий по authorization key. */
	private function getAccessToken(): string
	{
		$token = Config::getSecret('gigachat_access_token');
		$expires = Config::getInt('gigachat_token_expires');

		if ($token !== '' && $expires > time() + self::TOKEN_EXPIRY_GUARD)
		{
			return $token;
		}

		return $this->fetchAccessToken();
	}

	private function fetchAccessToken(): string
	{
		$authKey = Config::getSecret('llm_api_key');
		if ($authKey === '')
		{
			throw new LlmException('GigaChat: не задан authorization key (поле «API-ключ» в настройках)');
		}

		$http = new HttpClient([
			'socketTimeout' => 15,
			'streamTimeout' => 15,
			'version' => '1.1',
		]);
		$http->setHeader('Content-Type', 'application/x-www-form-urlencoded');
		$http->setHeader('Accept', 'application/json');
		$http->setHeader('RqUID', self::uuid4());
		$http->setHeader('Authorization', 'Basic ' . $authKey);
		if (Config::get('llm_ssl_verify', 'Y') !== 'Y')
		{
			$http->disableSslVerification();
		}

		$scope = trim(Config::get('gigachat_scope', '')) ?: self::DEFAULT_SCOPE;
		$raw = $http->post(self::OAUTH_URL, 'scope=' . urlencode($scope));
		$status = (int)$http->getStatus();

		$decoded = is_string($raw) ? (array)Json::decode($raw) : [];

		if ($status < 200 || $status >= 300 || empty($decoded['access_token']))
		{
			$error = (string)($decoded['error_message'] ?? ($decoded['message'] ?? ($raw ?: "HTTP {$status}")));
			Logger::error('llm', "GigaChat OAuth failed (HTTP {$status}): " . mb_substr($error, 0, 300));

			throw new LlmException(
				'GigaChat: не удалось получить access-токен (HTTP ' . $status . '): ' . mb_substr($error, 0, 300)
			);
		}

		// expires_at приходит в миллисекундах.
		$expiresAt = isset($decoded['expires_at']) ? (int)((int)$decoded['expires_at'] / 1000) : (time() + 1800);

		Config::set('gigachat_access_token', (string)$decoded['access_token']);
		Config::set('gigachat_token_expires', (string)$expiresAt);

		Logger::info('llm', 'GigaChat access token received, expires at ' . date('d.m.Y H:i:s', $expiresAt));

		return (string)$decoded['access_token'];
	}

	private static function dropAccessToken(): void
	{
		Config::set('gigachat_access_token', '');
		Config::set('gigachat_token_expires', '0');
	}

	private static function uuid4(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
