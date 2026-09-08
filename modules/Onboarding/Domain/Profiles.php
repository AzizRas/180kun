<?php
declare(strict_types=1);

namespace Modules\Onboarding\Domain;

use App\Kernel;
use App\Result;

/**
 * Сценарий онбординга целиком. HTTP не знает — возвращает Result,
 * поэтому те же шаги можно провести из Telegram-бота.
 *
 * Порядок: анкета → скрининг → нулевой цикл (7 дней) → готов.
 */
final class Profiles
{
    public const ZERO_CYCLE_DAYS = 7;

    public function __construct(private Kernel $kernel)
    {
    }

    public function find(int $userId): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM onboarding_profiles WHERE user_id = ?', [$userId]);
    }

    public function baseline(int $userId): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM onboarding_baseline WHERE user_id = ?', [$userId]);
    }

    /** Состояние онбординга для интерфейса. */
    public function state(int $userId): array
    {
        $profile  = $this->find($userId);
        $baseline = $this->baseline($userId);

        if ($profile === null) {
            return ['step' => 'questions', 'profile' => null, 'baseline' => null];
        }
        if ($profile['status'] === 'rejected') {
            return ['step' => 'rejected', 'reason' => $profile['reject_reason'], 'profile' => null, 'baseline' => null];
        }

        $step = match ($profile['status']) {
            'draft'      => 'screening',
            'zero_cycle' => 'zero_cycle',
            default      => 'ready',
        };

        return [
            'step'       => $step,
            'profile'    => $this->publicProfile($profile),
            'baseline'   => $baseline,
            'days_left'  => $baseline === null
                ? self::ZERO_CYCLE_DAYS
                : max(0, self::ZERO_CYCLE_DAYS - (int) $baseline['days']),
        ];
    }

    /** Шаг 1: ответы на восемь вопросов. */
    public function saveAnswers(int $userId, array $input): Result
    {
        [$clean, $errors] = Questions::validate($input);
        if ($errors !== []) {
            return Result::fail('invalid_answers', ['fields' => $errors]);
        }

        $existing = $this->find($userId);
        $data     = [
            'goal_dir'    => $clean['goal_dir'],
            'sex'         => $clean['sex'],
            'birth_year'  => $clean['birth_year'],
            'height_cm'   => $clean['height_cm'],
            'weight_kg'   => $clean['weight_kg'],
            'target_kg'   => $clean['target_kg'] ?? null,
            'time_budget' => $clean['time_budget'],
            'window'      => $clean['window'],
            'social'      => $clean['social'],
            'experience'  => $clean['experience'],
            'constraints' => $clean['constraints'],
            'fasting'     => $clean['fasting'],
            'status'      => 'draft',
        ];

        if ($existing === null) {
            $this->kernel->db()->insert('onboarding_profiles', $data + [
                'user_id'    => $userId,
                'created_at' => gmdate('c'),
            ]);
        } else {
            $this->kernel->db()->update('onboarding_profiles', $data, 'user_id = :uid', ['uid' => $userId]);
        }

        return Result::ok($this->publicProfile($this->find($userId)));
    }

    /** Шаг 2: анкета противопоказаний. Здесь решается допуск. */
    public function saveScreening(int $userId, array $answers): Result
    {
        $profile = $this->find($userId);
        if ($profile === null) {
            return Result::fail('no_profile');
        }

        $verdict = Screening::evaluate($profile, $answers);

        $this->kernel->db()->run('DELETE FROM onboarding_screening WHERE user_id = ?', [$userId]);
        $this->kernel->db()->insert('onboarding_screening', [
            'user_id'      => $userId,
            'answers'      => json_encode($verdict['answers']),
            'passed'       => $verdict['ok'] ? 1 : 0,
            'needs_doctor' => $verdict['needs_doctor'] ? 1 : 0,
            'created_at'   => gmdate('c'),
        ]);

        if (!$verdict['ok']) {
            $this->kernel->db()->update('onboarding_profiles', [
                'status'        => 'rejected',
                'reject_reason' => $verdict['reject'],
            ], 'user_id = :uid', ['uid' => $userId]);

            $this->kernel->events->emit('onboarding.rejected', [
                'user_id' => $userId,
                'reason'  => $verdict['reject'],
            ]);

            return Result::fail('not_eligible', [
                'reason'       => $verdict['reject'],
                'needs_doctor' => $verdict['needs_doctor'],
            ]);
        }

        $this->kernel->db()->update(
            'onboarding_profiles',
            ['status' => 'zero_cycle'],
            'user_id = :uid',
            ['uid' => $userId]
        );

        return Result::ok([
            'needs_doctor' => $verdict['needs_doctor'],
            'bmi'          => $verdict['bmi'],
        ]);
    }

    /**
     * Шаг 3: нулевой цикл. Данные накапливаются день за днём; когда
     * набралось 7 дней, ступень определена и план можно строить.
     *
     * @param array $day steps, workouts, rhr, sleep_min, weight_kg
     */
    public function addBaselineDay(int $userId, array $day, string $source = 'manual'): Result
    {
        $profile = $this->find($userId);
        if ($profile === null || $profile['status'] === 'rejected') {
            return Result::fail('no_profile');
        }

        $current = $this->baseline($userId);
        $days    = (int) ($current['days'] ?? 0);

        // Скользящее накопление: храним агрегаты, а не сырые дни —
        // сырые дни появятся в модуле Wearable на срезе 5.
        $newDays  = min(self::ZERO_CYCLE_DAYS, $days + 1);
        $prevMed  = (int) ($current['steps_med'] ?? 0);
        $stepsNew = (int) ($day['steps'] ?? 0);
        $median   = $days === 0
            ? $stepsNew
            : (int) round(($prevMed * $days + $stepsNew) / ($days + 1));

        $data = [
            'steps_med'   => $median,
            'workouts'    => (int) ($day['workouts'] ?? ($current['workouts'] ?? 0)),
            'rhr'         => isset($day['rhr']) ? (int) $day['rhr'] : ($current['rhr'] ?? null),
            'sleep_min'   => isset($day['sleep_min']) ? (int) $day['sleep_min'] : ($current['sleep_min'] ?? null),
            'weight_kg'   => isset($day['weight_kg']) ? (float) $day['weight_kg'] : ($current['weight_kg'] ?? $profile['weight_kg']),
            'days'        => $newDays,
            'source'      => $source,
            'captured_at' => gmdate('c'),
        ];

        if ($current === null) {
            $this->kernel->db()->insert('onboarding_baseline', $data + ['user_id' => $userId]);
        } else {
            $this->kernel->db()->update('onboarding_baseline', $data, 'user_id = :uid', ['uid' => $userId]);
        }

        return Result::ok(['days' => $newDays, 'left' => self::ZERO_CYCLE_DAYS - $newDays]);
    }

    /**
     * Завершение онбординга: ставим ступень и публикуем событие.
     * План строит не этот модуль — он только сообщает, что готов.
     */
    public function complete(int $userId, bool $force = false): Result
    {
        $profile  = $this->find($userId);
        $baseline = $this->baseline($userId);

        if ($profile === null || $profile['status'] === 'rejected') {
            return Result::fail('no_profile');
        }
        if ($baseline === null) {
            return Result::fail('no_baseline');
        }
        if (!$force && (int) $baseline['days'] < self::ZERO_CYCLE_DAYS) {
            return Result::fail('zero_cycle_incomplete', [
                'days' => (int) $baseline['days'],
                'need' => self::ZERO_CYCLE_DAYS,
            ]);
        }

        $tier = Tier::classify((int) $baseline['steps_med'], (int) $baseline['workouts']);

        $this->kernel->db()->update('onboarding_profiles', [
            'tier'         => $tier,
            'status'       => 'ready',
            'completed_at' => gmdate('c'),
        ], 'user_id = :uid', ['uid' => $userId]);

        $profile         = $this->find($userId);
        $profile['tier'] = $tier;

        $this->kernel->events->emit('baseline.captured', ['user_id' => $userId, 'baseline' => $baseline]);
        $this->kernel->events->emit('onboarding.completed', [
            'user_id'  => $userId,
            'profile'  => $profile,
            'baseline' => $baseline,
        ]);

        return Result::ok(['tier' => $tier, 'profile' => $this->publicProfile($profile)]);
    }

    /** Профиль без чувствительных полей — их не отдаём наружу лишний раз. */
    public function publicProfile(array $row): array
    {
        return [
            'goal_dir'    => $row['goal_dir'],
            'sex'         => $row['sex'],
            'age'         => (int) gmdate('Y') - (int) $row['birth_year'],
            'height_cm'   => (int) $row['height_cm'],
            'weight_kg'   => (float) $row['weight_kg'],
            'target_kg'   => $row['target_kg'] !== null ? (float) $row['target_kg'] : null,
            'bmi'         => Screening::bmi((float) $row['weight_kg'], (int) $row['height_cm']),
            'time_budget' => (int) $row['time_budget'],
            'window'      => $row['window'],
            'social'      => (int) $row['social'],
            'fasting'     => (int) $row['fasting'] === 1,
            'tier'        => $row['tier'],
            'status'      => $row['status'],
        ];
    }
}
