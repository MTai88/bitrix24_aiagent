<?php

namespace Mtai\Aiagent\Chat;

/**
 * Конвертер Markdown → BB-код для сообщений бота: реальные LLM отвечают
 * markdown, а мессенджер Битрикса рендерит BBCode. Поддерживается
 * базовый набор: код-блоки, инлайн-код, жирный, курсив, ссылки,
 * заголовки и списки. Неизвестная разметка остаётся как есть.
 */
final class Markdown
{
	public static function toBb(string $text): string
	{
		// Защита code-блоков от внутренней конвертации.
		$blocks = [];
		$text = preg_replace_callback(
			'/```(?:[a-zA-Z0-9+-]*)\n?(.*?)```/s',
			static function ($m) use (&$blocks) {
				$blocks[] = trim((string)$m[1]);

				return '[CODE]' . (count($blocks) - 1) . '[/CODE]';
			},
			$text
		) ?? $text;

		// Инлайн-код → [CODE].
		$text = preg_replace('/`([^`\n]+)`/', '[CODE]$1[/CODE]', $text) ?? $text;

		// Жирный и курсив (жирный первым, чтобы ** не съелся как два *).
		$text = preg_replace('/\*\*(.+?)\*\*/s', '[B]$1[/B]', $text) ?? $text;
		$text = preg_replace('/__(.+?)__/s', '[B]$1[/B]', $text) ?? $text;
		$text = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '[I]$1[/I]', $text) ?? $text;
		$text = preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '[I]$1[/I]', $text) ?? $text;

		// Ссылки [текст](url) → [URL=url]текст[/URL]; голые http(s)-ссылки.
		$text = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u', '[URL=$2]$1[/URL]', $text) ?? $text;
		$text = preg_replace('/(?<![\]=])(https?:\/\/[^\s<\[]+)/u', '[URL=$1]$1[/URL]', $text) ?? $text;

		// Заголовки → жирная строка.
		$text = preg_replace('/^#{1,6}\s*(.+)$/mu', '[B]$1[/B]', $text) ?? $text;

		// Списки: маркер → буллит (мессенджер не рендерит [LIST] в чате).
		$text = preg_replace('/^\s*[-*+]\s+/mu', '• ', $text) ?? $text;
		$text = preg_replace('/^\s*(\d+)\.\s+/mu', '$1. ', $text) ?? $text;

		// Возврат code-блоков.
		foreach ($blocks as $i => $code) {
			$text = str_replace('[CODE]' . $i . '[/CODE]', '[CODE]' . $code . '[/CODE]', $text);
		}

		// Горизонтальные линии → тире.
		$text = preg_replace('/^\s*([-*_]\s*){3,}$/mu', str_repeat('—', 20), $text) ?? $text;

		return trim((string)$text);
	}
}
