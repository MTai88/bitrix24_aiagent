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
 * OpenAiProvider); нативный function calling не используется — инструменты
 * работают через JSON-протокол.
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
		return false;
	}

	/** У GigaChat нет OpenAI-совместимого /embeddings — RAG работает на встроенном эмбеддере. */
	public function supportsEmbedding(): bool
	{
		return false;
	}

	/** Основной вызов: при 401 сбрасываем токен и повторяем один раз. */
	public function chat(array $messages, array $tools = [], array $options = []): array
	{
		try
		{
			return parent::chat($messages, [], $options);
		}
		catch (LlmException $e)
		{
			if (!str_contains($e->getMessage(), 'HTTP 401'))
			{
				throw $e;
			}

			Logger::info('llm', 'GigaChat: 401, refreshing access token');
			self::dropAccessToken();

			return parent::chat($messages, [], $options);
		}
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
