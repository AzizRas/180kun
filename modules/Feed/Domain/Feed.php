<?php
declare(strict_types=1);

namespace Modules\Feed\Domain;

use App\Contracts\Access;
use App\Contracts\Media;
use App\Contracts\Team;
use App\Kernel;
use App\Result;

/**
 * Лента сквада.
 *
 * Правила, из которых всё следует:
 *  - видят только сокомандники: зритель и автор сейчас в одном скваде;
 *  - в ленту идёт действие, а не тело (§ 11): человек подтверждает это
 *    перед публикацией, сокомандник может пожаловаться — фото скрывается
 *    сразу, до решения модератора;
 *  - никаких счётчиков и рейтингов: одна реакция «поддержал», имена
 *    поддержавших видит только автор, порядок — только по времени (Р-16);
 *  - подпись проходит проверку безопасности, как заметка в чек-ине.
 */
final class Feed
{
    public const TAGS     = ['plate', 'gym', 'walk', 'workout', 'other'];
    public const REASONS  = ['body', 'face', 'offensive', 'spam', 'other'];
    /** Жалобы, после которых фото скрывается сразу, не дожидаясь второй. */
    public const HIDE_NOW = ['body', 'face'];
    public const PAGE     = 20;
    /** Не чаще одного сообщения о новых фото в чат сквада за это время. */
    public const ANNOUNCE_GAP = 7200;

    public function __construct(private Kernel $kernel)
    {
    }

    private function team(): Team { return $this->kernel->container->get(Team::class); }
    private function media(): Media { return $this->kernel->container->get(Media::class); }

    // ================= состояние и лента =================

    /**
     * Можно ли смотреть и можно ли выкладывать — и почему нет.
     * reason: no_team | finished | no_season | media_off
     */
    public function state(int $userId): array
    {
        $team  = $this->team()->teamOf($userId);
        $media = $this->media()->available();
        $left  = max(0, (int) $this->kernel->config->get('feed.posts_per_day', 3) - $this->postsToday($userId));

        $reason = null;
        if ($team === null) {
            $reason = 'no_team';
        } elseif ($team['status'] !== 'active') {
            $reason = 'finished';
        } elseif (!$this->kernel->container->get(Access::class)->hasSeason($userId)) {
            $reason = 'no_season';
        } elseif (!$media['ok']) {
            $reason = 'media_off';
        }

        return [
            'can_read'  => $team !== null,
            'can_post'  => $reason === null && $left > 0,
            'reason'    => $reason,
            'media_reason' => $media['ok'] ? null : $media['reason'],
            'left_today'=> $left,
            'team'      => $team === null ? null : ['id' => $team['id'], 'name' => $team['name'], 'status' => $team['status']],
            'tags'      => self::TAGS,
            'reasons'   => self::REASONS,
            'caption_max' => (int) $this->kernel->config->get('feed.caption_max', 280),
            'max_mb'    => (int) $this->kernel->config->get('media.max_upload_mb', 10),
        ];
    }

    public function list(int $viewerId, ?int $before = null): Result
    {
        $team = $this->team()->teamOf($viewerId);
        if ($team === null) {
            return Result::fail('no_team');
        }
        $names   = array_column($team['members'], 'name', 'user_id');
        $authors = array_keys($names);
        $marks   = implode(',', array_fill(0, count($authors), '?'));

        // Скрытое (на проверке) видит только автор; удалённое — никто.
        $rows = $this->kernel->db()->all(
            "SELECT * FROM feed_posts
             WHERE team_id = ? AND user_id IN ({$marks})
               AND (status = 'visible' OR (status = 'hidden' AND user_id = ?))
               AND id < ?
             ORDER BY id DESC LIMIT " . (self::PAGE + 1),
            array_merge([$team['id']], $authors, [$viewerId, $before ?? PHP_INT_MAX])
        );

        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        return Result::ok([
            'posts'  => array_map(fn(array $p) => $this->view($p, $viewerId, $names), $rows),
            'before' => $more ? (int) end($rows)['id'] : null,
        ]);
    }

