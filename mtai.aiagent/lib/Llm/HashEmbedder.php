<?php

namespace Mtai\Aiagent\Llm;

/**
 * Встроенный лексический эмбеддер (без внешних API).
 *
 * Хэш-трюк: каждый токен слова (нормализованный + усечённый «стем»)
 * хэшируется в DIM измерений с псевдослучайным знаком, вектор
 * нормируется. Косинусная близость получается оценкой лексического
 * совпадения — качество ниже настоящих нейросетевых эмбеддингов,
 * но работает офлайн и годится для небольших FAQ.
 */
final class HashEmbedder implements ProviderInterface
{
	private const DIM = 384;

	public static function code(): string
	{
		return 'hash';
	}

	public function title(): string
	{
		return 'Встроенный лексический (без API)';
	}

	public function supportsTools(): bool
	{
		return false;
	}

	public function supportsVision(): bool
	{
		return false;
	}

	public function supportsEmbedding(): bool
	{
		return true;
	}

	public function chat(array $messages, array $tools = [], array $options = []): array
	{
		throw new \LogicException('HashEmbedder does not support chat');
	}

	public function embed(array $texts): array
	{
		$result = [];
		foreach ($texts as $text)
		{
			$result[] = $this->embedOne((string)$text);
		}

		return $result;
	}

	private function embedOne(string $text): array
	{
		$vector = array_fill(0, self::DIM, 0.0);

		foreach ($this->tokenize($text) as $token)
		{
			$this->addToken($vector, $token);
		}

		OpenAiProvider::normalize($vector);

		return $vector;
	}

	private function addToken(array &$vector, string $token): void
	{
		foreach ([0, 1, 2] as $salt)
		{
			$hash = crc32($salt . ':' . $token);
			$index = $hash % self::DIM;
			$sign = (($hash >> 31) & 1) ? -1.0 : 1.0;
			$vector[$index] += $sign / 3.0;
		}
	}

	/** Токены: слова целиком + усечённые до 6 символов «стемы» для морфологии. */
	private function tokenize(string $text): array
	{
		$lower = mb_strtolower($text);
		$words = preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [];

		$tokens = [];
		foreach ($words as $word)
		{
			if ($word === '' || mb_strlen($word) < 2)
			{
				continue;
			}

			$tokens[] = $word;
			if (mb_strlen($word) > 6)
			{
				$tokens[] = mb_substr($word, 0, 6);
			}
		}

		return $tokens;
	}
}
