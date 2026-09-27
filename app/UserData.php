<?php
declare(strict_types=1);

namespace App;

/**
 * Права человека на свои данные (Р-19): выгрузить всё одной кнопкой и
 * удалить аккаунт с полной чисткой.
 *
 * Ядро не знает, какие данные есть в продукте. Каждый модуль сам
 * объявляет СВОИ таблицы — и сам же отвечает за их выгрузку и удаление:
 *
 *   UserData::register($kernel, 'checkin', 'checkin_', [
 *       'checkin_days'   => [],                                  // выгрузить и удалить по user_id
 *       'checkin_events' => ['column' => 'user_id'],
 *       'x_payments'     => ['erase' => 'UPDATE x_payments SET user_id = 0 WHERE user_id = ?'],
 *       'x_items'        => ['where' => 'owner_id IN (SELECT id FROM x_things WHERE user_id = ?)'],
 *       'x_files'        => ['before' => fn(int $userId) => …,   // стереть файлы с диска до строк
 *                            'map'    => fn(array $row) => …],   // дополнить строку выгрузки (ссылка)
 *   ]);
 *
 * Таблица чужого префикса отвергается сразу — модуль не может выгрузить
 * или стереть чужие данные даже по ошибке. Порядок в массиве — порядок
 * удаления: дочерние таблицы раньше родительских.
 */
final class UserData
{
    /** Колонки, которые не выгружаются никогда: секреты, а не данные человека. */
    private const NEVER_EXPORT = ['password_hash', 'token_hash', 'code_hash'];

    /**
     * @param array<string, array{column?: string, where?: string, erase?: string, export?: bool, before?: callable, map?: callable}> $tables
     */
    public static function register(Kernel $kernel, string $module, string $prefix, array $tables): void
    {
        foreach (array_keys($tables) as $table) {
            if (!str_starts_with($table, $prefix)) {
                throw new \LogicException("Модуль {$module} объявил чужую таблицу {$table}");
            }
        }

        $kernel->events->on('user.export', static function (array $p) use ($kernel, $module, $tables): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId <= 0) {
                return $p;
            }
            foreach ($tables as $table => $spec) {
                if (($spec['export'] ?? true) === false) {
                    continue;
                }
                [$where, $params] = self::where($spec, $userId);
                $rows = $kernel->db()->all("SELECT * FROM {$table} WHERE {$where}", $params);
                foreach ($rows as &$row) {
                    foreach (self::NEVER_EXPORT as $secret) {
                        unset($row[$secret]);
                    }
                    if (isset($spec['map']) && is_callable($spec['map'])) {
                        $row = ($spec['map'])($row, $userId);
                    }
                }
                unset($row);
                $p['sections'][$module][$table] = $rows;
            }
            return $p;
        }, $module);

        $kernel->events->on('user.erase', static function (array $p) use ($kernel, $module, $tables): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId <= 0) {
                return $p;
            }
            // Файлы на диске не откатываются транзакцией — их стирают первыми:
            // лучше строка без файла, чем файл без строки, о котором никто не знает.
            foreach ($tables as $spec) {
                if (isset($spec['before']) && is_callable($spec['before'])) {
                    ($spec['before'])($userId);
                }
            }
            $kernel->db()->transaction(static function (Db $db) use ($tables, $userId): void {
                foreach ($tables as $table => $spec) {
                    if (isset($spec['erase'])) {
                        $db->run($spec['erase'], array_fill(0, substr_count($spec['erase'], '?'), $userId));
                        continue;
                    }
                    [$where, $params] = self::where($spec, $userId);
                    $db->run("DELETE FROM {$table} WHERE {$where}", $params);
                }
            });
            $p['erased'][] = $module;
            return $p;
        }, $module);
    }

    /** @return array{0: string, 1: array<int, int>} */
    private static function where(array $spec, int $userId): array
    {
        $where = $spec['where'] ?? (($spec['column'] ?? 'user_id') . ' = ?');
        return [$where, array_fill(0, substr_count($where, '?'), $userId)];
    }
}
