<?php

namespace Mtai\Aiagent\Rag\Parser;

/** DOCX: word/document.xml внутри ZIP, текст из w:t, абзацы по w:p. */
final class DocxParser implements ParserInterface
{
	public static function extensions(): array
	{
		return ['docx'];
	}

	public function parse(string $filePath, string $originalName): string
	{
		if (!class_exists(\ZipArchive::class))
		{
			throw new \RuntimeException('PHP extension zip is not available');
		}

		$zip = new \ZipArchive();
		if ($zip->open($filePath) !== true)
		{
			throw new \RuntimeException('Cannot open DOCX archive');
		}

		try
		{
			$xml = (string)$zip->getFromName('word/document.xml');
			if ($xml === '')
			{
				throw new \RuntimeException('word/document.xml not found in DOCX');
			}
		}
		finally
		{
			$zip->close();
		}

		$doc = new \DOMDocument();
		if (!@$doc->loadXML($xml))
		{
			throw new \RuntimeException('Malformed document.xml in DOCX');
		}

		$paragraphs = [];
		$nodes = $doc->getElementsByTagName('p'); // w:p
		foreach ($nodes as $paragraph)
		{
			$text = '';
			foreach ($paragraph->getElementsByTagName('t') as $runText) // w:t
			{
				$text .= $runText->nodeValue;
			}

			$paragraphs[] = $text;
		}

		$result = implode("\n", $paragraphs);
		$result = (string)preg_replace("/\n{3,}/", "\n\n", $result);

		if (trim($result) === '')
		{
			throw new \RuntimeException('DOCX contains no text (scans/images only?)');
		}

		return $result;
	}
}
