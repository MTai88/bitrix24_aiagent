<?php

namespace Mtai\Aiagent\Rag\Parser;

/** TXT / Markdown / CSV — читаем как есть, приводим к UTF-8. */
final class TxtParser implements ParserInterface
{
	public static function extensions(): array
	{
		return ['txt', 'md', 'csv', 'html', 'htm'];
	}

	public function parse(string $filePath, string $originalName): string
	{
		$content = (string)file_get_contents($filePath);
		if ($content === '')
		{
			throw new \RuntimeException('File is empty');
		}

		// UTF-8 BOM
		if (str_starts_with($content, "\xEF\xBB\xBF"))
		{
			$content = substr($content, 3);
		}

		if (!mb_check_encoding($content, 'UTF-8'))
		{
			$converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
			if (mb_check_encoding($converted, 'UTF-8'))
			{
				$content = $converted;
			}
		}

		if (preg_match('/\.(html?|csv)$/i', $originalName))
		{
			$content = trim((string)preg_replace('/<[^>]+>/', ' ', $content));
			$content = preg_replace('/[ \t]+/', ' ', $content) ?? $content;
		}

		$normalized = preg_replace('/\r\n?/', "\n", $content) ?? $content;

		if (trim($normalized) === '')
		{
			throw new \RuntimeException('No text content found');
		}

		return $normalized;
	}
}
