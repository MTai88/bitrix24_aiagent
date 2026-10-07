<?php

namespace Mtai\Aiagent\Rag;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Llm\Factory;
use Mtai\Aiagent\Orm\ChunkTable;
use Mtai\Aiagent\Orm\DocumentTable;
use Mtai\Aiagent\Rag\Parser\DocxParser;
use Mtai\Aiagent\Rag\Parser\PdfParser;
use Mtai\Aiagent\Rag\Parser\TxtParser;

/**
 * Сервис базы знаний: загрузка файла, разбор, чанкование,
 * эмбеддинг и запись в индекс; переиндексация и удаление.
 */
final class DocumentService
{
	private const MAX_FILE_SIZE = 31457280; // 30 МБ

	/** @var ParserInterface[] */
	private array $parsers;

	public function __construct()
	{
		$this->parsers = [
			new TxtParser(),
			new DocxParser(),
			new PdfParser(),
		];
	}

	public static function allowedExtensions(): string
	{
		$result = [];
		foreach ([new TxtParser(), new DocxParser(), new PdfParser()] as $parser)
		{
			foreach ($parser::extensions() as $extension)
			{
				$result[] = $extension;
			}
		}

		return implode(', ', $result);
	}

	/**
	 * Загрузка документа из $_FILES-массива (array name/type/tmp_name/error/size).
	 * Работает и с массивом от CFile::MakeFileArray (без ключа error —
	 * файлы из Disk/CLI не проходят через HTTP-загрузку).
	 *
	 * @param string $scope область базы знаний: public — клиентам в открытых
	 *   линиях; internal — только сотрудникам (чат-агент, активность БП)
	 * @return int ID документа
	 */
	public function upload(array $file, string $scope = DocumentTable::SCOPE_PUBLIC): int
	{
		if (!in_array($scope, [DocumentTable::SCOPE_PUBLIC, DocumentTable::SCOPE_INTERNAL], true))
		{
			$scope = DocumentTable::SCOPE_PUBLIC;
		}
		if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK)
		{
			throw new \RuntimeException('File upload error: ' . (string)($file['error'] ?? ''));
		}

		if (empty($file['tmp_name']) || !is_file((string)$file['tmp_name']))
		{
			throw new \RuntimeException('File content is not available');
		}

		if ((int)$file['size'] > self::MAX_FILE_SIZE)
		{
			throw new \RuntimeException('File exceeds 30 MB limit');
		}

		$name = (string)($file['name'] ?? 'document');
		$extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

		if (!in_array($extension, $this->supportedExtensions(), true))
		{
			throw new \RuntimeException('Unsupported file type: ' . $extension);
		}

		$saved = \CFile::SaveFile(
			[
				'name' => $name,
				'type' => (string)($file['type'] ?? 'application/octet-stream'),
				'tmp_name' => $file['tmp_name'],
				'size' => (int)$file['size'],
				'MODULE_ID' => 'mtai.aiagent',
			],
			'mtai.aiagent'
		);

		if (!(int)$saved)
		{
			throw new \RuntimeException('Cannot store file via CFile');
		}

		$result = DocumentTable::add([
			'NAME' => $name,
			'FILE_ID' => (int)$saved,
			'FILE_SIZE' => (int)$file['size'],
			'STATUS' => 'new',
			'SCOPE' => $scope,
			'DATE_CREATE' => new DateTime(),
		]);

		if (!$result->isSuccess())
		{
			throw new \RuntimeException('Cannot create document row: ' . implode('; ', $result->getErrorMessages()));
		}

		$documentId = (int)$result->getId();
		$this->indexDocument($documentId);

