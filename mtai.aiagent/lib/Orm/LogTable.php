<?php

namespace Mtai\Aiagent\Orm;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\Type\DateTime;

/** Журнал модуля: запросы к LLM, REST-вызовы, решения агента. */
class LogTable extends DataManager
{
	use DeleteByFilterTrait;
	public static function getTableName(): string
	{
		return 'mtai_aiagent_log';
	}

	public static function getMap(): array
	{
		return [
			(new \Bitrix\Main\Entity\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new \Bitrix\Main\Entity\StringField('LEVEL'))
				->configureSize(8)
				->configureDefaultValue('info'),
			(new \Bitrix\Main\Entity\StringField('CATEGORY'))
				->configureSize(32)
				->configureDefaultValue('general'),
			(new \Bitrix\Main\Entity\TextField('MESSAGE'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\TextField('CONTEXT')),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime()),
		];
	}
}
