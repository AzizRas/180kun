<?php
declare(strict_types=1);

namespace Modules\Media\Domain;

use App\Contracts\Media;
use App\Kernel;
use App\Result;

/**
 * Хранилище фото.
 *
 * Три рубежа, почему фото не утекут:
 *  1. Файлы лежат вне публичной папки под случайными именами — прямой
 *     ссылки на файл не существует.
 *  2. Отдаёт их только маршрут с подписью: кому (зритель), что (размер),
 *     до какого времени. Подпись выдаёт владелец смысла — лента, после
 *     проверки, что зритель в том же скваде.
 *  3. Фото принимаются только на сервере, заявленном как узбекский, и
 *     никогда — на известных зарубежных платформах, даже если флаг
 *     выставили по ошибке.
 */
final class MediaService implements Media
{
    public const VARIANTS = ['thumb' => 't', 'full' => 'f'];

    public function __construct(private Kernel $kernel)
    {
    }

    // ================= можно ли здесь хранить фото =================

    public function available(): array
    {
        $c = $this->kernel->config;
        if (!$c->get('media.enabled', true)) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if (self::foreignPlatform() !== null) {
            return ['ok' => false, 'reason' => 'foreign_platform'];
        }
        if ((string) $c->get('app.data_residency', '') !== 'UZ') {
            return ['ok' => false, 'reason' => 'residency'];
        }
        if (Images::engine() === null) {
            return ['ok' => false, 'reason' => 'no_processor'];
        }
        if (!$this->ensureDir()) {
            return ['ok' => false, 'reason' => 'storage'];
        }
        return ['ok' => true, 'reason' => null];
    }

    /** Платформы, которые точно не в Узбекистане. Флаг DATA_RESIDENCY их не перебивает. */
    public static function foreignPlatform(): ?string
    {
        foreach (['RAILWAY_ENVIRONMENT' => 'Railway', 'RENDER' => 'Render', 'FLY_APP_NAME' => 'Fly.io', 'DYNO' => 'Heroku', 'VERCEL' => 'Vercel'] as $env => $name) {
            if (getenv($env) !== false) {
                return $name;
            }
        }
        return null;
    }

    public function dir(): string
    {
        return rtrim((string) $this->kernel->config->get('media.dir', $this->kernel->dataDir() . '/media'), '/\\');
    }

