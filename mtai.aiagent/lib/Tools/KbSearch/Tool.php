<?php

namespace Mtai\Aiagent\Tools\KbSearch;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$query = trim((string)($args['query'] ?? ''));
		if ($query === '')
		{
			return ['error' => 'query is required'];
		}

		$limit = max(1, min(10, (int)($args['limit'] ?? 5)));

		$scope = (string)($args['scope'] ?? 'all');
		$scopes = match ($scope)
		{
			'public' => ['public'],
			'internal' => ['internal'],
			default => null, // all — обе области
		};

		$found = (new \Mtai\Aiagent\Rag\VectorIndex())->search($query, $limit, null, $scopes);

		$chunks = [];
		foreach ($found as $item)
		{
			$chunks[] = [
				'content' => mb_substr((string)$item['content'], 0, 1200),
				'score' => $item['score'],
			];
		}

		return $chunks === []
			? ['found' => false, 'note' => 'в базе знаний ничего релевантного не найдено']
			: ['found' => true, 'chunks' => $chunks];
	}
}
