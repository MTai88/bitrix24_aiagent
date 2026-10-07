<?php

namespace Mtai\Aiagent\Orm;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Type\DateTime;

/** История диалогов (контекстное окно LLM), ключ вида ol:{chatId} / im:{botId}:{userId}. */
class ConversationTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'mtai_aiagent_conversation';
	}

	public static function getMap(): array
	{
		return [
			(new \Bitrix\Main\Entity\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new \Bitrix\Main\Entity\StringField('DIALOG_KEY'))
				->configureRequired()
				->configureSize(64),
			(new \Bitrix\Main\Entity\TextField('MESSAGES'))
				->configureRequired(),
			(new \Bitrix\Main\Entity\DatetimeField('DATE_UPDATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime()),
		];
	}
}
