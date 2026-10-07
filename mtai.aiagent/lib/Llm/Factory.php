<?php

namespace Mtai\Aiagent\Llm;

use Mtai\Aiagent\Config;
use Mtai\Aiagent\Logger;

/** Фабрика LLM-провайдеров по опции llm_provider. */
final class Factory
{
	public static function create(): ProviderInterface
	{
		$provider = Config::get('llm_provider', 'mock');

		return match ($provider)
		{
			'openai' => new OpenAiProvider(),
			'yandex' => new YandexProvider(),
			'gigachat' => new GigaChatProvider(),
			'mock' => new MockProvider(),
			default => throw new \InvalidArgumentException("Unknown LLM provider: {$provider}"),
		};
	}

	/**
	 * Эмбеддер для RAG по опции llm_embedding_provider:
	 * auto — эмбеддинги провайдера, если поддерживает, иначе встроенный;
	 * provider — только провайдер (ошибкой, если не поддерживает);
	 * hash — встроенный лексический (без внешних вызовов);
	 * off — null (поиск по базе отключён).
	 */
	public static function createEmbedder(): ?ProviderInterface
	{
		$mode = Config::get('llm_embedding_provider', 'auto');
		if ($mode === 'off')
		{
			return null;
		}
		if ($mode === 'hash')
		{
			return new HashEmbedder();
		}

		$provider = self::create();
		if ($mode === 'provider' && !$provider->supportsEmbedding())
		{
			throw new \InvalidArgumentException(
				'Provider ' . $provider->title() . ' does not support embeddings'
			);
		}

		if ($mode === 'auto' && !$provider->supportsEmbedding())
		{
			Logger::info('llm', 'Provider has no embeddings, using built-in hash embedder');

			return new HashEmbedder();
		}

		return $provider;
	}
}
