<?php

namespace Mtai\Aiagent\Llm;

/**
 * Контракт LLM-провайдера.
 *
 * Сообщения — массивы вида ['role' => 'system|user|assistant|tool',
 * 'content' => string, ...]. У текущего пользовательского сообщения
 * может быть ключ images (mime + base64) — его видит только провайдер
 * с supportsVision(), в историю диалога байты не пишутся.
 * Результат чата:
 * [
 *   'content' => string|null,         // текст ответа
 *   'toolCalls' => [                  // запросы инструментов (OpenAI-формат)
 *     ['id' => string, 'name' => string, 'arguments' => array],
 *   ],
 * ]
 */
interface ProviderInterface
{
	/** Символьный код провайдера (совпадает с опцией llm_provider). */
	public static function code(): string;

	/** Человеческое название для админки. */
	public function title(): string;

	/** Поддержка нативного function calling (tools). */
	public function supportsTools(): bool;

	/** Модель принимает изображение в запросе (OpenAI image_url). */
	public function supportsVision(): bool;

	/**
	 * Диалог с моделью.
	 * @param array $messages сообщения диалога
	 * @param array $tools описания инструментов (JSON Schema) — если supportsTools()
	 * @param array $options temperature/maxTokens переопределения
	 */
	public function chat(array $messages, array $tools = [], array $options = []): array;

	/** Эмбеддинги для массива текстов (нормированные вектора одинаковой длины). */
	public function embed(array $texts): array;

	/** Поддерживает ли провайдер эмбеддинги. */
	public function supportsEmbedding(): bool;
}