    private function view(array $p, int $viewerId, array $names): array
    {
        $mine      = (int) $p['user_id'] === $viewerId;
        $supporters = array_map('intval', array_column($this->kernel->db()->all(
            'SELECT user_id FROM feed_support WHERE post_id = ? ORDER BY created_at', [(int) $p['id']]
        ), 'user_id'));
        $mediaId = $p['media_id'] !== null ? (int) $p['media_id'] : null;

        return [
            'id'         => (int) $p['id'],
            'author'     => ['id' => (int) $p['user_id'], 'name' => $names[(int) $p['user_id']] ?? ('#' . $p['user_id'])],
            'mine'       => $mine,
            'tag'        => $p['tag'],
            'caption'    => $p['caption'],
            'created_at' => $p['created_at'],
            'status'     => $p['status'],
            'thumb'      => $mediaId ? $this->media()->url($mediaId, $viewerId, 'thumb') : null,
            'full'       => $mediaId ? $this->media()->url($mediaId, $viewerId, 'full') : null,
            'supported'  => in_array($viewerId, $supporters, true),
            // Кто поддержал — видит только автор. Остальным — ни имён, ни числа.
            'supporters' => $mine ? array_map(static fn(int $id) => $names[$id] ?? ('#' . $id), $supporters) : null,
            'reported'   => !$mine && $this->kernel->db()->value('SELECT 1 FROM feed_reports WHERE post_id = ? AND user_id = ?', [(int) $p['id'], $viewerId]) !== null,
        ];
    }

    // ================= публикация =================

    public function post(int $userId, string $bytes, string $tag, string $caption, bool $confirmed): Result
    {
        $state = $this->state($userId);
        if ($state['reason'] !== null) {
            return Result::fail($state['reason'], $state['media_reason'] ? ['media_reason' => $state['media_reason']] : []);
        }
        if ($state['left_today'] <= 0) {
            return Result::fail('daily_limit', ['limit' => (int) $this->kernel->config->get('feed.posts_per_day', 3)]);
        }
        if (!$confirmed) {
            return Result::fail('confirm_rules');
        }

        $tag     = in_array($tag, self::TAGS, true) ? $tag : 'other';
        $caption = trim(preg_replace('/\s+/u', ' ', strip_tags($caption)) ?? '');
        if (mb_strlen($caption) > $state['caption_max']) {
            return Result::fail('caption_long', ['max' => $state['caption_max']]);
        }

        // Подпись — тот же текст от человека, что и заметка в чек-ине:
        // тревожные слова уходят не в ленту, а в кризисный протокол.
        if ($caption !== '') {
            $check = $this->kernel->events->emit('safety.check_text', ['user_id' => $userId, 'text' => $caption, 'source' => 'feed', 'on_screen' => true, 'safety' => null]);
            if (!empty($check['safety'])) {
                return Result::fail('safety', ['safety' => $check['safety']]);
            }
        }

        $stored = $this->media()->store($userId, $bytes, 'feed');
        if (!$stored->ok) {
            return Result::fail('media.' . $stored->error, $stored->meta);
        }

        $team = $this->team()->teamOf($userId);
        $id   = $this->kernel->db()->insert('feed_posts', [
            'user_id'    => $userId,
            'team_id'    => (int) $team['id'],
            'media_id'   => (int) $stored->data['id'],
            'tag'        => $tag,
            'caption'    => $caption,
            'day'        => gmdate('Y-m-d'),
            'created_at' => gmdate('c'),
        ]);

        $this->announce($team, $userId, $tag, $id);
        $this->kernel->events->emit('feed.posted', ['user_id' => $userId, 'team_id' => (int) $team['id'], 'post_id' => $id, 'tag' => $tag]);

        $row = $this->kernel->db()->first('SELECT * FROM feed_posts WHERE id = ?', [$id]);
        return Result::ok(['post' => $this->view($row, $userId, array_column($team['members'], 'name', 'user_id'))]);
    }

    /** Сообщение в чат сквада — не чаще раза в два часа, чтобы не стать шумом. */
    private function announce(array $team, int $userId, string $tag, int $postId): void
    {
        $last = $this->kernel->db()->value('SELECT MAX(announced_at) FROM feed_posts WHERE team_id = ?', [(int) $team['id']]);
        if ($last !== null && strtotime((string) $last) > time() - self::ANNOUNCE_GAP) {
            return;
        }
        $name = array_column($team['members'], 'name', 'user_id')[$userId] ?? '';
        $lang = (string) $team['lang'];
        $text = $this->kernel->i18n->t('feed.announce', [
            'name' => $name,
            'what' => $this->kernel->i18n->t('feed.tag.' . $tag, [], $lang),
        ], $lang);
        if ($this->team()->announce((int) $team['id'], $text)) {
            $this->kernel->db()->update('feed_posts', ['announced_at' => gmdate('c')], 'id = :id', ['id' => $postId]);
        }
    }