		return $documentId;
	}

	/** Разбор + индексация одного документа. */
	public function indexDocument(int $documentId): void
	{
		$document = DocumentTable::getById($documentId)->fetch();
		if (!$document)
		{
			throw new \RuntimeException("Document #{$documentId} not found");
		}

		DocumentTable::update($documentId, ['STATUS' => 'indexing', 'ERROR' => null]);

		try
		{
			$filePath = \CFile::GetPath((int)$document['FILE_ID']);
			if ($filePath === null || $filePath === '')
			{
				throw new \RuntimeException('Stored file not found');
			}
			$absolute = $_SERVER['DOCUMENT_ROOT'] . $filePath;

			$text = $this->parseFile($absolute, (string)$document['NAME']);

			$chunks = (new Chunker())->split($text);
			if ($chunks === [])
			{
				throw new \RuntimeException('No content chunks extracted');
			}

			$this->storeChunks($documentId, $chunks);

			DocumentTable::update($documentId, [
				'STATUS' => 'ready',
				'CHUNKS' => count($chunks),
				'ERROR' => null,
				'DATE_INDEX' => new DateTime(),
			]);

			Logger::info('kb', "Document #{$documentId} '{$document['NAME']}' indexed: " . count($chunks) . ' chunks');
		}
		catch (\Throwable $e)
		{
			DocumentTable::update($documentId, [
				'STATUS' => 'error',
				'ERROR' => $e->getMessage(),
			]);

			Logger::error('kb', "Document #{$documentId} index failed: " . $e->getMessage());

			throw $e;
		}
	}

	/** Удаление документа и его фрагментов. */
	public function delete(int $documentId): void
	{
		$document = DocumentTable::getById($documentId)->fetch();
		if (!$document)
		{
			return;
		}

		ChunkTable::deleteByFilter(['=DOCUMENT_ID' => $documentId]);

		if ((int)$document['FILE_ID'] > 0)
		{
			\CFile::Delete((int)$document['FILE_ID']);
		}

		DocumentTable::delete($documentId);

		Logger::info('kb', "Document #{$documentId} '{$document['NAME']}' deleted");
	}

	/** Переиндексация всех документов (например, после смены модели эмбеддингов). */
	public function reindexAll(): int
	{
		$count = 0;
		$rows = DocumentTable::getList([
			'select' => ['ID'],
			'order' => ['ID' => 'ASC'],
		]);
		foreach ($rows as $row)
		{
			try
			{
				$this->indexDocument((int)$row['ID']);
				$count++;
			}
			catch (\Throwable)
			{
				// ошибка уже записана в документ и журнал
			}
		}

		Logger::info('kb', "Reindexed {$count} documents");

		return $count;
	}

	private function storeChunks(int $documentId, array $chunks): void
	{
		ChunkTable::deleteByFilter(['=DOCUMENT_ID' => $documentId]);

		$embedder = Factory::createEmbedder();
		if ($embedder === null)
		{
			throw new \RuntimeException('Embeddings disabled (llm_embedding_provider=off)');
		}

		// Пакетное эмбеддинг-запросами по 32 фрагмента.
		foreach (array_chunk($chunks, 32, true) as $batch)
		{
			$vectors = $embedder->embed(array_values($batch));

			$sort = 0;
			foreach ($batch as $index => $chunkText)
			{
				$vector = $vectors[$sort] ?? null;
				if ($vector === null)
				{
					throw new \RuntimeException('Embedding provider returned fewer vectors than texts');
				}

				ChunkTable::add([
					'DOCUMENT_ID' => $documentId,
					'SORT' => $index,
					'CONTENT' => $chunkText,
					'EMBEDDING' => Json::encode($vector),
					'DATE_CREATE' => new DateTime(),
				]);
				$sort++;
			}
		}
	}

	private function parseFile(string $absolutePath, string $name): string
	{
		if (!is_file($absolutePath) || !is_readable($absolutePath))
		{
			throw new \RuntimeException('Stored file is not readable');
		}

		$extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
		foreach ($this->parsers as $parser)
		{
			if (in_array($extension, $parser::extensions(), true))
			{
				return $parser->parse($absolutePath, $name);
			}
		}

		throw new \RuntimeException('No parser for extension: ' . $extension);
	}

	private function supportedExtensions(): array
	{
		$result = [];
		foreach ($this->parsers as $parser)
		{
			foreach ($parser::extensions() as $extension)
			{
				$result[] = $extension;
			}
		}

		return $result;
	}
}
