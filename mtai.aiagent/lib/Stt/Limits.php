<?php

namespace Mtai\Aiagent\Stt;

use Mtai\Aiagent\Config;

/** Пределы длины записи. Синхронный SpeechKit — не больше 30 секунд и 1 МБ. */
final class Limits
{
	public static function maxSeconds(): int
	{
		$max = Config::getInt('voice_max_seconds', 60);
		if ($max < 5)
		{
			$max = 5;
		}
		if ($max > 120)
		{
			$max = 120;
		}
		if (Config::get('llm_provider', 'mock') === 'yandex')
		{
			$max = min($max, 30);
		}

		return $max;
	}

	/** PCM 16 кГц, 16 бит, моно плюс запас на заголовок WAV. */
	public static function maxBytes(): int
	{
		return self::maxSeconds() * Wav::RATE * 2 + 1024;
	}
}
