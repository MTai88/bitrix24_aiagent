<?php

namespace Mtai\Aiagent\Rag\Parser;

/** Извлечение текста из файла базы знаний. */
interface ParserInterface
{
	/** Расширения (без точки), которые разбирает парсер. */
	public static function extensions(): array;

	/**
	 * Извлечь текст. Бросает RuntimeException при невозможности разобрать.
	 */
	public function parse(string $filePath, string $originalName): string;
}
