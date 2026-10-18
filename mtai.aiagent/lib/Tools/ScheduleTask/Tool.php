<?php

namespace Mtai\Aiagent\Tools\ScheduleTask;

use Bitrix\Main\Type\DateTime;
use Mtai\Aiagent\Orm\ScheduleTable;
use Mtai\Aiagent\Tools\Context;

/**
 * Постановка отложенного задания: запись поручения в mtai_aiagent_schedule.
 *
 * Время приводится к абсолютному значению LLM (в системном промпте есть
 * текущая дата/время — см. ChatHandler::buildMessages), здесь только
 * нормализация и guard'ы: строго будущее, горизонт ≤ 30 суток.
 * Получатель результата — автор запроса (задания только для себя).
 */
class Tool
{
	private const MAX_HORIZON_DAYS = 30;

	public function execute(array $args, int $userId, Context $ctx): array
	{
		$instruction = trim((string)($args['instruction'] ?? ''));
		if ($instruction === '')
		{
			return ['error' => 'instruction is empty'];
		}

		$raw = trim((string)($args['datetime'] ?? ''));
		if ($raw === '')
		{
			return ['error' => 'datetime is required'];
		}

		$normalized = $this->normalize($raw);
		$timestamp = strtotime($normalized);
		if ($timestamp === false)
		{
			return ['error' => "cannot parse datetime: {$raw}"];
		}

		$now = time();
		if ($timestamp <= $now)
		{
			return ['error' => 'datetime is in the past: ' . date('Y-m-d H:i', $timestamp)];
		}
		if ($timestamp > strtotime('+' . self::MAX_HORIZON_DAYS . ' days', $now))
		{
			return ['error' => 'datetime is too far (max ' . self::MAX_HORIZON_DAYS . ' days)'];
		}

		// В приватном чате с ботом dialogId = ID собеседника — результат
		// выполнения уйдёт туда же.
		$row = ScheduleTable::add([
			'USER_ID' => $userId,
			'DIALOG_ID' => (string)$userId,
			'INSTRUCTION' => $instruction,
			'RUN_AT' => new DateTime(date('Y-m-d H:i:s', $timestamp), 'Y-m-d H:i:s'),
			'STATUS' => ScheduleTable::STATUS_PENDING,
		]);

		return [
			'ok' => true,
			'task_id' => (int)$row->getId(),
			'run_at' => date('Y-m-d H:i', $timestamp),
		];
	}

	private function normalize(string $value): string
	{
		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
		{
			return $value . ' 00:00:00';
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $value))
		{
			return str_replace('T', ' ', $value) . ':00';
		}

		return str_replace('T', ' ', $value);
	}
}
