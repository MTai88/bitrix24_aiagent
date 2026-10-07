<?php

namespace Mtai\Aiagent\Orm;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Type\DateTime;

/** Очередь асинхронной обработки событий бота. */
class JobTable extends DataManager
{
	public const STATUS_NEW = 'new';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_DONE = 'done';
	public const STATUS_ERROR = 'error';

	public static function getTableName(): string
	{
		return 'mtai_aiagent_job';
	}

	public static function getMap(): array
	{
		return [
			(new \Bitrix\Main\Entity\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new \Bitrix\Main\Entity\StringField('TYPE'))
				->configureRequired()
				->configureSize(32),
			(new \Bitrix\Main\Entity\TextField('PAYLOAD'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\StringField('STATUS'))
				->configureSize(16)
				->configureDefaultValue(self::STATUS_NEW),
			(new \Bitrix\Main\Entity\IntegerField('ATTEMPTS')),
			(new \Bitrix\Main\Entity\StringField('CLAIM'))
				->configureSize(32)
				->configureDefaultValue(''),
			(new \Bitrix\Main\Entity\TextField('ERROR')),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime()),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_DONE')),
		];
	}
}
