<?php

namespace Mtai\Aiagent\Rest;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\RestException;

/**
 * REST-клиент модуля. Два режима авторизации (опция rest_auth_mode):
 *
 * - oauth — локальное приложение: access-токен кешируется, обновляется
 *   по refresh-токену через {портал}/oauth/token/;
 * - webhook — входящий вебхук (b_rest_ap): вызов
 *   {портал}/rest/{userId}/{password}/{method}; для методов бота
 *   автоматически подставляется botToken (imbot.v2.*) и CLIENT_ID
 *   (imopenlines.*) — так бот, зарегистрированный вебхуком,
 *   опознаётся как «custom»-приложение.
 *
 * Все вызовы логируются в журнал модуля.
 */
final class Client
{
	public const AUTH_OAUTH = 'oauth';
	public const AUTH_WEBHOOK = 'webhook';

	private const TOKEN_EXPIRY_GUARD = 60; // секунд запаса до истечения access-токена

	public static function authMode(): string
	{
		return Config::get('rest_auth_mode', self::AUTH_OAUTH) === self::AUTH_WEBHOOK
			? self::AUTH_WEBHOOK
			: self::AUTH_OAUTH;
	}

	/** Готов ли клиент к работе. */
	public static function isConfigured(): bool
	{
		if (Config::portalUrl() === '')
		{
			return false;
		}

		if (self::authMode() === self::AUTH_WEBHOOK)
		{
			return Config::getInt('webhook_user_id') > 0 && Config::getSecret('webhook_password') !== '';
		}

		return Config::get('app_client_id', '') !== ''
			&& Config::getSecret('app_client_secret') !== ''
			&& Config::getSecret('app_refresh_token') !== '';
	}

	/**
	 * Вызов REST-метода. Бросает RestException при ошибке.
	 * В режиме OAuth при протухшем токене обновляет его и повторяет вызов.
	 */
	public function call(string $method, array $params = [])
	{
		if (self::authMode() === self::AUTH_WEBHOOK)
		{
			$params = $this->injectWebhookIdentity($method, $params);
			$response = $this->request($method, $params, null);

			return $this->unwrap($method, $params, $response);
		}

		$token = $this->getAccessToken();
		$response = $this->request($method, $params, $token);

		if (isset($response['error'])
			&& in_array($response['error'], ['expired_token', 'invalid_token', 'NO_AUTH_FOUND'], true)
		)
		{
			$this->dropAccessToken();
			$token = $this->getAccessToken();
			$response = $this->request($method, $params, $token);
		}

		return $this->unwrap($method, $params, $response);
	}

	/** Вызов без исключений: возвращает [ok, result|error]. */
	public function callSafe(string $method, array $params = []): array
	{
		try
		{
			return [true, $this->call($method, $params)];
		}
		catch (RestException $e)
		{
			return [false, $e->getMessage()];
		}
	}

	/** Токен-идентификатор бота для вебхук-режима. */
	public static function botToken(): string
	{
		return Config::getSecret('webhook_password');
	}

	private function injectWebhookIdentity(string $method, array $params): array
	{
		$token = self::botToken();

		if (str_starts_with($method, 'imbot.v2.'))
		{
			$params['botToken'] = $token;
		}
		elseif (str_starts_with($method, 'imopenlines.'))
		{
			$params['CLIENT_ID'] = $token;
		}

		return $params;
	}

	private function unwrap(string $method, array $params, array $response)
	{
		if (isset($response['error']))
		{
			$message = (string)($response['error_description'] ?? $response['error']);
			Logger::warn('rest', "REST {$method} error: {$message}", ['params' => $this->maskParams($params)]);

			throw new RestException("REST {$method}: {$message}", (string)$response['error']);
		}

		return $response['result'] ?? null;
	}

