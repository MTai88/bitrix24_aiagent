<?php

namespace Mtai\Aiagent;

use Bitrix\Main\Config\Option;

/**
 * Доступ к настройкам модуля.
 *
 * Секреты читаются через Secrets (расшифровка + приоритет переменных
 * окружения), остальные опции — напрямую из Option.
 */
final class Config
{
	public const MODULE_ID = 'mtai.aiagent';

	// Секретные опции: значения шифруются при записи в БД.
	public const SECRET_OPTIONS = [
		'llm_api_key',
		'app_client_secret',
		'app_refresh_token',
		'app_access_token',
		'webhook_password',
		'gigachat_access_token',
	];

	/**
	 * Обычная опция модуля.
	 *
	 * Сначала значение из БД, затем default_option.php модуля,
	 * и только потом — дефолт вызывающего кода. Дефолт вызывающего
	 * нельзя передавать прямо в Option::get: непустое значение
	 * отключает загрузку default_option.php.
	 */
	public static function get(string $name, ?string $default = null): string
	{
		$value = Option::get(self::MODULE_ID, $name, '');
		if ($value !== '')
		{
			return $value;
		}

		return $default ?? '';
	}

	/** Обычная опция как список ID/строк через запятую. */
	public static function getCommaList(string $name): array
	{
		$raw = self::get($name, '');

		return array_values(array_filter(array_map('trim', explode(',', $raw))));
	}

	/** Обычная опция как целое. */
	public static function getInt(string $name, int $default = 0): int
	{
		return (int)self::get($name, (string)$default);
	}

	/** Подмена llm_timeout на время одного вызова (поиск в шапке и т.п.). */
	private static ?int $httpTimeoutOverride = null;

	/**
	 * Таймаут HTTP к LLM: временная подмена, иначе опция llm_timeout.
	 */
	public static function httpTimeout(): int
	{
		if (self::$httpTimeoutOverride !== null && self::$httpTimeoutOverride > 0)
		{
			return self::$httpTimeoutOverride;
		}

		$value = self::getInt('llm_timeout', 45);

		return $value > 0 ? $value : 45;
	}

	/**
	 * Выполняет $callback с укороченным HTTP-таймаутом провайдеров LLM.
	 * Опция llm_timeout в базе не меняется.
	 */
	public static function withHttpTimeout(int $seconds, callable $callback): mixed
	{
		$previous = self::$httpTimeoutOverride;
		self::$httpTimeoutOverride = max(1, $seconds);
		try
		{
			return $callback();
		}
		finally
		{
			self::$httpTimeoutOverride = $previous;
		}
	}

	/** Обычная опция как float. */
	public static function getFloat(string $name, float $default = 0.0): float
	{
		return (float)str_replace(',', '.', self::get($name, (string)$default));
	}

	/** Опция-флаг Y/N. */
	public static function isEnabled(string $name): bool
	{
		return self::get($name, 'N') === 'Y';
	}

	/** Секретная опция: переменная окружения → расшифрованное значение из БД. */
	public static function getSecret(string $name): string
	{
		$envMap = [
			'llm_api_key' => 'MTAI_AIAGENT_LLM_API_KEY',
			'app_client_secret' => 'MTAI_AIAGENT_APP_CLIENT_SECRET',
			'app_refresh_token' => 'MTAI_AIAGENT_APP_REFRESH_TOKEN',
			'webhook_password' => 'MTAI_AIAGENT_WEBHOOK_PASSWORD',
		];

		$envName = $envMap[$name] ?? null;
		if ($envName !== null)
		{
			$env = (string)getenv($envName);
			if ($env !== '')
			{
				return $env;
			}
		}

		$stored = Option::get(self::MODULE_ID, $name, '');

		return Secrets::decrypt($stored);
	}

	/** Запись опции: секреты шифруются. */
	public static function set(string $name, string $value): void
	{
		if (in_array($name, self::SECRET_OPTIONS, true))
		{
			$value = Secrets::encrypt($value);
		}

		Option::set(self::MODULE_ID, $name, $value);
	}

	/** Адрес портала для REST-вызовов и OAuth. */
	public static function portalUrl(): string
	{
		$url = rtrim(self::get('portal_url', ''), '/');
		if ($url === '')
		{
			$url = rtrim(self::detectPortalUrl(), '/');
		}

		return $url;
	}

	/** Определение адреса портала: текущий хит → сайт по умолчанию из БД. */
	public static function detectPortalUrl(): string
	{
		$scheme = (new \Bitrix\Main\Web\Uri(''))->getScheme();
		if (!empty($_SERVER['HTTP_HOST']))
		{
			$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';

			return $scheme . '://' . $_SERVER['HTTP_HOST'];
		}

		// CLI: берём сайт по умолчанию (b_lang), для тестового стенда это bitrix.local
		try
		{
			$site = \Bitrix\Main\SiteTable::getList([
				'select' => ['SERVER_NAME', 'DIR'],
				'filter' => ['=DEF' => 'Y', '=ACTIVE' => 'Y'],
				'limit' => 1,
			])->fetch();
			if ($site && !empty($site['SERVER_NAME']))
			{
				return 'https://' . $site['SERVER_NAME'];
			}
		}
		catch (\Throwable)
		{
		}

		return '';
	}
}
