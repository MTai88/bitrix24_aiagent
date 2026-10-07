<?php

namespace Mtai\Aiagent\Orm;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Type\DateTime;

/** Документы базы знаний. */
class DocumentTable extends DataManager
{
	public const SCOPE_PUBLIC = 'public';
	public const SCOPE_INTERNAL = 'internal';

	public static function getTableName(): string
	{
		return 'mtai_aiagent_document';
	}

	public static function getMap(): array
	{
		return [
			(new \Bitrix\Main\Entity\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new \Bitrix\Main\Entity\StringField('NAME'))
				->configureRequired()
				->configureSize(255),
			(new \Bitrix\Main\Entity\IntegerField('FILE_SIZE')),
			(new \Bitrix\Main\Entity\IntegerField('FILE_ID')),
			(new \Bitrix\Main\Entity\IntegerField('CHUNKS')),
			(new \Bitrix\Main\Entity\StringField('STATUS'))
				->configureSize(16)
				->configureDefaultValue('new'),
			// Область базы знаний: public — клиентам в открытых линиях;
			// internal — только сотрудникам (чат-агент, активность БП).
			(new \Bitrix\Main\Entity\StringField('SCOPE'))
				->configureSize(16)
				->configureDefaultValue(self::SCOPE_PUBLIC),
			(new \Bitrix\Main\Entity\TextField('ERROR')),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime()),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_INDEX')),
		];
	}
}
