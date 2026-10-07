<?php

namespace Mtai\Aiagent\Rag;

use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Config;
use Mtai\Aiagent\Llm\Factory;
use Mtai\Aiagent\Logger;

/**
 * Векторный поиск по базе знаний (встроенный индекс на MySQL).
 *
 * Эмбеддинги хранятся в mtai_aiagent_chunk, поиск — перебор с косинусной
 * близостью в PHP. Для тестовых объёмов (десятки тысяч фрагментов)
 * это доли секунды; для больших баз подключите внешний движок.
 */
final class VectorIndex
{
	/**
	 * Поиск релевантных фрагментов.
	 *
	 * @param array|null $scopes области базы знаний (public/internal);
	 *   null = все области. Открытые линии ищут только по public —
	 *   внутренние документы клиентам не показываются.
	 * @return array<int, array{chunkId: int, documentId: int, content: string, score: float}>
	 */
	public function search(string $query, ?int $limit = null, ?float $minScore = null, ?array $scopes = null): array
	{
		$embedder = Factory::createEmbedder();
		if ($embedder === null)
		{
			return [];
		}

		$vectors = $embedder->embed([$query]);
		$queryVector = $vectors[0] ?? null;
		if ($queryVector === null)
		{
			return [];
		}

		$topK = $limit ?? Config::getInt('rag_top_k', 5);
		$minScore ??= Config::getFloat('rag_min_score', 0.25);

		// Разрешённые документы по области базы знаний.
		$chunkFilter = [];
		if ($scopes !== null)
		{
			$allowedIds = [];
			$docs = \Mtai\Aiagent\Orm\DocumentTable::getList([
				'select' => ['ID'],
				'filter' => ['@SCOPE' => array_values($scopes)],
			]);
			foreach ($docs as $doc)
			{
				$allowedIds[] = (int)$doc['ID'];
			}

			if ($allowedIds === [])
			{
				return [];
			}

			$chunkFilter = ['@DOCUMENT_ID' => $allowedIds];
		}

		$scored = [];
		$rows = \Mtai\Aiagent\Orm\ChunkTable::getList([
			'select' => ['ID', 'DOCUMENT_ID', 'CONTENT', 'EMBEDDING'],
			'filter' => $chunkFilter,
		]);

		foreach ($rows as $row)
		{
			try
			{
				$vector = Json::decode((string)$row['EMBEDDING']);
			}
			catch (\Throwable)
			{
				continue;
			}

			if (!is_array($vector) || count($vector) !== count($queryVector))
			{
				continue;
			}

			$score = self::cosine($queryVector, $vector);
			if ($score >= $minScore)
			{
				$scored[] = [
					'chunkId' => (int)$row['ID'],
					'documentId' => (int)$row['DOCUMENT_ID'],
					'content' => (string)$row['CONTENT'],
					'score' => round($score, 4),
				];
			}
		}

		usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
		$result = array_slice($scored, 0, $topK);

		if ($result !== [])
		{
			Logger::debug('rag', 'search: ' . count($result) . ' chunks, top score ' . $result[0]['score']);
		}

		return $result;
	}

	/** Косинусная близость двух нормированных векторов. */
	public static function cosine(array $a, array $b): float
	{
		$dot = 0.0;
		$count = count($a);
		for ($i = 0; $i < $count; $i++)
		{
			$dot += (float)$a[$i] * (float)$b[$i];
		}

		return $dot; // векторы нормированы — скалярное произведение = косинус
	}

	/** Всего проиндексированных фрагментов. */
	public function size(): int
	{
		return (int)\Mtai\Aiagent\Orm\ChunkTable::getCount();
	}
}
