<?php

namespace Mtai\Aiagent\Stt;

/** Провайдер чата без распознавания речи (GigaChat и неизвестные). */
final class UnavailableStt implements SttInterface
{
	public function isAvailable(): bool
	{
		return false;
	}

	public function transcribe(string $path, string $mime): string
	{
		throw new \RuntimeException('Провайдер не поддерживает распознавание речи');
	}
}
