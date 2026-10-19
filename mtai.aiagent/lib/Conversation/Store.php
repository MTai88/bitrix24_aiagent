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

	/**
	 * История для модели: метки сущностей дописываются к тексту ответа,
	 * в базе остаётся чистый текст, который ушёл сотруднику.
	 */
	public static function forModel(array $stored): array
	{
		$messages = [];
		foreach ($stored as $item)
		{
			if (!is_array($item))
			{
				continue;
			}
			$content = (string)($item['content'] ?? '');
			$refs = array_values(array_filter((array)($item['refs'] ?? []), static fn($ref): bool => trim((string)$ref) !== ''));
			if ($refs !== [])
			{
				$content .= "\n(сущности этого ответа: " . implode('; ', $refs) . ')';
			}
			$messages[] = [
				'role' => (string)($item['role'] ?? 'user'),
				'content' => $content,
			];
		}

		return $messages;
	}

	/**
	 * Добавить сообщение в историю, обрезав до history_depth записей.
	 * refs — короткие метки сущностей («deal #12 «Ромашка»»), чтобы следующая
	 * реплика («добавь в неё комментарий») видела, о чём шла речь.
	 */
	public function append(string $key, string $role, string $content, array $refs = []): void
	{
		$message = ['role' => $role, 'content' => $content];
		$refs = array_values(array_filter(array_map(static fn($ref): string => trim((string)$ref), $refs)));
		if ($refs !== [])
		{
			$message['refs'] = $refs;
		}

		$messages = $this->load($key);
		$messages[] = $message;

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

	/** Снять последнее сообщение указанной роли (откат перед ретраем). */
	public function trimLast(string $key, string $role): void
	{
		$messages = $this->load($key);
		$last = $messages === [] ? null : end($messages);
		if ($last !== null && ($last['role'] ?? '') === $role)
		{
			array_pop($messages);
			$this->save($key, $messages);
		}
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