	private function request(string $method, array $params, ?string $accessToken): array
	{
		$portalUrl = Config::portalUrl();
		if ($portalUrl === '')
		{
			throw new RestException('Portal URL is not configured', 'PORTAL_URL_EMPTY');
		}

		if ($accessToken !== null)
		{
			$params['auth'] = $accessToken;
		}

		$http = new HttpClient([
			'socketTimeout' => 30,
			'streamTimeout' => 60,
			'version' => '1.1',
		]);
		$http->setHeader('Content-Type', 'application/json');

		// Самоподписанный сертификат стенда — проверку можно отключить
		// опцией portal_ssl_verify=N (по умолчанию выключена для стенда).
		if (Config::get('portal_ssl_verify', 'N') !== 'Y')
		{
			$http->disableSslVerification();
		}

		$url = $portalUrl . '/rest/' . $method;
		if ($accessToken === null)
		{
			// Вебхук: /rest/{userId}/{password}/{method}
			$url = sprintf(
				'%s/rest/%d/%s/%s',
				$portalUrl,
				Config::getInt('webhook_user_id'),
				self::botToken(),
				$method
			);
		}

		$raw = $http->post($url, Json::encode($params, JSON_UNESCAPED_UNICODE));
		$status = (int)$http->getStatus();

		Logger::debug('rest', "REST {$method} -> HTTP {$status}");

		if ($raw === false)
		{
			throw new RestException("REST {$method}: HTTP request failed, status {$status}", 'HTTP_ERROR');
		}

		try
		{
			$decoded = Json::decode((string)$raw);
		}
		catch (\Throwable)
		{
			throw new RestException("REST {$method}: malformed JSON response", 'BAD_JSON');
		}

		if (!is_array($decoded))
		{
			throw new RestException("REST {$method}: unexpected response type", 'BAD_RESPONSE');
		}

		return $decoded;
	}

	/** Действующий access-токен (из кеша или свежий по refresh-токену). */
	private function getAccessToken(): string
	{
		$token = Config::getSecret('app_access_token');
		$expires = Config::getInt('app_access_expires');

		if ($token !== '' && $expires > time() + self::TOKEN_EXPIRY_GUARD)
		{
			return $token;
		}

		return $this->refreshAccessToken();
	}

	private function dropAccessToken(): void
	{
		Config::set('app_access_token', '');
		Config::set('app_access_expires', '0');
	}

	/** Обмен refresh-токена на новую пару токенов (режим oauth). */
	public function refreshAccessToken(): string
	{
		if (self::authMode() === self::AUTH_WEBHOOK)
		{
			// Для вебхука «обновление» — проверка, что он вообще работает.
			$this->call('app.info');

			return '';
		}

		$portalUrl = Config::portalUrl();
		$clientId = Config::get('app_client_id');
		$clientSecret = Config::getSecret('app_client_secret');
		$refreshToken = Config::getSecret('app_refresh_token');

		if ($portalUrl === '' || $clientId === '' || $clientSecret === '' || $refreshToken === '')
		{
			throw new RestException('REST application credentials are not configured', 'APP_NOT_CONFIGURED');
		}

		$http = new HttpClient(['socketTimeout' => 15, 'streamTimeout' => 15, 'version' => '1.1']);
		$http->setHeader('Content-Type', 'application/x-www-form-urlencoded');

		$raw = $http->post(
			$portalUrl . '/oauth/token/',
			http_build_query([
				'grant_type' => 'refresh_token',
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'refresh_token' => $refreshToken,
			])
		);

		$response = is_string($raw) ? (array)Json::decode($raw) : [];

		if (empty($response['access_token']))
		{
			$error = (string)($response['error'] ?? 'unknown');
			Logger::error('rest', 'OAuth refresh failed: ' . $error);

			throw new RestException('OAuth token refresh failed: ' . $error, $error);
		}

		Config::set('app_access_token', (string)$response['access_token']);
		Config::set('app_access_expires', (string)(time() + (int)($response['expires_in'] ?? 3600)));

		// Bitrix ротирует refresh-токен при каждом обновлении — сохраняем новый.
		if (!empty($response['refresh_token']))
		{
			Config::set('app_refresh_token', (string)$response['refresh_token']);
		}

		Logger::debug('rest', 'OAuth token refreshed');

		return (string)$response['access_token'];
	}

	/** Параметры без секретов — для лога. */
	private function maskParams(array $params): array
	{
		foreach (['auth', 'client_secret', 'botToken', 'BOT_TOKEN'] as $key)
		{
			if (isset($params[$key]))
			{
				$params[$key] = '***';
			}
		}

		return $params;
	}
}
