<?php

namespace Mtai\Aiagent\Tools\MyDay;

use Mtai\Aiagent\Tools\CalendarEventsList\Tool as CalendarTool;
use Mtai\Aiagent\Tools\Context;
use Mtai\Aiagent\Tools\CrmActivitiesList\Tool as ActivitiesTool;
use Mtai\Aiagent\Tools\TasksList\Tool as TasksTool;

/**
 * Сводка дня из задач, дел CRM, календаря и застоявшихся сделок.
 * Ошибка одного источника не роняет остальные.
 */
class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$date = trim((string)($args['date'] ?? ''));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
		{
			$date = date('Y-m-d');
		}

		$tasks = new TasksTool();
		$overdue = $this->safe(fn() => $tasks->execute([
			'status' => 'open',
			'overdue' => true,
			'limit' => 10,
		], $userId, $ctx));
		$today = $this->safe(fn() => $tasks->execute([
			'status' => 'open',
			'deadline_from' => $date,
			'deadline_to' => $date,
			'limit' => 10,
		], $userId, $ctx));

		$activities = new ActivitiesTool();
		$crmToday = $this->safe(fn() => $activities->execute(['period' => 'today', 'limit' => 10], $userId, $ctx));
		$crmOverdue = $this->safe(fn() => $activities->execute(['period' => 'overdue', 'limit' => 10], $userId, $ctx));

		$calendar = $this->safe(fn() => (new CalendarTool())->execute([
			'from' => $date,
			'to' => $date,
		], $userId, $ctx));

		$staleDeals = $this->safe(fn() => (new \Mtai\Aiagent\Tools\CrmFindEntities\Tool())->execute([
			'entity_type' => 'deal',
			'responsible' => (string)$userId,
			'stage_semantic' => 'open',
			'inactive_days' => 7,
			'limit' => 5,
		], $userId, $ctx));

		return [
			'date' => $date,
			'tasks_overdue' => $overdue['tasks'] ?? [],
			'tasks_today' => $today['tasks'] ?? [],
			'crm_overdue' => $crmOverdue['activities'] ?? [],
			'crm_today' => $crmToday['activities'] ?? [],
			'calendar' => $calendar['events'] ?? [],
			'deals_inactive' => $staleDeals['items'] ?? [],
			'errors' => array_values(array_filter([
				isset($overdue['error']) ? 'tasks_overdue: ' . $overdue['error'] : null,
				isset($today['error']) ? 'tasks_today: ' . $today['error'] : null,
				isset($crmToday['error']) ? 'crm_today: ' . $crmToday['error'] : null,
				isset($crmOverdue['error']) ? 'crm_overdue: ' . $crmOverdue['error'] : null,
				isset($calendar['error']) ? 'calendar: ' . $calendar['error'] : null,
				isset($staleDeals['error']) ? 'deals_inactive: ' . $staleDeals['error'] : null,
			])),
		];
	}

	private function safe(callable $call): array
	{
		try
		{
			$result = $call();

			return is_array($result) ? $result : ['error' => 'empty result'];
		}
		catch (\Throwable $e)
		{
			return ['error' => $e->getMessage()];
		}
	}
}
