<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Result;

/**
 * Хранилище фотографий. Реализуется модулем Media.
 *
 * Фото — возможно, биометрия (лицо в кадре), а биометрию закон
 * Узбекистана требует хранить внутри страны. Поэтому хранилище само
 * решает, можно ли ему вообще принимать фото на этом сервере
 * (available), и никто не обходит это решение.
 *
 * Кто может смотреть фото, хранилище не знает: это решает владелец
 * смысла (лента проверяет сквад) и выдаёт зрителю подписанную ссылку
 * с коротким сроком жизни.
 */
interface Media
{
    /** @return array{ok: bool, reason: ?string}  reason: disabled | residency | foreign_platform | no_processor | storage */
    public function available(): array;

    /**
     * Принять фото: проверить, пересжать, срезать метаданные (GPS),
     * сохранить вне публичной папки.
     *
     * @return Result ok: {id, width, height}; fail: media_off | too_big | bad_image | too_many_pixels | daily_limit
     */
    public function store(int $userId, string $bytes, string $purpose): Result;

    /** Подписанная ссылка для конкретного зрителя. variant: thumb | full. */
    public function url(int $mediaId, int $viewerId, string $variant = 'thumb', int $ttl = 600): ?string;

    /** @return array{id: int, user_id: int, width: int, height: int, created_at: string}|null */
    public function info(int $mediaId): ?array;

    /** Удалить файл насовсем. */
    public function delete(int $mediaId, string $reason): bool;
}
