<?php

namespace Mtai\Aiagent\Llm;

/**
 * Мок-провайдер: проверка конвейера (событие → очередь → ответ)
 * без внешних API и ключей.
 *
 * chat() эхом возвращает текст последнего сообщения пользователя,
 * embed() делегирует встроенному лексическому эмбеддеру, поэтому
 * RAG-поиск на моке реально работает по совпадению слов.
 */
final class MockProvider implements ProviderInterface
{
	private HashEmbedder $embedder;

	public function __construct()
	{
		$this->embedder = new HashEmbedder();
	}

	public static function code(): string
	{
		return 'mock';
	}

	public function title(): string
	{
		return 'Мок (без внешних вызовов, для проверки конвейера)';
	}

	public function supportsTools(): bool
	{
		return false;
	}

	public function supportsEmbedding(): bool
	{
		return true;
	}

	public function chat(array $messages, array $tools = [], array $options = []): array
	{
		$lastUser = '';
		foreach (array_reverse($messages) as $message)
		{
			if (($message['role'] ?? '') === 'user')
			{
				$lastUser = (string)($message['content'] ?? '');

				break;
			}
		}

		// Достаём исходный вопрос (без привязанного RAG-контекста).
		$question = trim(preg_replace('/\nКонтекст из базы знаний:.*$/su', '', $lastUser) ?? $lastUser);

		$reply = '[mock] ' . ($question !== '' ? $question : '(пустой запрос)');

		return [
			'content' => $reply,
			'toolCalls' => [],
			'usage' => [],
			'finishReason' => 'stop',
		];
	}

	public function embed(array $texts): array
	{
		return $this->embedder->embed($texts);
	}
}
