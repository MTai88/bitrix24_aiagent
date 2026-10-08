<?php

namespace Mtai\Aiagent\Tools\FindUser;

use Mtai\Aiagent\Tools\Context;

class Tool
{
	public function execute(array $args, int $userId, Context $ctx): array
	{
		$result = $ctx->restCall('user.search', ['FILTER' => ['NAME' => (string)($args['full_name'] ?? '')]]);

		$found = (array)($result[0] ?? []);
		if (!isset($found['ID']))
		{
			return ['error' => 'user not found'];
		}

		return [
			'id' => (int)$found['ID'],
			'name' => (string)($found['NAME'] ?? '') . ' ' . (string)($found['LAST_NAME'] ?? ''),
			'email' => (string)($found['EMAIL'] ?? ''),
		];
	}
}