    private function ensureDir(): bool
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return false;
        }
        // Второй рубеж на Apache, если папку данных положили в публичную.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return is_writable($dir);
    }

    // ================= приём =================

    public function store(int $userId, string $bytes, string $purpose): Result
    {
        $av = $this->available();
        if (!$av['ok']) {
            return Result::fail('media_off', ['reason' => $av['reason']]);
        }
        if ($userId <= 0 || $bytes === '') {
            return Result::fail('bad_image');
        }
        if (strlen($bytes) > $this->maxBytes()) {
            return Result::fail('too_big', ['max_mb' => (int) $this->kernel->config->get('media.max_upload_mb', 10)]);
        }

        $today = gmdate('Y-m-d');
        $limit = (int) $this->kernel->config->get('media.daily_limit', 6);
        // Удалённые тоже считаются: иначе «загрузил — удалил» обходит лимит.
        $count = (int) $this->kernel->db()->value('SELECT COUNT(*) FROM media_files WHERE user_id = ? AND day = ?', [$userId, $today], 0);
        if ($count >= $limit) {
            return Result::fail('daily_limit', ['limit' => $limit]);
        }

        $img = Images::process(
            $bytes,
            (int) $this->kernel->config->get('media.max_side', 1600),
            (int) $this->kernel->config->get('media.thumb_side', 480)
        );
        if (!$img->ok) {
            return $img;
        }

        $token = bin2hex(random_bytes(16));
        foreach (self::VARIANTS as $variant => $suffix) {
            if (!$this->write($this->path($token, $variant), $img->data[$variant === 'thumb' ? 'thumb' : 'full'])) {
                $this->unlink($token);
                return Result::fail('media_off', ['reason' => 'storage']);
            }
        }

        $days = (int) $this->kernel->config->get('media.retention_days', 120);
        $id   = $this->kernel->db()->insert('media_files', [
            'user_id'    => $userId,
            'token'      => $token,
            'purpose'    => $purpose,
            'width'      => $img->data['width'],
            'height'     => $img->data['height'],
            'bytes'      => strlen($img->data['full']) + strlen($img->data['thumb']),
            'day'        => $today,
            'created_at' => gmdate('c'),
            'expires_at' => $days > 0 ? gmdate('c', time() + $days * 86400) : null,
        ]);

        return Result::ok(['id' => $id, 'width' => $img->data['width'], 'height' => $img->data['height']]);
    }

    public function maxBytes(): int
    {
        return (int) $this->kernel->config->get('media.max_upload_mb', 10) * 1024 * 1024;
    }

    // ================= ссылки и отдача =================

    public function url(int $mediaId, int $viewerId, string $variant = 'thumb', int $ttl = 600): ?string
    {
        if (!isset(self::VARIANTS[$variant]) || $viewerId <= 0) {
            return null;
        }
        $row = $this->row($mediaId);
        if ($row === null) {
            return null;
        }
        // Срок округляется до пятиминутки: ссылка на одно фото одна и та же
        // несколько минут, и браузер берёт его из кеша, а не качает заново.
        $exp = (intdiv(time(), 300) + 1) * 300 + $ttl;
        return '/api/media/' . $row['token'] . '/' . $variant
            . '?u=' . $viewerId . '&e=' . $exp . '&s=' . $this->sign((string) $row['token'], $variant, $viewerId, $exp);
    }

    /** @return array{bytes: string, etag: string, expires: int}|null  null — нет или подпись не сошлась */
    public function serve(string $token, string $variant, int $viewerId, int $exp, string $sig): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !isset(self::VARIANTS[$variant]) || $viewerId <= 0 || $exp < time()) {
            return null;
        }
        if (!hash_equals($this->sign($token, $variant, $viewerId, $exp), $sig)) {
            return null;
        }
        $row = $this->kernel->db()->first("SELECT id FROM media_files WHERE token = ? AND status = 'active'", [$token]);
        if ($row === null) {
            return null;
        }
        $bytes = @file_get_contents($this->path($token, $variant));
        if ($bytes === false) {
            return null;
        }
        return ['bytes' => $bytes, 'etag' => '"' . substr($token, 0, 12) . self::VARIANTS[$variant] . '"', 'expires' => $exp];
    }

    private function sign(string $token, string $variant, int $viewerId, int $exp): string
    {
        return substr(hash_hmac('sha256', "media|{$token}|{$variant}|{$viewerId}|{$exp}", $this->kernel->secret()), 0, 32);
    }

    // ================= чтение и удаление =================

    public function info(int $mediaId): ?array
    {
        $row = $this->row($mediaId);
        return $row === null ? null : [
            'id'         => (int) $row['id'],
            'user_id'    => (int) $row['user_id'],
            'width'      => (int) $row['width'],
            'height'     => (int) $row['height'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function delete(int $mediaId, string $reason): bool
    {
        $row = $this->row($mediaId);
        if ($row === null) {
            return false;
        }
        $this->unlink((string) $row['token']);
        $this->kernel->db()->update('media_files', [
            'status' => 'deleted', 'deleted_at' => gmdate('c'), 'deleted_reason' => $reason,
        ], 'id = :id', ['id' => $mediaId]);
        $this->kernel->events->emit('media.deleted', ['media_id' => $mediaId, 'user_id' => (int) $row['user_id'], 'reason' => $reason]);
        return true;
    }

    /** Удаление аккаунта: все файлы человека с диска. Строки стирает UserData. */
    public function unlinkAllOf(int $userId): int
    {
        $n = 0;
        foreach ($this->kernel->db()->all('SELECT token FROM media_files WHERE user_id = ?', [$userId]) as $row) {
            $this->unlink((string) $row['token']);
            $n++;
        }
        return $n;
    }

    /** Фото с истёкшим сроком хранения — стираются насовсем. */
    public function sweep(?string $now = null): int
    {
        $now = $now ?? gmdate('c');
        $n   = 0;
        foreach ($this->kernel->db()->all(
            "SELECT id FROM media_files WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < ? LIMIT 500",
            [$now]
        ) as $row) {
            $n += $this->delete((int) $row['id'], 'expired') ? 1 : 0;
        }
        return $n;
    }

    public function stats(): array
    {
        $db = $this->kernel->db();
        return [
            'available' => $this->available(),
            'engine'    => Images::engine(),
            'files'     => (int) $db->value("SELECT COUNT(*) FROM media_files WHERE status = 'active'", [], 0),
            'bytes'     => (int) $db->value("SELECT COALESCE(SUM(bytes), 0) FROM media_files WHERE status = 'active'", [], 0),
            'today'     => (int) $db->value('SELECT COUNT(*) FROM media_files WHERE day = ?', [gmdate('Y-m-d')], 0),
            'retention_days' => (int) $this->kernel->config->get('media.retention_days', 120),
        ];
    }

    public function fileExists(int $mediaId, string $variant = 'full'): bool
    {
        $row = $this->kernel->db()->first('SELECT token FROM media_files WHERE id = ?', [$mediaId]);
        return $row !== null && is_file($this->path((string) $row['token'], $variant));
    }

    private function row(int $id): ?array
    {
        return $this->kernel->db()->first("SELECT * FROM media_files WHERE id = ? AND status = 'active'", [$id]);
    }

    private function path(string $token, string $variant): string
    {
        return $this->dir() . '/' . substr($token, 0, 2) . '/' . $token . '_' . self::VARIANTS[$variant] . '.img';
    }

    private function write(string $path, string $bytes): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0640);
        return @rename($tmp, $path);
    }

    private function unlink(string $token): void
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            $p = $this->path($token, $variant);
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }
}
