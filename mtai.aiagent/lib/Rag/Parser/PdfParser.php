<?php

namespace Mtai\Aiagent\Rag\Parser;

/**
 * PDF: минималистичный экстрактор текста без внешних библиотек.
 *
 * Поддерживаются обычные текстовые PDF: извлекаются все потоки
 * (с распаковкой FlateDecode), из операторов Tj/TJ собирается текст,
 * Td/TD/T* дают переводы строк. Сканы (изображения без текстового
 * слоя) и экзотические кодировки шрифтов не разбираются — для таких
 * документов используйте DOCX/TXT (см. README, раздел про базу знаний).
 */
final class PdfParser implements ParserInterface
{
	public static function extensions(): array
	{
		return ['pdf'];
	}

	public function parse(string $filePath, string $originalName): string
	{
		$content = (string)file_get_contents($filePath);
		if ($content === '')
		{
			throw new \RuntimeException('File is empty');
		}

		if (strncmp($content, '%PDF', 4) !== 0)
		{
			throw new \RuntimeException('Not a PDF file');
		}

		$lines = [];

		// Все объекты-потоки: N 0 obj ... stream\n...endstream
		if (!preg_match_all('/stream\r?\n(.*?)endstream/s', $content, $streams))
		{
			throw new \RuntimeException('No streams found in PDF');
		}

		foreach ($streams[1] as $stream)
		{
			$text = $this->extractStreamText($stream);
			if ($text !== '')
			{
				$lines[] = $text;
			}
		}

		$result = implode("\n", $lines);
		$result = (string)preg_replace("/\n{3,}/", "\n\n", $result);
		$result = str_replace(["\x00"], '', $result);

		// Кириллица в UTF-16LE (с BOM или по диапазону) — перекодируем.
		if (preg_match('//u', $result) !== 1)
		{
			$converted = @mb_convert_encoding($result, 'UTF-8', 'UTF-16LE');
			if ($converted !== false && mb_check_encoding($converted, 'UTF-8'))
			{
				$result = $converted;
			}
			else
			{
				// Выбрасываем непрозрачные бинарные хвосты, оставляем ASCII-текст.
				$result = (string)filter_var(
					$result,
					FILTER_UNSAFE_RAW,
					FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH
				);
			}
		}

		if (mb_strlen(trim($result)) < 10)
		{
			throw new \RuntimeException(
				'PDF: extracted too little text — likely a scan or non-standard font encoding'
			);
		}

		return $result;
	}

	private function extractStreamText(string $stream): string
	{
		$data = $stream;

		// Распаковка zlib там, где она возможна.
		if (function_exists('gzuncompress'))
		{
			$inflated = @gzuncompress($data);
			if ($inflated !== false)
			{
				$data = $inflated;
			}
			else
			{
				$raw = @gzinflate($data);
				if ($raw !== false)
				{
					$data = $raw;
				}
			}
		}

		if (!str_contains($data, 'Tj') && !str_contains($data, 'TJ'))
		{
			return '';
		}

		$out = '';
		// (...) Tj  |  [(..) -?(..)] TJ  |  ET/Td/TD/T* — границы строк
		$tokens = preg_split(
			'/(\(Tj\)|TJ|Td|TD|T\*|ET)/',
			$data,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		) ?: [];

		foreach ($tokens as $index => $token)
		{
			if ($index % 2 === 1)
			{
				// Оператор-разделитель: Td/TD/T*/ET дают перевод строки.
				$out .= "\n";

				continue;
			}

			if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $token, $strings))
			{
				foreach ($strings[0] as $string)
				{
					$out .= $this->decodePdfString(substr($string, 1, -1));
				}
			}
		}

		return $out;
	}

	/** Раскодирование escape-последовательностей PDF-строки. */
	private function decodePdfString(string $string): string
	{
		$string = str_replace(
			['\\n', '\\r', '\\t', '\\b', '\\f'],
			["\n", "\r", "\t", "\b", "\f"],
			$string
		);

		$result = '';
		$length = strlen($string);
		for ($i = 0; $i < $length; $i++)
		{
			$char = $string[$i];
			if ($char === '\\' && $i + 1 < $length)
			{
				$next = $string[$i + 1];
				if ($next >= '0' && $next <= '7')
				{
					$octal = '';
					while ($i + 1 < $length && strlen($octal) < 3 && $string[$i + 1] >= '0' && $string[$i + 1] <= '7')
					{
						$octal .= $string[++$i];
					}
					$result .= chr((int)octdec($octal));
				}
				else
				{
					$result .= $next;
					$i++;
				}
			}
			else
			{
				$result .= $char;
			}
		}

		return $result;
	}
}
