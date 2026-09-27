<?php
declare(strict_types=1);

namespace Modules\Coach\Domain;

/**
 * Обращение к языковой модели. Одна реализация — OpenAI-совместимый
 * эндпоинт (Gemini, OpenAI, любой другой): смена провайдера — это смена
 * адреса и ключа (Р-22). В тестах подменяется заглушкой.
 */
interface LlmClient
{
    public function isAvailable(): bool;

    /**
     * @return ?array{text: string, tokens_in: int, tokens_out: int} null — модель недоступна
     */
    public function complete(string $model, string $system, string $user, int $maxTokens, int $timeout): ?array;
}
