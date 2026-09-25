<?php
declare(strict_types=1);

namespace Modules\Squad\Domain;

use App\Contracts\Auth;
use App\Kernel;
use App\Result;

/**
 * Пул кандидатов на сквад.
 *
 * Человек попадает сюда сам, как только завершил онбординг: модуль
 * слушает onboarding.completed и берёт из события нужные алгоритму поля.
 * Таблицы онбординга мы не читаем — если профиль понадобится позже
 * (модуль включили, когда люди уже прошли онбординг), он запрашивается
 * событием squad.profile_lookup, на которое отвечает Onboarding.
 */
final class Pool
{
    public const COMMIT_LEVELS = [1, 2, 3];

    public function __construct(private Kernel $kernel)
    {
    }

    public function find(int $userId): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM squad_pool WHERE user_id = ?', [$userId]);
    }

    /**
     * Постановка в пул. Повторный вызов обновляет снимок, но не трогает
     * тех, кто уже распределён: состав меняет только модератор.
     */
    public function join(int $userId, array $profile, array $baseline, ?string $today = null): Result
    {
        $today = $today ?? gmdate('Y-m-d');
        if (($profile['goal_dir'] ?? '') === '' || empty($profile['tier'])) {
            return Result::fail('profile_incomplete');
        }

        $user = $this->kernel->container->get(Auth::class)->userById($userId);
        $lang = (string) ($user['lang'] ?? 'ru');

        $age = isset($profile['age'])
            ? (int) $profile['age']
            : (int) gmdate('Y') - (int) ($profile['birth_year'] ?? gmdate('Y'));
        $bmi = isset($profile['bmi'])
            ? (float) $profile['bmi']
            : $this->bmi((float) ($profile['weight_kg'] ?? 0), (int) ($profile['height_cm'] ?? 0));

        $snapshot = [
            'goal_dir'    => (string) $profile['goal_dir'],
            'sex'         => (string) ($profile['sex'] ?? 'male'),
            'age'         => $age,
            'lang'        => in_array($lang, ['ru', 'uz'], true) ? $lang : 'ru',
            'tier'        => (string) $profile['tier'],
            'steps'       => (int) ($baseline['steps_med'] ?? $baseline['steps'] ?? 0),
            'time_budget' => (int) ($profile['time_budget'] ?? 30),
            'bmi'         => round($bmi, 1),
            'window'      => (string) ($profile['window'] ?? 'evening'),
            'social'      => (int) ($profile['social'] ?? 2),
            'experience'  => (string) ($profile['experience'] ?? ''),
            'updated_at'  => gmdate('c'),
        ];

        $existing = $this->find($userId);
        if ($existing !== null) {
            if (in_array($existing['status'], ['proposed', 'placed'], true)) {
                return Result::ok($existing);
            }
            $this->kernel->db()->update('squad_pool', $snapshot + ['status' => 'waiting'], 'user_id = :uid', ['uid' => $userId]);
        } else {
            $wave = $this->openWave($today);
            $this->kernel->db()->insert('squad_pool', $snapshot + [
                'user_id'   => $userId,
                'wave_id'   => $wave['id'] ?? null,
                'status'    => 'waiting',
                'joined_at' => gmdate('c'),
            ]);
        }

        $row = $this->find($userId);
        $this->kernel->events->emit('squad.pool_joined', ['user_id' => $userId, 'wave_id' => $row['wave_id'] ?? null]);
        $this->scheduleSeason($row);

        return Result::ok($row);
    }

    /**
     * Два вопроса, которых нет в онбординге, но они нужны подбору:
     * согласие на смешанный сквад и серьёзность намерения.
     */
    public function setPrefs(int $userId, mixed $mixedOk, mixed $commit): Result
    {
        $row = $this->find($userId);
        if ($row === null) {
            return Result::fail('not_in_pool');
        }
        if (!in_array((int) $commit, self::COMMIT_LEVELS, true)) {
            return Result::fail('bad_commit');
        }

        $this->kernel->db()->update('squad_pool', [
            'mixed_ok'     => filter_var($mixedOk, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'commit_level' => (int) $commit,
            'prefs_set'    => 1,
            'updated_at'   => gmdate('c'),
        ], 'user_id = :uid', ['uid' => $userId]);

        return Result::ok($this->find($userId));
    }

    public function withdraw(int $userId): void
    {
        $this->kernel->db()->update('squad_pool', ['status' => 'withdrawn', 'updated_at' => gmdate('c')], 'user_id = :uid', ['uid' => $userId]);
    }

    public function setStatus(array $userIds, string $status): void
    {
        foreach ($userIds as $id) {
            $this->kernel->db()->update('squad_pool', ['status' => $status, 'updated_at' => gmdate('c')], 'user_id = :uid', ['uid' => (int) $id]);
        }
    }

    /** Кандидаты волны в формате, который понимает Matcher. */
    public function candidates(?int $waveId, bool $includeUnassigned = true): array
    {
        $rows = $waveId === null
            ? $this->kernel->db()->all("SELECT * FROM squad_pool WHERE status = 'waiting' AND wave_id IS NULL ORDER BY user_id")
            : $this->kernel->db()->all(
                "SELECT * FROM squad_pool WHERE status = 'waiting' AND (wave_id = ?" . ($includeUnassigned ? ' OR wave_id IS NULL' : '') . ') ORDER BY user_id',
                [$waveId]
            );

        return array_map([self::class, 'toCandidate'], $rows);
    }

    public static function toCandidate(array $row): array
    {
        return [
            'user_id'     => (int) $row['user_id'],
            'goal_dir'    => (string) $row['goal_dir'],
            'sex'         => (string) $row['sex'],
            'age'         => (int) $row['age'],
            'lang'        => (string) $row['lang'],
            'tier'        => (string) $row['tier'],
            'steps'       => (int) $row['steps'],
            'time_budget' => (int) $row['time_budget'],
            'bmi'         => (float) $row['bmi'],
            'window'      => (string) $row['window'],
            'social'      => (int) $row['social'],
            'experience'  => (string) $row['experience'],
            'mixed_ok'    => (int) $row['mixed_ok'] === 1,
            'commit'      => (int) $row['commit_level'],
        ];
    }

    /** @return array<string, true> пары «коллеги или родня» */
    public function related(): array
    {
        $out = [];
        foreach ($this->kernel->db()->all('SELECT user_a, user_b FROM squad_relations') as $r) {
            $out[Matcher::relationKey((int) $r['user_a'], (int) $r['user_b'])] = true;
        }
        return $out;
    }

    public function relate(int $a, int $b, string $kind = 'known'): void
    {
        if ($a === $b || $a <= 0 || $b <= 0) {
            return;
        }
        $this->kernel->db()->run(
            'INSERT OR IGNORE INTO squad_relations (user_a, user_b, kind, created_at) VALUES (?, ?, ?, ?)',
            [min($a, $b), max($a, $b), $kind, gmdate('c')]
        );
    }

    /** Ближайшая открытая волна, ещё не стартовавшая. */
    public function openWave(?string $today = null): ?array
    {
        return $this->kernel->db()->first(
            "SELECT * FROM squad_waves WHERE status = 'open' AND start_date >= ? ORDER BY start_date LIMIT 1",
            [$today ?? gmdate('Y-m-d')]
        );
    }

    /**
     * Сообщаем, когда у человека день 1: планировщик сдвинет план, чтобы
     * все в волне шли по одному календарю (Р-07). Планировщик про сквады
     * не знает — событие общее.
     */
    public function scheduleSeason(?array $row): void
    {
        if ($row === null || empty($row['wave_id'])) {
            return;
        }
        $wave = $this->kernel->db()->first('SELECT start_date FROM squad_waves WHERE id = ?', [(int) $row['wave_id']]);
        if ($wave !== null) {
            $this->kernel->events->emit('season.scheduled', [
                'user_ids'   => [(int) $row['user_id']],
                'start_date' => (string) $wave['start_date'],
            ]);
        }
    }

    private function bmi(float $weight, int $heightCm): float
    {
        if ($heightCm <= 0) {
            return 0.0;
        }
        $m = $heightCm / 100;
        return $weight / ($m * $m);
    }
}
