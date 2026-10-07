<?php

namespace Mtai\Aiagent\Orm;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\Type\DateTime;

/** Фрагменты документов с эмбеддингами (JSON-массив чисел). */
class ChunkTable extends DataManager
{
	use DeleteByFilterTrait;
	public static function getTableName(): string
	{
		return 'mtai_aiagent_chunk';
	}

	public static function getMap(): array
	{
		return [
			(new \Bitrix\Main\Entity\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new \Bitrix\Main\Entity\IntegerField('DOCUMENT_ID')),
			(new \Bitrix\Main\Entity\IntegerField('SORT')),
			(new \Bitrix\Main\Entity\TextField('CONTENT'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\TextField('EMBEDDING'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime()),
		];
	}
}
