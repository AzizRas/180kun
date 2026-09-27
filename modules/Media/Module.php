<?php
declare(strict_types=1);

namespace Modules\Media;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Media\Domain\Images;
use Modules\Media\Domain\MediaService;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(MediaService::class, static fn() => new MediaService($kernel), 'media');
        $container->singleton(Contracts\Media::class, static fn(Container $c) => $c->get(MediaService::class), 'media');
    }

    public function boot(Kernel $kernel): void
    {
        $media = static fn(): MediaService => $kernel->container->get(MediaService::class);

        // Сроки хранения: фото не живут дольше сезона.
        $kernel->events->on('system.tick', static function (array $p) use ($media): array {
            $n = $media()->sweep();
            if ($n > 0) {
                $p['done'][] = 'media: удалено по сроку ' . $n;
            }
            return $p;
        }, 'media');

        // /health: можно ли на этом сервере принимать фото и почему нет.
        $kernel->events->on('health.checks', static function (array $p) use ($media, $kernel): array {
            $av   = $media()->available();
            $note = $av['ok']
                ? 'движок ' . Images::engine() . ', папка ' . $media()->dir()
                : ($av['reason'] === 'foreign_platform'
                    ? 'сервер ' . MediaService::foreignPlatform() . ' за рубежом — фото выключены (биометрия хранится только в Узбекистане)'
                    : $kernel->i18n->t('media.off.' . $av['reason'], [], 'ru'));
            $p['checks'][] = ['title' => 'Фото: приём и хранение', 'ok' => $av['ok'], 'note' => $note, 'warn' => true];
            return $p;
        }, 'media');

        // Права на данные (Р-19). Выгрузка — со ссылкой на сутки, чтобы
        // человек скачал свои фото; удаление — сначала файлы с диска.
        \App\UserData::register($kernel, 'media', 'media_', [
            'media_files' => [
                'before' => static fn(int $userId) => $media()->unlinkAllOf($userId),
                'map'    => static function (array $row, int $userId) use ($media): array {
                    unset($row['token']);   // имя файла на диске — не данные человека
                    $row['link'] = $row['status'] === 'active' ? $media()->url((int) $row['id'], $userId, 'full', 86400) : null;
                    return $row;
                },
            ],
        ]);
    }
}
