<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Тонкая обёртка над PDO/SQLite.
 *
 * ПРАВИЛО ПРОЕКТА: модуль читает и пишет ТОЛЬКО свои таблицы —
 * те, что начинаются с его префикса (identity_, squad_, checkin_ ...).
 * Данные чужого модуля берутся через контракт или событие, а не JOIN-ом.
 * Это то, что позволит вынести модуль в отдельный сервис без переписывания.
 */
final class Db
{
    private ?PDO $pdo = null;
    private int $queries = 0;

    public function __construct(private string $path, private bool $debug = false)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать папку для базы: {$dir}");
        }

        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // WAL даёт одновременное чтение при записи — критично для дешёвого хостинга.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        return $this->pdo = $pdo;
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $this->queries++;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? $default : $row[0];
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn($c) => ':' . $c, $cols))
        );
        $this->run($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = implode(', ', array_map(static fn($c) => "{$c} = :{$c}", array_keys($data)));
        return $this->run("UPDATE {$table} SET {$sets} WHERE {$where}", $data + $params)->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function tableExists(string $table): bool
    {
        return (bool) $this->value(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?",
            [$table]
        );
    }

    /** @return array<int, string> все таблицы модуля по префиксу */
    public function tablesWithPrefix(string $prefix): array
    {
        $rows = $this->all(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ? ORDER BY name",
            [$prefix . '%']
        );
        return array_column($rows, 'name');
    }

    public function queryCount(): int
    {
        return $this->queries;
    }

    public function path(): string
    {
        return $this->path;
    }
}
