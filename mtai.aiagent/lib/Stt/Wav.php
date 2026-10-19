<?php

namespace Mtai\Aiagent\Stt;

/**
 * WAV PCM 16 кГц, моно, 16 бит — единственный формат, который пишет кнопка.
 * Без ffmpeg: OpenAI принимает такой WAV целиком, Yandex — сырой PCM без заголовка.
 */
final class Wav
{
	public const RATE = 16000;

	public static function silence(int $seconds): string
	{
		$seconds = max(1, $seconds);

		return self::wrap(str_repeat("\0\0", $seconds * self::RATE));
	}

	public static function wrap(string $pcm): string
	{
		$dataSize = strlen($pcm);

		return 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
			. 'fmt ' . pack('V', 16)
			. pack('v', 1)
			. pack('v', 1)
			. pack('V', self::RATE)
			. pack('V', self::RATE * 2)
			. pack('v', 2)
			. pack('v', 16)
			. 'data' . pack('V', $dataSize)
			. $pcm;
	}

	/**
	 * @return array{duration: float, pcm: string}
	 */
	public static function parse(string $path): array
	{
		$raw = @file_get_contents($path);
		if (!is_string($raw) || strlen($raw) < 44)
		{
			throw new \RuntimeException('Файл не похож на WAV');
		}
		if (substr($raw, 0, 4) !== 'RIFF' || substr($raw, 8, 4) !== 'WAVE')
		{
			throw new \RuntimeException('Нужен файл WAV');
		}

		$offset = 12;
		$length = strlen($raw);
		$fmt = null;
		$data = null;
		while ($offset + 8 <= $length)
		{
			$id = substr($raw, $offset, 4);
			$size = unpack('V', substr($raw, $offset + 4, 4))[1];
			$offset += 8;
			if ($offset + $size > $length)
			{
				break;
			}
			if ($id === 'fmt ')
			{
				$fmt = substr($raw, $offset, $size);
			}
			elseif ($id === 'data')
			{
				$data = substr($raw, $offset, $size);
				break;
			}
			$offset += $size + ($size % 2);
		}

		if ($fmt === null || $data === null || strlen($fmt) < 16)
		{
			throw new \RuntimeException('В WAV нет звуковых данных');
		}

		$audioFormat = unpack('v', substr($fmt, 0, 2))[1];
		$channels = unpack('v', substr($fmt, 2, 2))[1];
		$rate = unpack('V', substr($fmt, 4, 4))[1];
		$bits = unpack('v', substr($fmt, 14, 2))[1];
		if ($audioFormat !== 1 || $channels !== 1 || $rate !== self::RATE || $bits !== 16)
		{
			throw new \RuntimeException('Нужен WAV PCM 16 кГц, моно, 16 бит');
		}

		return [
			'duration' => strlen($data) / (self::RATE * 2),
			'pcm' => $data,
		];
	}
}
