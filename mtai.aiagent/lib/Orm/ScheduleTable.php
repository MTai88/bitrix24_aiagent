<?php

namespace Mtai\Aiagent\Orm;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Type\DateTime;

/**
 * Отложенные задания сотрудников (schedule_task).
 *
 * INSTRUCTION — самодостаточное поручение: при исполнении планировщиком
 * история чата недоступна, поэтому все параметры вшиты в текст на этапе
 * создания LLM. DIALOG_ID — личный чат с ботом для доклада о результате
 * (в приватном диалоге равен ID собеседника).
 */
class ScheduleTable extends DataManager
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_DONE = 'done';
	public const STATUS_ERROR = 'error';
	public const STATUS_CANCELLED = 'cancelled';

	public static function getTableName(): string
	{
		return 'mtai_aiagent_schedule';
	}

	public static function getMap(): array
	{
		return [
			(new \Bitrix\Main\Entity\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new \Bitrix\Main\Entity\IntegerField('USER_ID'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\StringField('DIALOG_ID'))
				->configureSize(32)
				->configureDefaultValue(''),
			(new \Bitrix\Main\Entity\TextField('INSTRUCTION'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\DatetimeField('RUN_AT'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\StringField('STATUS'))
				->configureSize(16)
				->configureDefaultValue(self::STATUS_PENDING),
			(new \Bitrix\Main\Entity\IntegerField('ATTEMPTS')),
			(new \Bitrix\Main\Entity\StringField('CLAIM'))
				->configureSize(32)
				->configureDefaultValue(''),
			(new \Bitrix\Main\Entity\DatetimeField('CLAIM_AT')),
			(new \Bitrix\Main\Entity\TextField('ERROR')),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime()),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_DONE')),
		];
	}
}
