<?php

namespace Mtai\Aiagent\Rag;

use Mtai\Aiagent\Config;

/**
 * Нарезка текста на фрагменты для индексации.
 *
 * Блок = абзац между пустыми строками. Блоки НЕ склеиваются в крупные
 * фрагменты: короткий самодостаточный блок (вопрос-ответ FAQ, пункт
 * прайса) — идеальный чанк, а склейка размывает эмбеддинг шумом соседних
 * тем. Слишком длинный блок режется по предложениям с перекрытием
 * rag_chunk_overlap — ответ на границе не теряет контекст.
 */
final class Chunker
{
	/** @return string[] фрагменты */
	public function split(string $text): array
	{
		$maxSize = max(200, Config::getInt('rag_chunk_size', 1000));
		$overlap = max(0, min(Config::getInt('rag_chunk_overlap', 180), (int)($maxSize / 2)));

		$blocks = preg_split("/\n\s*\n/", trim($text)) ?: [];
		$blocks = array_values(array_filter(array_map('trim', $blocks)));

		$chunks = [];
		foreach ($blocks as $block)
		{
			if (mb_strlen($block) <= $maxSize)
			{
				$chunks[] = $block;

				continue;
			}

			$sentences = preg_split('/(?<=[.!?;:])\s+/u', $block) ?: [$block];
			$buffer = '';
			foreach ($sentences as $sentence)
			{
				if ($buffer !== '' && mb_strlen($buffer . ' ' . $sentence) > $maxSize)
				{
					$chunks[] = $buffer;
					$buffer = $overlap > 0
						? mb_substr($buffer, -$overlap)
						: '';
				}
				$buffer = $buffer === '' ? $sentence : $buffer . ' ' . $sentence;
			}
			if (trim($buffer) !== '')
			{
				$chunks[] = $buffer;
			}
		}

		return array_values(array_filter(array_map('trim', $chunks)));
	}
}
