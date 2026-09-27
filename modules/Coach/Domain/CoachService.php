<?php
declare(strict_types=1);

namespace Modules\Coach\Domain;

use App\Contracts\Coach;
use App\Kernel;

/**
 * Тренер (контракт Coach).
 *
 * Порядок всегда один:
 *   1. контекст из данных (код);
 *   2. состояние и флаги нагрузки (код), облегчение плана (код);
 *   3. шаблон с числами человека — готов ВСЕГДА;
 *   4. если есть согласие, ключ и лимит — модель пишет свой вариант;
 *   5. вариант модели проверяется красными линиями; не прошёл — шаблон.
 * Продукт не падает вместе с чужим API (Р-22).
 */
final class CoachService implements Coach
{
    private const TOKENS = ['checkin' => 220, 'week' => 700];

    public function __construct(private Kernel $kernel, private Context $context)
    {
    }

    public function isLive(): bool
    {
        return $this->client()->isAvailable();
    }

    public function classifyState(array $context): string
    {
        $userId = (int) ($context['user_id'] ?? 0);
        if ($userId <= 0) {
            return 'steady';
        }
        return Classifier::classify($this->context->build($userId, $context['date'] ?? null)['window'])['state'];
    }

    public function replyToCheckin(array $context): array
    {
        $userId = (int) ($context['user_id'] ?? 0);
        $date   = (string) ($context['date'] ?? gmdate('Y-m-d'));
        if ($userId <= 0) {
            return ['text' => '', 'action' => null, 'source' => 'none'];
        }

        $ctx   = $this->context->build($userId, $date);
        $state = Classifier::classify($ctx['window']);
        $load  = LoadRules::evaluate($ctx['window']);

        // Облегчение — сразу и без спроса (Р-13). Решает план, не мы:
        // мы только сообщаем, что сигнал есть.
        if ($load['level'] !== 'none') {
            $this->kernel->events->emit('plan.load_adjust', [
                'user_id' => $userId,
                'factor'  => $load['factor'],
                'from'    => $date,
                'days'    => $load['days'],
                'level'   => $load['level'],
                'reasons' => $load['reasons'],
                'applied' => false,
            ]);
            $ctx = $this->context->build($userId, $date);   // план изменился — числа тоже
        }

        $template = $this->checkinTemplate($ctx['facts'], $state['state'], $load, (string) ($context['done'] ?? ''), $ctx['lang']);
        $result   = $this->withModel('checkin', $userId, $ctx, $state, $load, $template);

        $id = $this->save($userId, 'checkin', $date, $state['state'], $result);
        $this->kernel->events->emit('coach.reply', ['user_id' => $userId, 'kind' => 'checkin', 'source' => $result['source']]);

        return [
            'id'     => $id,
            'text'   => $result['text'],
            'action' => null,
            'source' => $result['source'],
            'state'  => $state['state'],
            'load'   => $load['level'],
        ];
    }

    public function weeklyReview(array $context): array
    {
        $userId = (int) ($context['user_id'] ?? 0);
        $today  = (string) ($context['date'] ?? gmdate('Y-m-d'));
        $ref    = gmdate('o-\WW', strtotime($today . ' 00:00:00 UTC'));

        $cached = $this->kernel->db()->first(
            "SELECT * FROM coach_msgs WHERE user_id = ? AND kind = 'week' AND ref = ?",
            [$userId, $ref]
        );
        if ($cached !== null) {
            return self::view($cached);
        }

        $ctx      = $this->context->build($userId, $today);
        $state    = Classifier::classify($ctx['window']);
        $load     = LoadRules::evaluate($ctx['window']);
        $template = $this->weekTemplate($ctx['facts'], $ctx['lang']);
        $result   = $this->withModel('week', $userId, $ctx, $state, $load, $template);

        $id = $this->save($userId, 'week', $ref, $state['state'], $result);
        return self::view($this->kernel->db()->first('SELECT * FROM coach_msgs WHERE id = ?', [$id])) + ['facts' => $ctx['facts']];
    }

    // ---------- согласие ----------

    public function hasConsent(int $userId): bool
    {
        return (int) $this->kernel->db()->value('SELECT ai FROM coach_consent WHERE user_id = ?', [$userId], 0) === 1;
    }

    public function consentAsked(int $userId): bool
    {
        return $this->kernel->db()->value('SELECT 1 FROM coach_consent WHERE user_id = ?', [$userId]) !== null;
    }

