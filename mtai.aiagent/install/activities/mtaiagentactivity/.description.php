<?php

use Bitrix\Bizproc\Activity\Enum\ActivityType;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$arActivityDescription = [
	'NAME' => GetMessage('MTAI_AIAGENT_ACTIVITY_NAME'),
	'DESCRIPTION' => GetMessage('MTAI_AIAGENT_ACTIVITY_DESCRIPTION'),
	'TYPE' => [
		ActivityType::ACTIVITY->value,
		ActivityType::ROBOT->value,
	],
	'CLASS' => 'MtaiAgentActivity',
	'JSCLASS' => 'BizProcActivity',
	// Классический дизайнер: активность попадает в группу OWN_ID (если
	// задана) или ID; группа должна существовать в arAllActGroups, иначе
	// пункт молча пропадает. OWN_ID/OWN_NAME создают собственную группу.
	'CATEGORY' => [
		'ID' => 'other',
		'OWN_ID' => 'mtai',
		'OWN_NAME' => GetMessage('MTAI_AIAGENT_ACTIVITY_GROUP'),
	],
	'RETURN' => [
		'Answer' => [
			'NAME' => GetMessage('MTAI_AIAGENT_ACTIVITY_RETURN_ANSWER'),
			'TYPE' => 'string',
		],
		'ErrorMessage' => [
			'NAME' => GetMessage('MTAI_AIAGENT_ACTIVITY_RETURN_ERROR'),
			'TYPE' => 'string',
		],
	],
];
