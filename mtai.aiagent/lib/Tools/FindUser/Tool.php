<?php

namespace Mtai\Aiagent\Tools\FindUser;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$resolved = $ctx->resolveUser((string)($args['full_name'] ?? ''));
		if (!isset($resolved['id']))
		{
			return $resolved;
		}

		return [
			'id' => (int)$resolved['id'],
			'name' => (string)($resolved['name'] ?? ''),
		];
	}
}