    public function setConsent(int $userId, bool $ai): void
    {
        $this->kernel->db()->run(
            'INSERT OR REPLACE INTO coach_consent (user_id, ai, updated_at) VALUES (?, ?, ?)',
            [$userId, $ai ? 1 : 0, gmdate('c')]
        );
    }

    public function feedback(int $userId, int $messageId, bool $useful): bool
    {
        $row = $this->kernel->db()->first('SELECT id FROM coach_msgs WHERE id = ? AND user_id = ?', [$messageId, $userId]);
        if ($row === null) {
            return false;
        }
        $this->kernel->db()->update('coach_msgs', ['useful' => $useful ? 1 : 0], 'id = :id', ['id' => $messageId]);
        return true;
    }

    // ---------- модель ----------

    /** @return array{text: string, source: string, rejected: ?array, tokens_in: int, tokens_out: int} */
    private function withModel(string $kind, int $userId, array $ctx, array $state, array $load, string $template): array
    {
        $fallback = ['text' => $template, 'source' => 'template', 'rejected' => null, 'calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0];

        // ИИ — часть сезона (Р-20: в Нулевом цикле «без ИИ-диалога»).
        $paid = $this->kernel->container->get(\App\Contracts\Access::class)->hasSeason($userId);
        if (!$paid || !$this->hasConsent($userId) || !$this->client()->isAvailable() || $this->overLimit($userId)) {
            return $fallback;
        }

        $model   = (string) $this->kernel->config->get($kind === 'week' ? 'ai.model_deep' : 'ai.model_fast');
        $timeout = min((int) $this->kernel->config->get('ai.timeout', 20), $kind === 'week' ? 25 : 8);
        $answer  = $this->client()->complete(
            $model,
            $this->systemPrompt($kind, $ctx['lang']),
            json_encode([
                'kind'    => $kind,
                'state'   => $state['state'],
                'signals' => $state['reasons'],
                'load'    => ['level' => $load['level'], 'reasons' => $load['reasons']],
                'profile' => $ctx['profile'],
                'facts'   => $ctx['facts'],
                'action'  => $ctx['plan']['action']['key'] ?? null,
                'fallback'=> $template,
            ], JSON_UNESCAPED_UNICODE),
            self::TOKENS[$kind],
            $timeout
        );
        if ($answer === null) {
            return ['calls' => 1] + $fallback;
        }

        $text = trim($answer['text']);
        $bad  = Guard::violations($text, $ctx['facts'], $kind);
        if ($bad !== []) {
            $this->kernel->db()->insert('coach_rejections', ['kind' => $kind, 'codes' => json_encode($bad), 'created_at' => gmdate('c')]);
            return ['rejected' => $bad, 'calls' => 1, 'tokens_in' => $answer['tokens_in'], 'tokens_out' => $answer['tokens_out']] + $fallback;
        }

        return ['text' => $text, 'source' => 'model', 'rejected' => null, 'calls' => 1, 'tokens_in' => $answer['tokens_in'], 'tokens_out' => $answer['tokens_out']];
    }

    private function overLimit(int $userId): bool
    {
        // Считаются обращения, а не показанные ответы: отклонённый или
        // упавший вызов тоже стоит денег.
        $n = (int) $this->kernel->db()->value(
            'SELECT COALESCE(SUM(calls), 0) FROM coach_msgs WHERE user_id = ? AND (ref = ? OR created_at LIKE ?)',
            [$userId, gmdate('Y-m-d'), gmdate('Y-m-d') . '%'],
            0
        );
        return $n >= (int) $this->kernel->config->get('ai.daily_limit', 40);
    }

    private function systemPrompt(string $kind, string $lang): string
    {
        $language = $lang === 'uz' ? 'на узбекском языке (латиница)' : 'на русском языке';
        $length   = $kind === 'week' ? 'не больше 6 предложений' : 'не больше 3 предложений';
        return <<<TXT
Ты — тренер программы LEVEL 180 (180 дней, сквад из шести человек). Пиши {$language}, обращайся на «вы», {$length}, без списков и без эмодзи.

Жёсткие правила — ответ, нарушивший любое, будет выброшен:
1. Используй минимум два числа из поля facts ровно как они даны. Других чисел не придумывай.
2. Одно конкретное действие, не список советов.
3. Нельзя: лекарства, добавки, витамины, диагнозы, лечение, анализы, калории, голодание, «навсегда», сравнение с другими людьми, оценки тела.
4. Нельзя слова: «провал», «сорвался», «опять», «должен был», «сила воли». Никакого стыда.
5. Нельзя банальности: «главное — постоянство», «вы справитесь», «верьте в себя», «маленькие шаги ведут к большим результатам».
6. Нагрузку считает не модель. Если load.level не none — объясни облегчение числами из facts (adj_pct, adj_days) и скажи, что это ошибка плана, не человека.
7. state уже определён кодом: steady — всё идёт; obstacle — помеха (событие), ничего не уменьшаем; overload — перегруз; drift — чек-ины есть, действий нет: предложи другой, более короткий формат действия.
8. Если сомневаешься — верни текст из поля fallback.
TXT;
    }

    private function client(): LlmClient
    {
        return $this->kernel->container->get(LlmClient::class);
    }

    // ---------- шаблоны ----------

    private function checkinTemplate(array $facts, string $state, array $load, string $done, string $lang): string
    {
        $keys = [];
        if ($load['level'] === 'red') {
            $keys[] = 'coach.tpl.red';
        }
        if ($load['level'] === 'yellow') {
            foreach ($load['reasons'] as $r) {
                $keys[] = 'coach.tpl.yellow.' . $r;
            }
            $keys[] = 'coach.tpl.yellow';
        }
        $keys[] = 'coach.tpl.' . $state;
        if ($facts['done'] >= $facts['norm']) {
            $keys[] = 'coach.tpl.kept';
        }
        $keys[] = $done === 'no' ? 'coach.tpl.steady.no' : 'coach.tpl.steady.yes';

        foreach ($keys as $key) {
            $text = $this->fill($key, $facts, $lang);
            if ($text !== null) {
                return $text;
            }
        }
        return (string) $this->fill('coach.tpl.steady.yes', $facts, $lang);
    }

    private function weekTemplate(array $facts, string $lang): string
    {
        $parts = [(string) $this->fill('coach.tpl.week.base', $facts, $lang)];
        foreach (['coach.tpl.week.steps', 'coach.tpl.week.sleep', 'coach.tpl.week.weight'] as $k) {
            $line = $this->fill($k, $facts, $lang);
            if ($line !== null) {
                $parts[] = $line;
            }
        }
        $parts[] = (string) $this->fill('coach.tpl.week.next', $facts, $lang);
        return implode(' ', $parts);
    }

    /** Шаблон с подстановкой; null, если для него не хватает чисел. */
    private function fill(string $key, array $facts, string $lang): ?string
    {
        $text = $this->kernel->i18n->t($key, [], $lang);
        if ($text === $key) {
            return null;
        }
        if (preg_match_all('/\{(\w+)\}/', $text, $m)) {
            foreach ($m[1] as $name) {
                if (!array_key_exists($name, $facts)) {
                    return null;
                }
            }
        }
        $replace = [];
        foreach ($facts as $k => $v) {
            $replace[$k] = is_float($v) ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') : (string) $v;
        }
        return $this->kernel->i18n->t($key, $replace, $lang);
    }

    private function save(int $userId, string $kind, string $ref, string $state, array $r): int
    {
        $this->kernel->db()->run(
            'INSERT INTO coach_msgs (user_id, kind, ref, state, text, source, rejected, calls, tokens_in, tokens_out, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, kind, ref) DO UPDATE SET
               state = excluded.state, text = excluded.text, source = excluded.source, rejected = excluded.rejected,
               calls = coach_msgs.calls + excluded.calls,
               tokens_in = coach_msgs.tokens_in + excluded.tokens_in, tokens_out = coach_msgs.tokens_out + excluded.tokens_out',
            [$userId, $kind, $ref, $state, $r['text'], $r['source'], $r['rejected'] ? json_encode($r['rejected']) : null,
             $r['calls'], $r['tokens_in'], $r['tokens_out'], gmdate('c')]
        );
        return (int) $this->kernel->db()->value('SELECT id FROM coach_msgs WHERE user_id = ? AND kind = ? AND ref = ?', [$userId, $kind, $ref]);
    }

    public static function view(array $row): array
    {
        return [
            'id'     => (int) $row['id'],
            'text'   => (string) $row['text'],
            'source' => (string) $row['source'],
            'state'  => $row['state'],
            'useful' => $row['useful'] === null ? null : (int) $row['useful'] === 1,
            'ref'    => $row['ref'],
        ];
    }
}
