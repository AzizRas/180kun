<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Контракт ИИ-тренера. Реализуется модулем Coach.
 *
 * Решение Р-11: расчёт нагрузки делает детерминированный код (модуль Planning),
 * а этот контракт отвечает только за формулировку и выбор действия
 * внутри уже разрешённого коридора.
 */
interface Coach
{
    /**
     * Ответ на ежедневный чек-ин.
     *
     * @param array $context обезличенный профиль + метрики (без имени и телефона)
     * @return array{text: string, action: ?array, source: string}
     */
    public function replyToCheckin(array $context): array;

    /** Недельный обзор. */
    public function weeklyReview(array $context): array;

    /** Классификация состояния: steady | obstacle | overload | drift | quit */
    public function classifyState(array $context): string;

    /** Доступен ли настоящий ИИ (false = работаем на шаблонах). */
    public function isLive(): bool;
}
