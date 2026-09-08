<?php
declare(strict_types=1);

namespace Modules\Health;

use App\Kernel;
use App\Migrator;

/**
 * Отчёт о состоянии сборки.
 *
 * Главная ценность — раздел «контракты»: он показывает, какие возможности
 * сейчас работают по-настоящему, а какие отдают заглушку из-за выключенного
 * модуля. После правки modules.php это первое, что нужно открыть.
 */
final class Report
{
    public function __construct(private Kernel $kernel)
    {
    }

    public function build(): array
    {
        $checks = [];

        // --- Окружение ---
        $checks[] = $this->check(
            'PHP 8.0+',
            version_compare(PHP_VERSION, '8.0', '>='),
            PHP_VERSION
        );
        foreach (['pdo_sqlite', 'mbstring'] as $ext) {
            $checks[] = $this->check("Расширение {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'есть' : 'нет');
        }
        $checks[] = $this->check(
            'Расширение curl',
            extension_loaded('curl'),
            extension_loaded('curl') ? 'есть' : 'нет — ИИ не подключится',
            warnOnly: true
        );

        // --- Файловая система ---
        $storage = $this->kernel->root . '/storage';
        $checks[] = $this->check('Папка storage/ доступна на запись', is_dir($storage) && is_writable($storage), $storage);

        $configSecret = (string) $this->kernel->config->get('app.secret', '');
        $checks[] = $this->check(
            'Секретный ключ задан',
            $configSecret !== '' || is_file($storage . '/secret.key'),
            $configSecret !== '' ? 'в config.php' : 'в storage/secret.key'
        );

        $checks[] = $this->check(
            'Отладка выключена',
            !$this->kernel->config->get('app.debug'),
            $this->kernel->config->get('app.debug') ? 'debug = true — перед запуском поставить false' : 'debug = false',
            warnOnly: true
        );

        $checks[] = $this->check(
            'Пароль админки изменён',
            $this->kernel->config->get('admin.password') !== 'CHANGE_ME',
            'config.php → admin.password'
        );

        // --- База ---
        $dbOk = false;
        $dbNote = '';
        try {
            $this->kernel->db()->pdo();
            $dbOk   = true;
            $dbNote = $this->kernel->config->get('db.path');
        } catch (\Throwable $e) {
            $dbNote = $e->getMessage();
        }
        $checks[] = $this->check('База данных открывается', $dbOk, (string) $dbNote);

        $pending = [];
        if ($dbOk) {
            try {
                $pending  = (new Migrator($this->kernel))->pending();
                $checks[] = $this->check(
                    'Миграции применены',
                    $pending === [],
                    $pending === [] ? 'все' : 'не применено: ' . implode(', ', $pending)
                );
            } catch (\Throwable $e) {
                $checks[] = $this->check('Миграции применены', false, $e->getMessage());
            }
        }

        // --- Сборка ---
        $modules = [];
        foreach ($this->kernel->modules->all() as $info) {
            $modules[] = $info->toArray();
        }

        $contracts = [];
        foreach ($this->kernel->container->map() as $id => $provider) {
            if (!str_contains($id, 'Contracts\\')) {
                continue;
            }
            $short = substr($id, strrpos($id, '\\') + 1);
            $contracts[$short] = [
                'provider' => $provider,
                'live'     => $provider !== 'fallback',
            ];
        }

        $ok = true;
        foreach ($checks as $c) {
            if (!$c['ok'] && !$c['warn']) {
                $ok = false;
            }
        }

        return [
            'ok'        => $ok,
            'app'       => $this->kernel->config->get('app.name'),
            'time'      => gmdate('c'),
            'checks'    => $checks,
            'modules'   => $modules,
            'contracts' => $contracts,
            'routes'    => $this->kernel->router->map(),
            'events'    => $this->kernel->events->map(),
            'problems'  => $this->kernel->modules->problems(),
            'untranslated' => $this->kernel->i18n->untranslated(),
            'pending_migrations' => $pending,
        ];
    }

    private function check(string $title, bool $ok, string $note = '', bool $warnOnly = false): array
    {
        return ['title' => $title, 'ok' => $ok, 'note' => $note, 'warn' => $warnOnly];
    }

    public function render(array $r): string
    {
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $h = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>LEVEL 180 — диагностика</title>'
           . '<style>'
           . 'body{font:15px/1.6 system-ui,sans-serif;margin:0;background:#0f1620;color:#dfe7f1}'
           . '.w{max-width:900px;margin:0 auto;padding:32px 20px 80px}'
           . 'h1{font-size:22px;margin:0 0 4px}h2{font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#7d90a6;margin:32px 0 10px}'
           . 'table{width:100%;border-collapse:collapse;font-size:14px}td,th{padding:7px 10px;border-bottom:1px solid #22303f;text-align:left;vertical-align:top}'
           . 'th{color:#7d90a6;font-weight:600;font-size:12px;text-transform:uppercase}'
           . '.ok{color:#43c598}.bad{color:#f0836f}.warn{color:#edb34a}.muted{color:#7d90a6}'
           . 'code{font-family:ui-monospace,monospace;font-size:13px}'
           . '.pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px}'
           . '.pill.on{background:#0d2a22;color:#43c598}.pill.off{background:#301813;color:#f0836f}'
           . '</style></head><body><div class="w">';

        $h .= '<h1>' . $e($r['app']) . ' — диагностика</h1>';
        $h .= '<p class="' . ($r['ok'] ? 'ok' : 'bad') . '">'
            . ($r['ok'] ? 'Всё в порядке — можно работать.' : 'Есть блокирующие проблемы, смотрите ниже.')
            . ' <span class="muted">' . $e($r['time']) . '</span></p>';

        $h .= '<h2>Проверки</h2><table>';
        foreach ($r['checks'] as $c) {
            $cls = $c['ok'] ? 'ok' : ($c['warn'] ? 'warn' : 'bad');
            $sym = $c['ok'] ? '✓' : ($c['warn'] ? '!' : '✕');
            $h .= '<tr><td class="' . $cls . '" style="width:22px">' . $sym . '</td><td>' . $e($c['title'])
                . '</td><td class="muted"><code>' . $e($c['note']) . '</code></td></tr>';
        }
        $h .= '</table>';

        $h .= '<h2>Модули</h2><table><tr><th>Модуль</th><th>Версия</th><th>Состояние</th><th>Зависит от</th></tr>';
        foreach ($r['modules'] as $m) {
            $h .= '<tr><td>' . $e($m['title']) . ' <span class="muted"><code>' . $e($m['name']) . '</code></span></td>'
                . '<td class="muted">' . $e($m['version']) . '</td>'
                . '<td><span class="pill ' . ($m['enabled'] ? 'on">включён' : 'off">выключен') . '</span></td>'
                . '<td class="muted">' . $e($m['requires'] ? implode(', ', $m['requires']) : '—') . '</td></tr>';
        }
        $h .= '</table>';

        $h .= '<h2>Контракты</h2><table><tr><th>Контракт</th><th>Кто реализует</th><th>Режим</th></tr>';
        foreach ($r['contracts'] as $name => $c) {
            $h .= '<tr><td><code>' . $e($name) . '</code></td><td class="muted">' . $e($c['provider']) . '</td>'
                . '<td class="' . ($c['live'] ? 'ok">рабочий' : 'warn">заглушка') . '</td></tr>';
        }
        $h .= '</table>';

        $h .= '<h2>Маршруты</h2><table><tr><th>Метод</th><th>Путь</th><th>Авторизация</th></tr>';
        foreach ($r['routes'] as $rt) {
            $h .= '<tr><td class="muted">' . $e($rt['method']) . '</td><td><code>' . $e($rt['pattern']) . '</code></td>'
                . '<td class="muted">' . ($rt['auth'] ? 'нужна' : '—') . '</td></tr>';
        }
        $h .= '</table>';

        if ($r['events']) {
            $h .= '<h2>События</h2><table><tr><th>Событие</th><th>Слушают</th></tr>';
            foreach ($r['events'] as $ev => $mods) {
                $h .= '<tr><td><code>' . $e($ev) . '</code></td><td class="muted">' . $e(implode(', ', $mods)) . '</td></tr>';
            }
            $h .= '</table>';
        }

        if ($r['problems']) {
            $h .= '<h2>Проблемы сборки</h2><ul>';
            foreach ($r['problems'] as $p) {
                $h .= '<li class="bad">' . $e($p) . '</li>';
            }
            $h .= '</ul>';
        }

        if ($r['untranslated']) {
            $h .= '<h2>Нет узбекского перевода</h2><p class="warn"><code>'
                . $e(implode(', ', array_slice($r['untranslated'], 0, 40))) . '</code></p>';
        }

        return $h . '</div></body></html>';
    }
}
