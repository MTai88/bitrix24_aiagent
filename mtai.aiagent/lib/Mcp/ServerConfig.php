<?php

namespace Mtai\Aiagent\Mcp;

use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Secrets;

/**
 * Список MCP-серверов модуля: разбор настроек (опция mcp_servers)
 * и «сырых» строк активности бизнес-процесса.
 *
 * Формат хранения — JSON в опции mcp_servers:
 * [{"name":"…","url":"https://…","api_key":"enc1:…","enabled":true}].
 * API-ключи шифруются через Secrets отдельно от остального JSON.
 *
 * Формат активности — одна строка на сервер:
 *   Имя | URL | API-ключ        (ключ необязателен)
 *   Имя = URL
 *   URL                              (имя берётся из хоста)
 * Строки с # в начале и пустые пропускаются.
 */
final class ServerConfig
{
	public const OPTION_NAME = 'mcp_servers';

	/**
	 * Серверы из настроек модуля (с расшифрованными ключами).
	 * Каждая запись содержит name/url/api_key и enabled.
	 * @param bool $enabledOnly только включённые
	 * @return array<int, array{name: string, url: string, api_key: string, enabled?: bool}>
	 */
	public static function moduleServers(bool $enabledOnly = false): array
	{
		$raw = Config::get(self::OPTION_NAME, '');
		if ($raw === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable $e)
		{
			Logger::warn('mcp', 'Опция mcp_servers не является JSON: ' . $e->getMessage());

			return [];
		}

		$result = [];
		foreach ((array)$decoded as $row)
		{
			$row = (array)$row;
			$enabled = !empty($row['enabled']);
			if ($enabledOnly && !$enabled)
			{
				continue;
			}

			$server = self::normalize(
				(string)($row['name'] ?? ''),
				(string)($row['url'] ?? ''),
				Secrets::decrypt((string)($row['api_key'] ?? ''))
			);
			if ($server !== null)
			{
				$server['enabled'] = $enabled;
				$result[] = $server;
			}
		}

		return $result;
	}

	/**
	 * Разбор многострочного поля активности БП.
	 * @return array<int, array{name: string, url: string, api_key: string}>
	 */
	public static function parseRaw(string $raw): array
	{
		$result = [];
		foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line)
		{
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#'))
			{
				continue;
			}

			$name = '';
			$url = '';
			$apiKey = '';

			if (str_contains($line, '|'))
			{
				$parts = array_map('trim', explode('|', $line, 3));
				[$name, $url] = [$parts[0] ?? '', $parts[1] ?? ''];
				$apiKey = $parts[2] ?? '';
			}
			elseif (str_contains($line, '='))
			{
				[$name, $url] = array_map('trim', explode('=', $line, 2));
			}
			else
			{
				$url = $line;
			}

			$server = self::normalize($name, $url, $apiKey);
			if ($server === null)
			{
				Logger::warn('mcp', 'Активность БП: пропущена строка MCP без URL: ' . $line);

				continue;
			}

			$result[] = $server;
		}

		return self::unique($result);
	}

	/**
	 * Серверы для чата сотрудников: мастер-выключатель «Включить в чате»
	 * (mcp_enabled) + галочки «Вкл.» у серверов. Активности БП этот
	 * режим не касается — там серверы выбираются параметром активности.
	 * @return array<int, array{name: string, url: string, api_key: string}>
	 */
	public static function serversForChat(): array
	{
		if (!Config::isEnabled('mcp_enabled'))
		{
			return [];
		}

		return self::moduleServers(true);
	}

	/**
	 * Серверы для запуска активности БП по выбору дизайнера: имена из
	 * параметра-мультиселекта резолвятся в добавленные серверы модуля
	 * (галочка «Вкл.» — чатовая, здесь не учитывается; мастер-выключатель
	 * чата тоже). Строка (шаблоны старого текстового поля) разбирается
	 * через parseRaw — обратная совместимость со старыми шаблонами.
	 * Не найденные имена пропускаются с записью в журнал.
	 * @param string|array $selection выбранные имена серверов
	 * @return array<int, array{name: string, url: string, api_key: string}>
	 */
	public static function serversForActivity(string|array $selection): array
	{
		$result = [];

		// Строка — старое текстовое поле активности (обратная совместимость).
		if (is_string($selection))
		{
			$raw = trim($selection);
			if ($raw !== '')
			{
				$result = self::parseRaw($raw);
			}
			$selection = [];
		}

		$selectedNames = [];
		foreach ($selection as $item)
		{
			$name = trim((string)$item);
			if ($name !== '')
			{
				$selectedNames[] = $name;
			}
		}

		if ($selectedNames !== [])
		{
			$all = self::moduleServers(false);
			foreach ($selectedNames as $name)
			{
				$found = false;
				foreach ($all as $server)
				{
					if ($server['name'] === $name)
					{
						$result[] = $server;
						$found = true;
					}
				}
				if (!$found)
				{
					Logger::warn('mcp', "Активность БП: выбранный сервер «{$name}» не найден в настройках модуля — пропущен");
				}
			}
		}

		return self::unique($result);
	}

	/**
	 * Подготовка списка к записи в опцию: API-ключи шифруются.
	 * @param array<int, array{name?: string, url?: string, api_key?: string, enabled?: bool}> $servers
	 */
	public static function encodeForStorage(array $servers): array
	{
		$result = [];
		foreach ($servers as $row)
		{
			$server = self::normalize(
				trim((string)($row['name'] ?? '')),
				trim((string)($row['url'] ?? '')),
				trim((string)($row['api_key'] ?? ''))
			);
			if ($server === null)
			{
				continue;
			}

			$result[] = [
				'name' => $server['name'],
				'url' => $server['url'],
				'api_key' => Secrets::encrypt($server['api_key']),
				'enabled' => !empty($row['enabled']),
			];
		}

		return $result;
	}

	/**
	 * Проверка и дополнение полей одного сервера.
	 * @return array{name: string, url: string, api_key: string}|null
	 */
	private static function normalize(string $name, string $url, string $apiKey): ?array
	{
		$url = trim($url);
		if ($url === '' || !preg_match('#^https?://#i', $url))
		{
			return null;
		}

		$name = trim($name);
		if ($name === '')
		{
			$name = (string)parse_url($url, PHP_URL_HOST);
			if ($name === '' || $name === false)
			{
				$name = 'srv';
			}
		}

		return [
			'name' => mb_substr($name, 0, 64),
			'url' => $url,
			'api_key' => trim($apiKey),
		];
	}

	/**
	 * Дедупликация по паре «имя + URL» (активность может дублировать
	 * сервер из настроек модуля — двойной набор инструментов не нужен).
	 */
	private static function unique(array $servers): array
	{
		$result = [];
		foreach ($servers as $server)
		{
			$key = $server['name'] . '|' . $server['url'];
			$result[$key] = $server;
		}

		return array_values($result);
	}
}
