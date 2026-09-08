<?php
declare(strict_types=1);

namespace App;

/**
 * Ядро приложения.
 *
 * Отвечает за: автозагрузку без Composer, поднятие контейнера,
 * обнаружение и загрузку модулей, маршрутизацию.
 *
 * Ядро НЕ знает ни об одном конкретном модуле. Любой модуль можно
 * выключить в modules.php — ядро продолжит работать.
 */
final class Kernel
{
    public string $root;
    public Container $container;
    public Config $config;
    public Events $events;
    public Router $router;
    public ModuleRegistry $modules;
    public I18n $i18n;

    private bool $booted = false;

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        self::registerAutoloader($this->root);

        $this->config    = new Config(require $this->root . '/config.php');
        $this->container = new Container();
        $this->events    = new Events($this->config->get('app.debug', false));
        $this->router    = new Router();
        $this->i18n      = new I18n($this->config->get('app.default_lang', 'ru'));
        $this->modules   = new ModuleRegistry($this);

        $this->container->instance(Kernel::class, $this);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Events::class, $this->events);
        $this->container->instance(I18n::class, $this->i18n);

        // База данных создаётся лениво: health.php должен работать даже если БД недоступна.
        $this->container->singleton(Db::class, function () {
            return new Db($this->config->get('db.path'), $this->config->get('app.debug', false));
        });
    }

    /** Автозагрузка: App\Foo -> app/Foo.php, Modules\Squad\Domain\Bar -> modules/Squad/Domain/Bar.php */
    public static function registerAutoloader(string $root): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\')) {
                $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            } elseif (str_starts_with($class, 'Modules\\')) {
                $path = $root . '/modules/' . str_replace('\\', '/', substr($class, 8)) . '.php';
            } else {
                return;
            }
            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    /** Поднимает все включённые модули. Идемпотентно. */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }
        $this->booted = true;

        $this->modules->discover();
        $this->modules->register();   // модули кладут свои сервисы в контейнер
        $this->modules->boot();       // модули подписываются на события и объявляют маршруты

        return $this;
    }

    public function db(): Db
    {
        return $this->container->get(Db::class);
    }

    /** Точка входа HTTP-запроса. */
    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request, $this);
        } catch (\Throwable $e) {
            return $this->renderError($e);
        }
    }

    private function renderError(\Throwable $e): Response
    {
        $debug = (bool) $this->config->get('app.debug', false);
        $this->log('error', $e->getMessage(), [
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'class' => $e::class,
        ]);

        $payload = ['ok' => false, 'error' => 'internal_error'];
        if ($debug) {
            $payload['message'] = $e->getMessage();
            $payload['where']   = $e->getFile() . ':' . $e->getLine();
            $payload['trace']   = array_slice(explode("\n", $e->getTraceAsString()), 0, 12);
        }

        return Response::json($payload, 500);
    }

    /**
     * Секретный ключ приложения: из config.php, иначе создаётся один раз
     * в storage/secret.key. Используется для подписи сессий и талонов.
     */
    public function secret(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $fromConfig = (string) $this->config->get('app.secret', '');
        if ($fromConfig !== '') {
            return $cached = $fromConfig;
        }

        $file = $this->root . '/storage/secret.key';
        if (is_file($file)) {
            return $cached = (string) file_get_contents($file);
        }

        $key = bin2hex(random_bytes(32));
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, $key);
        @chmod($file, 0600);
        return $cached = $key;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $dir = $this->root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
