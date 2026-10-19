<?php

namespace Mtai\Aiagent\Stt;

use Mtai\Aiagent\Config;

/** Распознавание речи тем же провайдером, что и чат (опция llm_provider). */
final class Factory
{
	public static function make(): SttInterface
	{
		return match (Config::get('llm_provider', 'mock'))
		{
			'openai' => new OpenAiStt(),
			'yandex' => new YandexStt(),
			'mock' => new MockStt(),
			default => new UnavailableStt(),
		};
	}

	public static function isAvailable(): bool
	{
		try
		{
			return self::make()->isAvailable();
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
