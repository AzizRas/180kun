<?php
declare(strict_types=1);

namespace App;

/**
 * Обнаружение и загрузка модулей.
 *
 * Модуль = папка в modules/ с файлом module.json. Включение и выключение —
 * одна строка в modules.php в корне. Если модуль объявил зависимость,
 * которой нет среди включённых, он тихо пропускается и это видно в health.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleInfo> */
    private array $found = [];
    /** @var array<string, ModuleInfo> */
    private array $active = [];
    /** @var array<int, string> */
    private array $problems = [];

    public function __construct(private Kernel $kernel)
    {
    }

    public function discover(): void
    {
        $enabledList = require $this->kernel->root . '/modules.php';
        $enabled     = array_map('strtolower', array_values($enabledList));

        foreach (glob($this->kernel->root . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifestPath = $dir . '/module.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest) || empty($manifest['name'])) {
                $this->problems[] = basename($dir) . ': некорректный module.json';
                continue;
            }

            $info = new ModuleInfo(
                name:     strtolower((string) $manifest['name']),
                title:    (string) ($manifest['title'] ?? $manifest['name']),
                version:  (string) ($manifest['version'] ?? '0.1.0'),
                path:     $dir,
                dirName:  basename($dir),
                requires: array_map('strtolower', (array) ($manifest['requires'] ?? [])),
                provides: (array) ($manifest['provides'] ?? []),
                emits:    (array) ($manifest['emits'] ?? []),
                listens:  (array) ($manifest['listens'] ?? []),
                prefix:   (string) ($manifest['table_prefix'] ?? (strtolower((string) $manifest['name']) . '_')),
                enabled:  in_array(strtolower((string) $manifest['name']), $enabled, true),
            );

            $this->found[$info->name] = $info;
        }

        // Зависимости: модуль включается только если включены все его requires.
        foreach ($this->found as $name => $info) {
            if (!$info->enabled) {
                continue;
            }
            foreach ($info->requires as $dep) {
                if (!isset($this->found[$dep]) || !$this->found[$dep]->enabled) {
                    $info->enabled = false;
                    $this->problems[] = "Модуль «{$name}» выключен: нет зависимости «{$dep}»";
                    continue 2;
                }
            }
            $this->active[$name] = $info;
        }

        $this->active = $this->sortByDependency($this->active);
    }

    /** @param array<string, ModuleInfo> $modules @return array<string, ModuleInfo> */
    private function sortByDependency(array $modules): array
    {
        $sorted  = [];
        $visited = [];

        $visit = function (string $name) use (&$visit, &$sorted, &$visited, $modules): void {
            if (isset($visited[$name])) {
                return;
            }
            $visited[$name] = true;
            foreach ($modules[$name]->requires as $dep) {
                if (isset($modules[$dep])) {
                    $visit($dep);
                }
            }
            $sorted[$name] = $modules[$name];
        };

        foreach (array_keys($modules) as $name) {
            $visit($name);
        }
        return $sorted;
    }

    public function register(): void
    {
        foreach ($this->active as $info) {
            $module = $this->instantiate($info);
            if ($module !== null) {
                $module->register($this->kernel->container, $this->kernel);
            }
        }
    }

    public function boot(): void
    {
        foreach ($this->active as $info) {
            if ($info->instance === null) {
                continue;
            }
            $info->instance->boot($this->kernel);

            // Маршруты модуля
            $routes = $info->path . '/routes.php';
            if (is_file($routes)) {
                $define = require $routes;
                if (is_callable($define)) {
                    $define($this->kernel->router, $this->kernel);
                }
            }

            // Переводы модуля
            foreach (['ru', 'uz'] as $lang) {
                $file = $info->path . '/lang/' . $lang . '.php';
                if (is_file($file)) {
                    $this->kernel->i18n->load($lang, require $file);
                }
            }
        }
    }

    private function instantiate(ModuleInfo $info): ?BaseModule
    {
        $class = 'Modules\\' . $info->dirName . '\\Module';
        if (!class_exists($class)) {
            $this->problems[] = "Модуль «{$info->name}»: не найден класс {$class}";
            return null;
        }
        /** @var BaseModule $module */
        $module = new $class($info);
        return $info->instance = $module;
    }

    /** @return array<string, ModuleInfo> */
    public function enabled(): array
    {
        return $this->active;
    }

    /** @return array<string, ModuleInfo> */
    public function all(): array
    {
        return $this->found;
    }

    public function has(string $name): bool
    {
        return isset($this->active[strtolower($name)]);
    }

    /** @return array<int, string> */
    public function problems(): array
    {
        return $this->problems;
    }
}

/** Описание модуля, прочитанное из module.json. */
final class ModuleInfo
{
    public ?BaseModule $instance = null;

    public function __construct(
        public string $name,
        public string $title,
        public string $version,
        public string $path,
        public string $dirName,
        public array $requires,
        public array $provides,
        public array $emits,
        public array $listens,
        public string $prefix,
        public bool $enabled,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'title'    => $this->title,
            'version'  => $this->version,
            'enabled'  => $this->enabled,
            'requires' => $this->requires,
            'provides' => $this->provides,
            'emits'    => $this->emits,
            'listens'  => $this->listens,
            'prefix'   => $this->prefix,
        ];
    }
}