    private function postsToday(int $userId): int
    {
        return (int) $this->kernel->db()->value('SELECT COUNT(*) FROM feed_posts WHERE user_id = ? AND day = ?', [$userId, gmdate('Y-m-d')], 0);
    }

    // ================= поддержка, жалобы, удаление =================

    /** Пост, который этот человек сейчас вправе видеть. */
    private function visiblePost(int $viewerId, int $postId): ?array
    {
        $post = $this->kernel->db()->first("SELECT * FROM feed_posts WHERE id = ? AND status <> 'deleted'", [$postId]);
        if ($post === null) {
            return null;
        }
        $mine = (int) $post['user_id'] === $viewerId;
        if ($post['status'] === 'hidden' && !$mine) {
            return null;
        }
        $team = $this->team();
        if (!$team->isMember((int) $post['team_id'], $viewerId) || !$team->isMember((int) $post['team_id'], (int) $post['user_id'])) {
            return null;
        }
        return $post;
    }

    public function support(int $userId, int $postId): Result
    {
        $post = $this->visiblePost($userId, $postId);
        if ($post === null) {
            return Result::fail('not_found');
        }
        if ((int) $post['user_id'] === $userId) {
            return Result::fail('own_post');
        }
        $had = $this->kernel->db()->value('SELECT 1 FROM feed_support WHERE post_id = ? AND user_id = ?', [$postId, $userId]) !== null;
        if ($had) {
            $this->kernel->db()->run('DELETE FROM feed_support WHERE post_id = ? AND user_id = ?', [$postId, $userId]);
        } else {
            $this->kernel->db()->insert('feed_support', ['post_id' => $postId, 'user_id' => $userId, 'created_at' => gmdate('c')]);
            $this->kernel->events->emit('feed.supported', ['post_id' => $postId, 'user_id' => $userId, 'author_id' => (int) $post['user_id'], 'team_id' => (int) $post['team_id']]);
        }
        return Result::ok(['supported' => !$had]);
    }

    public function report(int $userId, int $postId, string $reason): Result
    {
        if (!in_array($reason, self::REASONS, true)) {
            return Result::fail('bad_reason');
        }
        $post = $this->visiblePost($userId, $postId);
        if ($post === null) {
            return Result::fail('not_found');
        }
        if ((int) $post['user_id'] === $userId) {
            return Result::fail('own_post');
        }
        $this->kernel->db()->run(
            'INSERT OR IGNORE INTO feed_reports (post_id, user_id, reason, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $reason, gmdate('c')]
        );

        $open = (int) $this->kernel->db()->value('SELECT COUNT(*) FROM feed_reports WHERE post_id = ? AND resolved_at IS NULL', [$postId], 0);
        $hide = in_array($reason, self::HIDE_NOW, true) || $open >= (int) $this->kernel->config->get('feed.hide_after_reports', 2);
        if ($hide && $post['status'] === 'visible') {
            $this->kernel->db()->update('feed_posts', [
                'status' => 'hidden', 'hidden_reason' => in_array($reason, self::HIDE_NOW, true) ? $reason : 'reports',
            ], 'id = :id', ['id' => $postId]);
        }
        $this->kernel->events->emit('feed.reported', ['post_id' => $postId, 'user_id' => $userId, 'reason' => $reason, 'hidden' => $hide]);
        return Result::ok(['hidden' => $hide]);
    }

    public function delete(int $userId, int $postId): Result
    {
        $post = $this->kernel->db()->first("SELECT * FROM feed_posts WHERE id = ? AND user_id = ? AND status <> 'deleted'", [$postId, $userId]);
        if ($post === null) {
            return Result::fail('not_found');
        }
        $this->remove($post, 'owner');
        return Result::ok();
    }

    private function remove(array $post, string $by): void
    {
        if ($post['media_id'] !== null) {
            $this->media()->delete((int) $post['media_id'], $by);
        }
        $this->kernel->db()->update('feed_posts', [
            'status' => 'deleted', 'deleted_at' => gmdate('c'), 'deleted_by' => $by,
        ], 'id = :id', ['id' => (int) $post['id']]);
    }

