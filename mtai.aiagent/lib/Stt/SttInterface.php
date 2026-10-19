<?php

namespace Mtai\Aiagent\Stt;

/**
 * Распознавание речи. Реализация выбирается по llm_provider:
 * тот же ключ и каталог, что у чата.
 */
interface SttInterface
{
	/** Можно ли распознавать с текущими настройками (ключ, каталог). */
	public function isAvailable(): bool;

	/**
	 * Текст из WAV PCM 16 кГц, моно, 16 бит.
	 * Пустая строка — речь не распознана, это не ошибка сервиса.
	 */
	public function transcribe(string $path, string $mime): string;
}
