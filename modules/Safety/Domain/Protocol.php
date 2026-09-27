<?php
declare(strict_types=1);

namespace Modules\Safety\Domain;

use App\Contracts\Auth;
use App\Contracts\Notifier;
use App\Kernel;

/**
 * Протокол при триггерной теме (Р-12, § 11):
 *  1. человек сразу получает заранее написанный текст с контактами помощи;
 *  2. соревновательные элементы для него скрываются (режим тишины);
 *  3. модератор-человек получает сигнал — реакция в течение 2 часов.
 * Никаких диагнозов, никаких советов, ни одного слова от ИИ.
 */
final class Protocol
{
    public const QUIET_DAYS = 14;

    public function __construct(private Kernel $kernel)
    {
    }

    /** Проверить текст и, если нужно, запустить протокол. Возвращает помощь для показа или null. */
    /** @param bool $onScreen ответ увидят на экране сразу — лично не дублируем */
    public function check(int $userId, string $text, string $source, bool $onScreen = false): ?array
    {
        $category = Triggers::detect($text);
        if ($category === null || $userId <= 0) {
            return null;
        }
        return $this->trigger($userId, $category, $source, $onScreen);
    }

    public function trigger(int $userId, string $category, string $source, bool $onScreen = false): array
    {
        // Один открытый сигнал в сутки на человека: модератору нужен
        // один вызов к действию, а не десять одинаковых.
        $open = $this->kernel->db()->value(
            'SELECT 1 FROM safety_alerts WHERE user_id = ? AND resolved_at IS NULL AND created_at >= ?',
            [$userId, gmdate('c', time() - 86400)]
        );
        if ($open === null) {
            $this->kernel->db()->insert('safety_alerts', [
                'user_id'    => $userId,
                'source'     => $source,
                'category'   => $category,
                'created_at' => gmdate('c'),
            ]);
            $this->kernel->events->emit('safety.alert', ['user_id' => $userId, 'source' => $source, 'category' => $category]);
        }

        $this->kernel->db()->run(
            'INSERT OR REPLACE INTO safety_state (user_id, quiet_until, updated_at) VALUES (?, ?, ?)',
            [$userId, gmdate('Y-m-d', time() + self::QUIET_DAYS * 86400), gmdate('c')]
        );

        $help = $this->help($userId);
        // Из группы человек не видит ответа на экране — пишем ему лично.
        if ($source !== 'checkin_note' && !$onScreen) {
            $this->kernel->container->get(Notifier::class)->send($userId, $help['text'] . "\n\n" . $help['contacts']);
        }
        return $help;
    }

    /** @return array{text: string, contacts: string} */
    public function help(int $userId): array
    {
        $user = $this->kernel->container->get(Auth::class)->userById($userId);
        $lang = (string) ($user['lang'] ?? 'ru');
        $fromEnv  = trim(str_replace('|', "\n", (string) $this->kernel->config->get('safety.contacts_' . $lang, '')));
        $contacts = $fromEnv !== '' ? $fromEnv : $this->kernel->i18n->t('safety.contacts', [], $lang);

        return [
            'text'     => $this->kernel->i18n->t('safety.help', [], $lang),
            'contacts' => $contacts,
        ];
    }

    public function isQuiet(int $userId, ?string $today = null): bool
    {
        $until = $this->kernel->db()->value('SELECT quiet_until FROM safety_state WHERE user_id = ?', [$userId]);
        return $until !== null && (string) $until >= ($today ?? gmdate('Y-m-d'));
    }

    public function openAlerts(): array
    {
        $auth = $this->kernel->container->get(Auth::class);
        return array_map(static function (array $a) use ($auth): array {
            $u = $auth->userById((int) $a['user_id']);
            return [
                'id'         => (int) $a['id'],
                'user_id'    => (int) $a['user_id'],
                'name'       => trim((string) ($u['name'] ?? '')) ?: '#' . $a['user_id'],
                'source'     => $a['source'],
                'category'   => $a['category'],
                'created_at' => $a['created_at'],
            ];
        }, $this->kernel->db()->all('SELECT * FROM safety_alerts WHERE resolved_at IS NULL ORDER BY created_at'));
    }

    /** Модератор связался с человеком — сигнал закрыт, режим тишины снят. */
    public function resolve(int $alertId, int $moderatorId): bool
    {
        $a = $this->kernel->db()->first('SELECT * FROM safety_alerts WHERE id = ? AND resolved_at IS NULL', [$alertId]);
        if ($a === null) {
            return false;
        }
        $this->kernel->db()->update('safety_alerts', ['resolved_at' => gmdate('c'), 'resolved_by' => $moderatorId], 'id = :id', ['id' => $alertId]);
        $this->kernel->db()->run('DELETE FROM safety_state WHERE user_id = ?', [(int) $a['user_id']]);
        return true;
    }
}
