<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Доступ к платной части сезона (Р-20). Реализуется модулем Billing.
 *
 * Бесплатно навсегда: Нулевой цикл, план на 180 дней, чек-ины, история
 * и экспорт данных. По сезону: место в скваде и разборы ИИ-тренера.
 *
 * Заглушка открывает всё: выключили модуль оплаты — продукт бесплатный,
 * а не сломанный.
 */
interface Access
{
    /** Есть ли у человека оплаченный (или подаренный) сезон на эту дату. */
    public function hasSeason(int $userId, ?string $date = null): bool;

    /**
     * @return array{status: string, until: ?string, trial_left: ?int}
     *   status: free | trial | pending | active | expired
     */
    public function status(int $userId): array;
}
