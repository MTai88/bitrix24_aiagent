<?php

namespace Mtai\Aiagent\Stt;

use Mtai\Aiagent\Config;

/**
 * Не ходит в сеть: возвращает фразу из опции voice_mock_text.
 * Для eval и проверки кнопки, когда LLM тоже стоит на «Мок».
 */
final class MockStt implements SttInterface
{
	public function isAvailable(): bool
	{
		return true;
	}

	public function transcribe(string $path, string $mime): string
	{
		if (!is_file($path))
		{
			throw new \RuntimeException('Файл записи не найден');
		}

		return trim(Config::get('voice_mock_text', 'что у меня сегодня'));
	}
}
