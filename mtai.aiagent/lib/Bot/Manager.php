<?php

namespace Mtai\Aiagent\Bot;

use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Rest\Client;

/**
 * Регистрация чат-бота через imbot.v2.
 *
 * Бот создаётся в режиме eventMode=fetch (события забираются
 * imbot.v2.Event.get) и с поддержкой открытых линий, чтобы его можно
 * было добавить в очередь линии как оператора.
 */
final class Manager
{
	public static function botUserId(): int
	{
		return Config::getInt('bot_user_id');
	}

	/** Зарегистрирован ли бот (локально известно его user id). */
	public static function isRegistered(): bool
	{
		return self::botUserId() > 0;
	}

	/**
	 * Регистрация бота. Повторный вызов для уже существующего кода
	 * возвращает того же бота (так устроен imbot.v2.Bot.register).
	 *
	 * В вебхук-режиме бот привязывается к токену вебхука (botToken):
	 * дальнейшие вызовы imbot.v2./imopenlines. опознают бота по нему.
	 */
	public function register(): int
	{
		$client = new Client();

		$fields = [
			'code' => Config::get('bot_code', 'mtai.aiagent'),
			'type' => 'bot',
			'eventMode' => 'fetch',
			'isSupportOpenline' => 'Y',
			'isHidden' => 'N',
			'isReactionsEnabled' => 'Y',
			'properties' => [
				'name' => Config::get('bot_name', 'AI-агент'),
			],
		];

		if (Client::authMode() === Client::AUTH_WEBHOOK)
		{
			$fields['botToken'] = Client::botToken();
		}

		$result = $client->call('imbot.v2.Bot.register', ['fields' => $fields]);

		$botId = $this->extractBotId((array)$result);
		if ($botId <= 0)
		{
			throw new \RuntimeException('imbot.v2.Bot.register: bot id not found in response');
		}

		Config::set('bot_user_id', (string)$botId);

		$this->registerCommands();

		Logger::info('bot', "Bot registered, botId={$botId}");

		return $botId;
	}

	/**
	 * Слэш-команды бота (imbot.v2.Command): /ai-help, /ai-reset, /ai-status.
	 * id команд сохраняются в опции bot_command_ids — imbot.v2.Command.answer
	 * требует commandId.
	 */
	public function registerCommands(): void
	{
		$botId = self::botUserId();
		if ($botId <= 0)
		{
			return;
		}

		$commands = [
			'ai-help' => ['AI-агент: справка', 'AI agent: help'],
			'ai-reset' => ['AI-агент: забыть контекст диалога', 'AI agent: reset dialog memory'],
			'ai-status' => ['AI-агент: состояние модуля', 'AI agent: module status'],
			'ai-add' => ['AI-агент: добавить файл из сообщения в базу знаний', 'AI agent: add attached file to knowledge base'],
		];

		$client = new Client();

		foreach ($commands as $name => $titles)
		{
			$client->call('imbot.v2.Command.register', [
				'botId' => $botId,
				'fields' => [
					'command' => $name,
					'title' => ['ru' => $titles[0], 'en' => $titles[1]],
					'common' => 'N',
					'hidden' => 'N',
				],
			]);
		}

		// Ответ register не возвращает id команд — достаём из Command.list.
		$known = [];
		$list = (array)$client->call('imbot.v2.Command.list', ['botId' => $botId]);
		foreach ((array)($list['commands'] ?? []) as $command)
		{
			$command = (array)$command;
			$name = ltrim((string)($command['command'] ?? ''), '/');
			if (isset($commands[$name]) && (int)($command['botId'] ?? 0) === $botId)
			{
				$known[$name] = (int)$command['id'];
			}
		}

		Config::set('bot_command_ids', \Bitrix\Main\Web\Json::encode($known));
		Logger::info('bot', 'Bot commands registered: ' . implode(', ', array_keys($known)));
	}

	/** Обновление имени/свойств бота (имбот остаётся тот же). */
	public function update(): void
	{
		$botId = self::botUserId();
		if ($botId <= 0)
		{
			throw new \RuntimeException('Bot is not registered');
		}

		(new Client())->call('imbot.v2.Bot.update', [
			'botId' => $botId,
			'fields' => [
				'code' => Config::get('bot_code', 'mtai.aiagent'),
				'properties' => [
					'name' => Config::get('bot_name', 'AI-агент'),
				],
			],
		]);

		Logger::info('bot', "Bot updated, botId={$botId}");
	}

	/** Удаление бота (вместе с его слэш-командами). */
	public function unregister(): void
	{
		$botId = self::botUserId();

		$this->unregisterCommands();

		(new Client())->call('imbot.v2.Bot.unregister', ['botId' => $botId]);

		Config::set('bot_user_id', '0');

		Logger::info('bot', "Bot unregistered, botId={$botId}");
	}

	private function unregisterCommands(): void
	{
		$stored = Config::get('bot_command_ids', '');
		if ($stored === '')
		{
			return;
		}

		try
		{
			$ids = (array)\Bitrix\Main\Web\Json::decode($stored);
		}
		catch (\Throwable)
		{
			return;
		}

		$client = new Client();
		foreach ($ids as $commandId)
		{
			if ((int)$commandId > 0)
			{
				$client->callSafe('imbot.v2.Command.unregister', [
					'botId' => self::botUserId(),
					'commandId' => (int)$commandId,
				]);
			}
		}

		Config::set('bot_command_ids', '');
	}

	/** Свежая информация о боте (валидация связи с порталом). */
	public function status(): array
	{
		$client = new Client();

		$botId = self::botUserId();
		if ($botId <= 0)
		{
			return ['registered' => false];
		}

		$result = $client->call('imbot.v2.Bot.get', ['botId' => $botId]);
		$result = (array)($result['bot'] ?? $result);

		return [
			'registered' => true,
			'id' => (int)($result['id'] ?? $botId),
			'code' => (string)($result['code'] ?? ''),
			'type' => (string)($result['type'] ?? ''),
			'eventMode' => (string)($result['eventMode'] ?? ''),
			'isSupportOpenline' => !empty($result['isSupportOpenline']),
		];
	}

	private function extractBotId(array $result): int
	{
		foreach ([['bot', 'id'], ['id']] as $path)
		{
			$value = $result;
			foreach ($path as $key)
			{
				$value = is_array($value) ? ($value[$key] ?? null) : null;
			}
			if (is_numeric($value))
			{
				return (int)$value;
			}
		}

		return 0;
	}
}
