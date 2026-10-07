<?php

namespace Mtai\Aiagent;

use Bitrix\Main\Security\Cipher;

/**
 * Шифрование секретов модуля (ключи API, токены REST-приложения).
 *
 * AES-256-CTR + SHA-256 через штатный \Bitrix\Main\Security\Cipher,
 * ключ — crypto_key из bitrix/.settings.php. В БД значения лежат
 * в base64-обёртке с префиксом enc1: пустая строка и null не шифруются.
 */
final class Secrets
{
	private const PREFIX = 'enc1:';

	private static function key(): string
	{
		$crypto = \Bitrix\Main\Config\Configuration::getInstance()->get('crypto');
		$key = (string)($crypto['crypto_key'] ?? '');
		if ($key === '')
		{
			throw new \RuntimeException('crypto_key is not configured in bitrix/.settings.php');
		}

		return $key;
	}

	public static function encrypt(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		try
		{
			$binary = (new Cipher())->encrypt($value, self::key());
		}
		catch (\Throwable $e)
		{
			throw new \RuntimeException('Cannot encrypt secret: ' . $e->getMessage(), 0, $e);
		}

		return self::PREFIX . base64_encode($binary);
	}

	public static function decrypt(string $stored): string
	{
		if ($stored === '' || !str_starts_with($stored, self::PREFIX))
		{
			// Значение записано без шифрования (или пусто) — отдаём как есть.
			return $stored;
		}

		$binary = base64_decode(substr($stored, strlen(self::PREFIX)), true);
		if ($binary === false)
		{
			return '';
		}

		try
		{
			return (new Cipher())->decrypt($binary, self::key());
		}
		catch (\Throwable)
		{
			return '';
		}
	}
}