    // ================= модератор =================

    /**
     * Очередь жалоб. Модератор видит фото только тех постов, на которые
     * пожаловались, — ленты сквадов целиком ему не открываются.
     */
    public function reports(int $moderatorId): array
    {
        $out = [];
        foreach ($this->kernel->db()->all(
            "SELECT p.*, COUNT(r.user_id) AS n, GROUP_CONCAT(r.reason) AS reasons, MIN(r.created_at) AS first_at
             FROM feed_reports r JOIN feed_posts p ON p.id = r.post_id
             WHERE r.resolved_at IS NULL AND p.status <> 'deleted'
             GROUP BY p.id ORDER BY first_at LIMIT 100"
        ) as $p) {
            $u = $this->kernel->container->get(\App\Contracts\Auth::class)->userById((int) $p['user_id']);
            $out[] = [
                'id'       => (int) $p['id'],
                'author'   => trim((string) ($u['name'] ?? '')) ?: '#' . $p['user_id'],
                'author_id'=> (int) $p['user_id'],
                'team_id'  => (int) $p['team_id'],
                'tag'      => $p['tag'],
                'caption'  => $p['caption'],
                'status'   => $p['status'],
                'reports'  => (int) $p['n'],
                'reasons'  => array_count_values(explode(',', (string) $p['reasons'])),
                'first_at' => $p['first_at'],
                'full'     => $p['media_id'] !== null ? $this->media()->url((int) $p['media_id'], $moderatorId, 'full', 900) : null,
            ];
        }
        return $out;
    }

    public function resolve(int $postId, string $action, int $moderatorId): Result
    {
        $post = $this->kernel->db()->first("SELECT * FROM feed_posts WHERE id = ? AND status <> 'deleted'", [$postId]);
        if ($post === null) {
            return Result::fail('not_found');
        }
        if ($action === 'restore') {
            $this->kernel->db()->update('feed_posts', ['status' => 'visible', 'hidden_reason' => null], 'id = :id', ['id' => $postId]);
            $resolution = 'kept';
        } elseif ($action === 'remove') {
            $this->remove($post, 'moderator');
            $resolution = 'removed';
        } else {
            return Result::fail('bad_action');
        }
        $this->kernel->db()->run(
            'UPDATE feed_reports SET resolved_at = ?, resolution = ? WHERE post_id = ? AND resolved_at IS NULL',
            [gmdate('c'), $resolution, $postId]
        );
        $this->kernel->events->emit('admin.action', [
            'actor_id'  => $moderatorId,
            'module'    => 'feed',
            'action'    => $action === 'remove' ? 'post_removed' : 'post_restored',
            'target_id' => $postId,
            'meta'      => ['author_id' => (int) $post['user_id']],
        ]);
        return Result::ok(['status' => $action === 'remove' ? 'deleted' : 'visible']);
    }

    // ================= метрики =================

    /** Доля постов за 14 дней, которые поддержал хоть кто-то из сквада. */
    public function supportedShare(string $today): array
    {
        $from  = gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - 14 * 86400);
        $total = (int) $this->kernel->db()->value("SELECT COUNT(*) FROM feed_posts WHERE day >= ? AND status <> 'deleted'", [$from], 0);
        $supp  = (int) $this->kernel->db()->value(
            "SELECT COUNT(DISTINCT p.id) FROM feed_posts p JOIN feed_support s ON s.post_id = p.id WHERE p.day >= ? AND p.status <> 'deleted'",
            [$from], 0
        );
        return ['value' => $total > 0 ? 100.0 * $supp / $total : null, 'n' => $total];
    }

    public function openReports(): int
    {
        return (int) $this->kernel->db()->value('SELECT COUNT(DISTINCT post_id) FROM feed_reports WHERE resolved_at IS NULL', [], 0);
    }

    /** Удаление аккаунта: фото с диска через хранилище, до стирания строк. */
    public function eraseMedia(int $userId): void
    {
        foreach ($this->kernel->db()->all('SELECT media_id FROM feed_posts WHERE user_id = ? AND media_id IS NOT NULL', [$userId]) as $row) {
            $this->media()->delete((int) $row['media_id'], 'erased');
        }
    }
}
