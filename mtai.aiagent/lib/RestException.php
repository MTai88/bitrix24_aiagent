<?php

namespace Mtai\Aiagent;

/** Ошибка REST-вызова Битрикс24. */
class RestException extends \RuntimeException
{
	private string $errorCode;

	public function __construct(string $message, string $errorCode = '', int $code = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
		$this->errorCode = $errorCode;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
