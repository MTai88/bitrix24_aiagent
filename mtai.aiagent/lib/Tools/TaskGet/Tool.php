<?php

namespace Mtai\Aiagent\Tools\TaskGet;

use Mtai\Aiagent\Chat\AccessGuard;
use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$taskId = (int)($args['task_id'] ?? 0);
		if ($taskId <= 0)
		{
			return ['error' => 'task_id is required'];
		}

		$taskResult = $ctx->restCall('tasks.task.get', [
			'taskId' => $taskId,
			'select' => ['ID', 'TITLE', 'DESCRIPTION', 'DEADLINE', 'STATUS', 'PRIORITY',
				'CREATED_BY', 'RESPONSIBLE_ID', 'ACCOMPLICES', 'AUDITORS', 'GROUP_ID', 'UF_CRM_TASK', 'CHAT_ID'],
		]);
		$task = (array)($taskResult['task'] ?? []);
		if ($task === [])
		{
			return ['error' => "Задача #{$taskId} не найдена"];
		}
		if (!AccessGuard::taskAccessible($userId, $task))
		{
			return ['error' => "Задача #{$taskId} вам недоступна"];
		}

		$status = (int)($task['status'] ?? $task['STATUS'] ?? 0);
		$responsibleId = (int)($task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? 0);
		$priority = (int)($task['priority'] ?? $task['PRIORITY'] ?? 1);

		return [
			'id' => $taskId,
			'title' => (string)($task['title'] ?? $task['TITLE'] ?? ''),
			'description' => mb_substr(trim((string)($task['description'] ?? $task['DESCRIPTION'] ?? '')), 0, 1500),
			'deadline' => (string)($task['deadline'] ?? $task['DEADLINE'] ?? ''),
			'status' => Context::taskStatusName($status),
			'priority' => match ($priority) { 2 => 'high', 0 => 'low', default => 'normal' },
			'responsible' => $this->person($responsibleId, $ctx),
			'created_by' => $this->person((int)($task['createdBy'] ?? $task['CREATED_BY'] ?? 0), $ctx),
			'accomplices' => $this->people($task['accomplices'] ?? $task['ACCOMPLICES'] ?? [], $ctx),
			'auditors' => $this->people($task['auditors'] ?? $task['AUDITORS'] ?? [], $ctx),
			'crm' => array_values((array)($task['ufCrmTask'] ?? $task['UF_CRM_TASK'] ?? [])),
			'checklist' => $this->checklist($taskId, $ctx),
			'comments' => $this->comments($taskId, (int)($task['chatId'] ?? $task['CHAT_ID'] ?? 0), $ctx),
			'url' => $ctx->taskUrl($taskId, $responsibleId > 0 ? $responsibleId : $userId),
		];
	}

	private function person(int $id, Context $ctx): array
	{
		if ($id <= 0)
		{
			return [];
		}

		return ['id' => $id, 'name' => $ctx->userName($id)];
	}

	/** @return array[] */
	private function people(mixed $raw, Context $ctx): array
	{
		$people = [];
		foreach ((array)$raw as $user)
		{
			$id = (int)(is_array($user) ? ($user['id'] ?? $user['ID'] ?? 0) : $user);
			if ($id > 0)
			{
				$people[] = $this->person($id, $ctx);
			}
		}

		return $people;
	}

	/** @return array[] */
	private function checklist(int $taskId, Context $ctx): array
	{
		[$ok, $result] = $ctx->restSafe('task.checklistitem.getlist', ['taskId' => $taskId]);
		if (!$ok)
		{
			return [];
		}

		$items = [];
		foreach ((array)$result as $row)
		{
			$row = (array)$row;
			$title = trim((string)($row['TITLE'] ?? $row['title'] ?? ''));
			if ($title === '' || str_starts_with($title, 'BX_CHECKLIST'))
			{
				continue;
			}
			$items[] = [
				'title' => $title,
				'done' => (string)($row['IS_COMPLETE'] ?? $row['isComplete'] ?? 'N') === 'Y',
			];
		}

		return $items;
	}

	/**
	 * Комментарии. На Задачах 2.0 (tasks 26) они живут в чате задачи,
	 * task.commentitem.getlist по форуму пустой. Системные сообщения
	 * (author_id = 0) пропускаем.
	 *
	 * @return array[]
	 */
	private function comments(int $taskId, int $chatId, Context $ctx): array
	{
		[$ok, $result] = $ctx->restSafe('task.commentitem.getlist', [
			'taskId' => $taskId,
			'order' => ['POST_DATE' => 'desc'],
		]);
		$comments = [];
		if ($ok && is_array($result) && $result !== [])
		{
			foreach (array_slice($result, -5) as $row)
			{
				$row = (array)$row;
				$comments[] = [
					'author_id' => (int)($row['AUTHOR_ID'] ?? 0),
					'date' => (string)($row['POST_DATE'] ?? ''),
					'text' => mb_substr(trim(strip_tags((string)($row['POST_MESSAGE'] ?? ''))), 0, 400),
				];
			}

			return $comments;
		}

		if ($chatId <= 0)
		{
			return [];
		}

		[$ok, $result] = $ctx->restSafe('im.dialog.messages.get', [
			'DIALOG_ID' => 'chat' . $chatId,
			'LIMIT' => 20,
		]);
		if (!$ok)
		{
			return [];
		}

		foreach ((array)($result['messages'] ?? []) as $row)
		{
			$row = (array)$row;
			$authorId = (int)($row['author_id'] ?? 0);
			$text = trim(strip_tags((string)($row['text'] ?? '')));
			if ($authorId <= 0 || $text === '')
			{
				continue;
			}
			$comments[] = [
				'author_id' => $authorId,
				'date' => (string)($row['date'] ?? ''),
				'text' => mb_substr($text, 0, 400),
			];
			if (count($comments) >= 5)
			{
				break;
			}
		}

		return $comments;
	}
}
