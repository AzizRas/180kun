<?php
declare(strict_types=1);

namespace App;

/**
 * Миграции принадлежат модулям, а не приложению.
 *
 * Каждый модуль хранит свои .sql в modules/<Name>/Migrations/ с именами
 * вида 001_init.sql, 002_add_column.sql. Применённые записываются в
 * _migrations вместе с именем модуля — поэтому модуль можно вырезать
 * вместе с его таблицами, не задев чужие.
 */
final class Migrator
{
    public function __construct(private Kernel $kernel)
    {
    }

    private function ensureTable(Db $db): void
    {
        $db->run('
            CREATE TABLE IF NOT EXISTS _migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                module     TEXT NOT NULL,
                file       TEXT NOT NULL,
                applied_at TEXT NOT NULL,
                UNIQUE(module, file)
            )
        ');
    }

    /**
     * @return array{applied: array<int, string>, skipped: int, errors: array<int, string>}
     */
    public function migrate(bool $dryRun = false): array
    {
        $db = $this->kernel->db();
        $this->ensureTable($db);

        $done = [];
        foreach ($db->all('SELECT module, file FROM _migrations') as $row) {
            $done[$row['module'] . '/' . $row['file']] = true;
        }

        $applied = [];
        $errors  = [];
        $skipped = 0;

        foreach ($this->kernel->modules->enabled() as $module) {
            $dir = $module->path . '/Migrations';
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.sql') ?: [];
            sort($files, SORT_NATURAL);

            foreach ($files as $file) {
                $name = basename($file);
                $key  = $module->name . '/' . $name;

                if (isset($done[$key])) {
                    $skipped++;
                    continue;
                }
                if ($dryRun) {
                    $applied[] = $key;
                    continue;
                }

                $sql = (string) file_get_contents($file);
                try {
                    $db->transaction(function (Db $db) use ($sql, $module, $name) {
                        $db->pdo()->exec($sql);
                        $db->insert('_migrations', [
                            'module'     => $module->name,
                            'file'       => $name,
                            'applied_at' => gmdate('c'),
                        ]);
                    });
                    $applied[] = $key;
                } catch (\Throwable $e) {
                    $errors[] = $key . ': ' . $e->getMessage();
                }
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** Что ещё не применено — используется в health-проверке. */
    public function pending(): array
    {
        return $this->migrate(true)['applied'];
    }
}
