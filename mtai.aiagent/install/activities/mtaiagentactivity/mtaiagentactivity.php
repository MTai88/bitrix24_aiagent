<?php

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

Loc::loadLanguageFile(__FILE__);

/**
 * Активность «AI-агент» для дизайнера бизнес-процессов (в духе n8n AI Agent):
 * вопрос/задача → LLM (модель и ключ — в настройках модуля mtai.aiagent) →
 * ответ в поле Answer. Режимы: ответ ассистента по базе знаний (RAG) или
 * выполнение задачи инструментами CRM; опционально — системный промпт
 * и память по ключу.
 */
class CBPMtaiAgentActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_QUESTION = 'Question';
	private const PARAM_MODE = 'Mode';
	private const PARAM_SYSTEM_PROMPT = 'SystemPrompt';
	private const PARAM_MEMORY_KEY = 'MemoryKey';
	private const PARAM_USER_ID = 'UserId';
	private const PARAM_ANSWER_FIELD = 'AnswerField';

	public function __construct(string $name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_QUESTION => null,
			self::PARAM_MODE => null,
			self::PARAM_SYSTEM_PROMPT => null,
			self::PARAM_MEMORY_KEY => null,
			self::PARAM_USER_ID => null,
			self::PARAM_ANSWER_FIELD => null,

			// Результаты
			'Answer' => null,
			'ErrorMessage' => null,
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	public static function validateProperties($testProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (empty($testProperties[self::PARAM_QUESTION]))
		{
			$errors[] = [
				'code' => 'NotExist',
				'message' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_ERROR_QUESTION_EMPTY'),
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_QUESTION => [
				'Name' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_PARAM_QUESTION'),
				'FieldName' => self::PARAM_QUESTION,
				'Type' => FieldType::TEXT,
				'Required' => true,
				'Default' => '',
			],			self::PARAM_MODE => [
				'Name' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_PARAM_MODE'),
				'FieldName' => self::PARAM_MODE,
				'Type' => FieldType::SELECT,
				'Options' => [
					'assistant' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_MODE_ASSISTANT'),
					'agent' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_MODE_AGENT'),
					'direct' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_MODE_DIRECT'),
				],
				'Default' => 'assistant',
			],
			self::PARAM_SYSTEM_PROMPT => [
				'Name' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_PARAM_SYSTEM_PROMPT'),
				'FieldName' => self::PARAM_SYSTEM_PROMPT,
				'Type' => FieldType::TEXT,
				'Default' => '',
			],
			self::PARAM_MEMORY_KEY => [
				'Name' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_PARAM_MEMORY_KEY'),
				'FieldName' => self::PARAM_MEMORY_KEY,
				'Type' => FieldType::STRING,
				'Default' => '',
			],
			self::PARAM_USER_ID => [
				'Name' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_PARAM_USER_ID'),
				'FieldName' => self::PARAM_USER_ID,
				'Type' => FieldType::STRING,
				'Default' => '',
			],
			self::PARAM_ANSWER_FIELD => [
				'Name' => Loc::getMessage('MTAI_AIAGENT_ACTIVITY_PARAM_ANSWER_FIELD'),
				'FieldName' => self::PARAM_ANSWER_FIELD,
				'Type' => FieldType::STRING,
				'Default' => '',
			],
		];
	}

	public function execute(): int
	{
		$this->Answer = null;
		$this->ErrorMessage = null;

		if (!\Bitrix\Main\Loader::includeModule('mtai.aiagent'))
		{
			$this->trackError(Loc::getMessage('MTAI_AIAGENT_ACTIVITY_ERROR_MODULE'));

			return CBPActivityExecutionStatus::Closed;
		}

		$question = (string)$this->{self::PARAM_QUESTION};
		if (trim($question) === '')
		{
			$this->trackError(Loc::getMessage('MTAI_AIAGENT_ACTIVITY_ERROR_QUESTION_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$mode = (string)$this->{self::PARAM_MODE};
		if (!in_array(
			$mode,
			[
				\Mtai\Aiagent\Agent\Runner::MODE_ASSISTANT,
				\Mtai\Aiagent\Agent\Runner::MODE_AGENT,
				\Mtai\Aiagent\Agent\Runner::MODE_DIRECT,
			],
			true
		))
		{
			$mode = \Mtai\Aiagent\Agent\Runner::MODE_ASSISTANT;
		}

		$userId = (int)$this->{self::PARAM_USER_ID};

		$result = (new \Mtai\Aiagent\Agent\Runner())->run(
			$mode,
			$question,
			(string)$this->{self::PARAM_SYSTEM_PROMPT},
			(string)$this->{self::PARAM_MEMORY_KEY},
			$userId,
		);

		$this->Answer = $result['answer'] !== '' ? $result['answer'] : null;
		if ($result['error'] !== null)
		{
			$this->trackError($result['error']);
		}

		// Опциональная запись ответа в поле документа (NAME, PROPERTY_123…).
		$answerField = trim((string)$this->{self::PARAM_ANSWER_FIELD});
		if ($answerField !== '' && $this->Answer !== null)
		{
			try
			{
				$this->workflow->GetService('DocumentService')->UpdateDocument(
					$this->getDocumentId(),
					[$answerField => $this->Answer],
				);
			}
			catch (\Throwable $e)
			{
				$this->trackError(
					Loc::getMessage('MTAI_AIAGENT_ACTIVITY_ERROR_FIELD_WRITE') . ': ' . $e->getMessage()
				);
			}
		}

		return CBPActivityExecutionStatus::Closed;
	}
}
