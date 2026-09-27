<?php
declare(strict_types=1);

use App\Contracts;
use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Identity\Domain\Codes;
use Modules\Identity\Domain\Users;

return static function (Router $router, Kernel $kernel): void {

    /** Ответ об ошибке с переведённым сообщением. */
    $failure = static function (Kernel $k, App\Result $result, int $status = 422): Response {
        return Response::json([
            'error'   => $result->error,
            'message' => $k->i18n->t('identity.' . $result->error, $result->meta),
        ] + $result->meta, $status);
    };

    // --- Коды подтверждения: регистрация по номеру и сброс пароля ---

    $router->post('/api/auth/code/request', static function (Request $r, Kernel $k) use ($failure): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);
        /** @var Codes $codes */
        $codes = $k->container->get(Codes::class);

        $phone = $users->normalizePhone($r->str('phone'));
        if ($phone === null) {
            return $failure($k, App\Result::fail('bad_phone'));
        }

        $purpose = $r->str('purpose', 'signup') === 'reset' ? 'reset' : 'signup';
        $exists  = $users->findByPhone($phone) !== null;

        if ($purpose === 'signup' && $exists) {
            return $failure($k, App\Result::fail('phone_taken'));
        }
        if ($purpose === 'reset' && !$exists) {
            // Не подтверждаем, зарегистрирован ли номер: это утечка данных.
            return Response::json(['sent' => true, 'expires_in' => 300]);
        }

        $result = $codes->request($phone, $purpose, $r->str('channel', 'auto'));
        if (!$result->ok) {
            return $failure($k, $result, $result->error === 'code_cooldown' ? 429 : 422);
        }

        return Response::json(['sent' => true] + $result->meta);
    });

    $router->post('/api/auth/code/verify', static function (Request $r, Kernel $k) use ($failure): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);
        /** @var Codes $codes */
        $codes = $k->container->get(Codes::class);

        $phone = $users->normalizePhone($r->str('phone'));
        if ($phone === null) {
            return $failure($k, App\Result::fail('bad_phone'));
        }

        $purpose = $r->str('purpose', 'signup') === 'reset' ? 'reset' : 'signup';
        $result  = $codes->verify($phone, $purpose, $r->str('code'));

        return $result->ok ? Response::json($result->data) : $failure($k, $result);
    });

    // Завершение сброса пароля: нужен талон, выданный после верного кода.
    $router->post('/api/auth/password/reset', static function (Request $r, Kernel $k) use ($failure): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);
        /** @var Codes $codes */
        $codes = $k->container->get(Codes::class);

        $phone = $users->normalizePhone($r->str('phone'));
        if ($phone === null || !$codes->checkTicket($r->str('ticket'), $phone, 'reset')) {
            return $failure($k, App\Result::fail('bad_ticket'), 403);
        }

        $user = $users->findByPhone($phone);
        if ($user === null) {
            return $failure($k, App\Result::fail('bad_ticket'), 403);
        }

        $result = $users->setPassword((int) $user['id'], $r->str('password'));
        if (!$result->ok) {
            return $failure($k, $result);
        }

        $session = $k->container->get(Contracts\Auth::class)->issueSession((int) $user['id']);

        return Response::json([
            'user'  => $users->publicView($result->data),
            'token' => $session['token'] ?? null,
        ])->withCookie('l180_session', (string) ($session['token'] ?? ''));
    });

    // Привязка номера к аккаунту, созданному через Telegram.
    $router->post('/api/me/phone', static function (Request $r, Kernel $k) use ($failure): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);
        /** @var Codes $codes */
        $codes = $k->container->get(Codes::class);

        $phone = $users->normalizePhone($r->str('phone'));
        if ($phone === null || !$codes->checkTicket($r->str('ticket'), $phone, 'signup')) {
            return $failure($k, App\Result::fail('bad_ticket'), 403);
        }

        $result = $users->attachPhone((int) $r->userId(), $phone, $r->str('password'));
        return $result->ok
            ? Response::json(['user' => $users->publicView($result->data)])
            : $failure($k, $result);
    }, ['auth' => true]);

    $router->post('/api/auth/register', static function (Request $r, Kernel $k) use ($failure): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);

        // Когда включена проверка номера, регистрация требует талона,
        // выданного после верного кода из SMS или Telegram.
        if ($k->config->get('auth.verify_phone', false)) {
            /** @var Codes $codes */
            $codes = $k->container->get(Codes::class);
            $phone = $users->normalizePhone($r->str('phone'));
            if ($phone === null || !$codes->checkTicket($r->str('ticket'), $phone, 'signup')) {
                return $failure($k, App\Result::fail('bad_ticket'), 403);
            }
        }

        $result = $users->register(
            $r->str('phone'),
            $r->str('password'),
            $r->str('name'),
            $r->lang()
        );

        if (!$result->ok) {
            return Response::json([
                'error'   => $result->error,
                'message' => $k->i18n->t('identity.' . $result->error, $result->meta),
            ] + $result->meta, 422);
        }

        /** @var Contracts\Auth $auth */
        $auth    = $k->container->get(Contracts\Auth::class);
        $session = $auth->issueSession((int) $result->data['id']);

        return Response::json([
            'user'  => $users->publicView($result->data),
            'token' => $session['token'] ?? null,
        ], 201)->withCookie('l180_session', (string) ($session['token'] ?? ''));
    });

    $router->post('/api/auth/login', static function (Request $r, Kernel $k): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);
        $ip    = $r->ip();

        if ($users->tooManyAttempts($ip)) {
            return Response::json([
                'error'   => 'too_many_attempts',
                'message' => $k->i18n->t('identity.too_many_attempts'),
            ], 429);
        }

        $phone = $users->normalizePhone($r->str('phone'));
        $user  = $phone ? $users->findByPhone($phone) : null;

        if ($user === null || !$users->verifyPassword($user, $r->str('password'))) {
            $users->recordAttempt($ip, $phone, false);
            return Response::json([
                'error'   => 'bad_credentials',
                'message' => $k->i18n->t('identity.bad_credentials'),
            ], 401);
        }

        if ($user['status'] === 'blocked') {
            return Response::json(['error' => 'blocked', 'message' => $k->i18n->t('identity.blocked')], 403);
        }

        $users->recordAttempt($ip, $phone, true);

        /** @var Contracts\Auth $auth */
        $auth    = $k->container->get(Contracts\Auth::class);
        $session = $auth->issueSession((int) $user['id']);

        return Response::json([
            'user'  => $users->publicView($user),
            'token' => $session['token'] ?? null,
        ])->withCookie('l180_session', (string) ($session['token'] ?? ''));
    });

    $router->post('/api/auth/logout', static function (Request $r, Kernel $k): Response {
        $token = $r->header('x-session-token') ?? $r->cookie('l180_session');
        if ($token) {
            $k->container->get(Contracts\Auth::class)->revokeSession($token);
        }
        return Response::json(['done' => true])->withCookie('l180_session', '', 0);
    });

    $router->get('/api/me', static function (Request $r, Kernel $k): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);
        return Response::json(['user' => $users->publicView($r->user())]);
    }, ['auth' => true]);

    $router->post('/api/me/lang', static function (Request $r, Kernel $k): Response {
        /** @var Users $users */
        $users = $k->container->get(Users::class);
        $lang  = $r->str('lang', 'ru');
        $users->setLang((int) $r->userId(), $lang);
        return Response::json(['lang' => $lang])->withCookie('lang', $lang, 365, false);
    }, ['auth' => true]);

    // ---------------- права на данные (Р-19) ----------------

    /**
     * Вся моя история одной кнопкой. Каждый модуль сам кладёт в выгрузку
     * свои таблицы (событие user.export) — ядро не знает, что где лежит.
     *   ?format=json — всё целиком;  ?format=csv — дни: чек-ины и браслет.
     */
    $router->get('/api/me/export', static function (Request $r, Kernel $k): Response {
        $users = $k->container->get(Users::class);
        $data  = $k->events->emit('user.export', [
            'user_id'  => (int) $r->userId(),
            'sections' => ['identity' => ['account' => $users->publicView($r->user()) + ['created_at' => $r->user()['created_at']]]],
        ]);
        $sections = $data['sections'] ?? [];

        if ($r->str('format') === 'csv') {
            $days = [];
            foreach ($sections['checkin']['checkin_days'] ?? [] as $d) {
                $days[$d['date']] = ['date' => $d['date'], 'done' => $d['done'], 'energy' => $d['energy'], 'mood' => $d['mood'], 'skip_reason' => $d['skip_reason']];
            }
            foreach ($sections['wearable']['wear_days'] ?? [] as $w) {
                $days[$w['date']] = ($days[$w['date']] ?? ['date' => $w['date']]) + ['steps' => $w['steps'], 'sleep_min' => $w['sleep_min'], 'rhr' => $w['rhr'], 'active_min' => $w['active_min']];
            }
            ksort($days);
            $cols = ['date', 'done', 'energy', 'mood', 'skip_reason', 'steps', 'sleep_min', 'rhr', 'active_min'];
            $csv  = implode(',', $cols) . "\n";
            foreach ($days as $row) {
                $csv .= implode(',', array_map(static fn($c) => (string) ($row[$c] ?? ''), $cols)) . "\n";
            }
            return Response::text($csv)
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="level180-days.csv"');
        }

        return Response::json(['exported_at' => gmdate('c'), 'sections' => $sections])
            ->withHeader('Content-Disposition', 'attachment; filename="level180-export.json"');
    }, ['auth' => true]);

    /**
     * Удаление аккаунта. Подтверждение словом — от случайного нажатия.
     * Сначала все модули стирают свои данные (user.erase), затем аккаунт.
     */
    $router->post('/api/me/erase', static function (Request $r, Kernel $k): Response {
        $word = mb_strtoupper(trim($r->str('confirm')));
        if (!in_array($word, ['УДАЛИТЬ', 'DELETE', "O'CHIRISH", 'OʻCHIRISH', 'O‘CHIRISH'], true)) {
            return Response::json(['error' => 'confirm_required', 'message' => $k->i18n->t('identity.erase_confirm')], 422);
        }
        $userId = (int) $r->userId();
        $result = $k->events->emit('user.erase', ['user_id' => $userId, 'erased' => []]);
        $k->container->get(Users::class)->erase($userId);
        return Response::json(['ok' => true, 'erased' => $result['erased'] ?? []])->withCookie('l180_session', '', -1);
    }, ['auth' => true]);

    // ---------------- модератор: люди и роли ----------------

    $router->get('/api/admin/users', static function (Request $r, Kernel $k): Response {
        if (!in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, 'users' => $k->container->get(Users::class)->search($r->str('q'))]);
    }, ['auth' => true]);

    /** Роли меняет только админ; блокирует и модератор. Себя понизить или заблокировать нельзя. */
    $router->post('/api/admin/users/{id}/{action}', static function (Request $r, Kernel $k): Response {
        $role   = (string) ($r->user()['role'] ?? '');
        $action = (string) $r->param('action');
        $target = (int) $r->param('id');
        $users  = $k->container->get(Users::class);

        if (!in_array($role, ['admin', 'moderator'], true) || ($action === 'role' && $role !== 'admin')) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        if ($target === (int) $r->userId()) {
            return Response::json(['error' => 'self', 'message' => $k->i18n->t('identity.admin_self')], 422);
        }

        $ok = match ($action) {
            'role'    => $users->setRole($target, $r->str('role')),
            'block'   => $users->setStatus($target, 'blocked'),
            'unblock' => $users->setStatus($target, 'active'),
            default   => null,
        };
        if ($ok === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if ($ok) {
            $k->events->emit('admin.action', [
                'actor_id' => (int) $r->userId(), 'module' => 'identity', 'action' => $action,
                'target_id' => $target, 'meta' => $action === 'role' ? ['role' => $r->str('role')] : [],
            ]);
        }
        return $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'bad_request'], 422);
    }, ['auth' => true]);
};
