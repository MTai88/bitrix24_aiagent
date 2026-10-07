<?php

namespace Mtai\Aiagent\Conversation;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Orm\ConversationTable;

/**
 * Память диалога: последние N сообщений (контекстное окно LLM).
 * Ключ — «канал:идентификатор диалога»: ol:{chatId} для открытых
 * линий, im:{botId}:{userId} для личных чатов.
 */
final class Store
{
	public function load(string $key): array
	{
		$row = ConversationTable::getList([
			'select' => ['MESSAGES'],
			'filter' => ['=DIALOG_KEY' => $key],
			'limit' => 1,
		])->fetch();

		if (!$row)
		{
			return [];
		}

		try
		{
			$messages = Json::decode((string)$row['MESSAGES']);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($messages) ? $messages : [];
	}

	/** Добавить сообщение в историю, обрезав до history_depth записей. */
	public function append(string $key, string $role, string $content): void
	{
		$messages = $this->load($key);
		$messages[] = ['role' => $role, 'content' => $content];

		$depth = max(2, Config::getInt('history_depth', 12));
		if (count($messages) > $depth)
		{
			$messages = array_slice($messages, -$depth);
		}

		$this->save($key, $messages);
	}

	public function clear(string $key): void
	{
		$this->save($key, []);
	}

	private function save(string $key, array $messages): void
	{
		$existing = ConversationTable::getList([
			'select' => ['ID'],
			'filter' => ['=DIALOG_KEY' => $key],
			'limit' => 1,
		])->fetch();

		$fields = [
			'MESSAGES' => Json::encode($messages, JSON_UNESCAPED_UNICODE),
			'DATE_UPDATE' => new DateTime(),
		];

		if ($existing)
		{
			ConversationTable::update((int)$existing['ID'], $fields);
		}
		else
		{
			ConversationTable::add(['DIALOG_KEY' => $key] + $fields);
		}
	}
}
