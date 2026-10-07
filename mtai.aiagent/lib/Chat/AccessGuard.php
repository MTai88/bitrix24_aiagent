<?php

namespace Mtai\Aiagent\Chat;

use Bitrix\Main\Loader;
use Mtai\Aiagent\Logger;
use Mtai\Aiagent\Orm\DocumentTable;

/**
 * Проверка прав сотрудника перед действиями AI-агента.
 *
 * REST-вызовы идут от владельца вебхука (обычно администратора), поэтому
 * без явной проверки агент давал бы любому сотруднику админские права.
 * Guard спрашивает нативные права CRM для конкретного пользователя
 * (Container::getUserPermissions($userId)) — без его авторизации —
 * и решает: показывать сущность, менять её, создавать, писать в таймлайн.
 *
 * Любой отказ возвращается модели как error — она сообщает сотруднику
 * человеческим языком, а лог фиксирует категорию access.
 */
final class AccessGuard
{
	/**
	 * Право чтения сущности CRM (учитывает «свои/отдел/все» из ролей).
	 * @param int $userId сотрудник, от лица которого идёт запрос
	 * @param string $type lead|deal|contact|company
	 */
	public static function crmCanRead(int $userId, string $type, int $id): bool
	{
		return self::check($userId, $type, $id, 'read');
	}

	public static function crmCanUpdate(int $userId, string $type, int $id): bool
	{
		return self::check($userId, $type, $id, 'update');
	}

	public static function crmCanAdd(int $userId, string $type): bool
	{
		if (!self::crmReady())
		{
			return true;
		}

		$typeId = Tools::ENTITY_TYPE_IDS[$type] ?? 0;
		$allowed = \Bitrix\Crm\Service\Container::getInstance()
			->getUserPermissions($userId)
			->checkAddPermissions($typeId);

		self::log($userId, $type, 0, 'add', $allowed);

		return $allowed;
	}

	private static function check(int $userId, string $type, int $id, string $action): bool
	{
		if (!self::crmReady())
		{
			return true;
		}

		if (!isset(Tools::ENTITY_TYPE_IDS[$type]) || $id <= 0)
		{
			return false;
		}

		$typeId = Tools::ENTITY_TYPE_IDS[$type];
		$perms = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($userId);

		$allowed = $action === 'read'
			? $perms->checkReadPermissions($typeId, $id)
			: $perms->checkUpdatePermissions($typeId, $id);

		self::log($userId, $type, $id, $action, $allowed);

		return $allowed;
	}

	/**
	 * Доступ сотрудника к задаче: он создатель, ответственный,
	 * соисполнитель или наблюдатель (или имеет права на все задачи).
	 */
	public static function taskAccessible(int $userId, array $taskFields): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$participants = [
			(int)($taskFields['createdBy'] ?? $taskFields['CREATED_BY'] ?? 0),
			(int)($taskFields['responsibleId'] ?? $taskFields['RESPONSIBLE_ID'] ?? 0),
		];
		foreach ((array)($taskFields['accomplices'] ?? $taskFields['ACCOMPLICES'] ?? []) as $u)
		{
			$participants[] = (int)(is_array($u) ? ($u['id'] ?? $u['ID'] ?? 0) : $u);
		}
		foreach ((array)($taskFields['auditors'] ?? $taskFields['AUDITORS'] ?? []) as $u)
		{
			$participants[] = (int)(is_array($u) ? ($u['id'] ?? $u['ID'] ?? 0) : $u);
		}

		$allowed = in_array($userId, $participants, true);

		self::log($userId, 'task', (int)($taskFields['id'] ?? $taskFields['ID'] ?? 0), 'access', $allowed);

		return $allowed;
	}

	private static function crmReady(): bool
	{
		return Loader::includeModule('crm');
	}

	private static function log(int $userId, string $type, int $id, string $action, bool $allowed): void
	{
		if (!$allowed)
		{
			Logger::warn('access', sprintf(
				'denied: user #%d %s %s #%d',
				$userId,
				$action,
				$type,
				$id,
			));
		}
	}
}
