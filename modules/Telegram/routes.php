<?php
declare(strict_types=1);

use App\Contracts;
use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Identity\Domain\Users;
use Modules\Telegram\Domain\BotApi;
use Modules\Telegram\Domain\InitData;

return static function (Router $router, Kernel $kernel): void {

    /**
     * Вход из Mini App. Клиент присылает window.Telegram.WebApp.initData
     * как есть — сервер проверяет подпись и сам решает, кто это.
     */
    $router->post('/api/auth/telegram', static function (Request $r, Kernel $k): Response {
        /** @var InitData $verifier */
        $verifier = $k->container->get(InitData::class);
        $check    = $verifier->verify($r->str('init_data'));

        if (!$check['ok']) {
            return Response::json([
                'error'   => $check['error'],
                'message' => $k->i18n->t('telegram.' . $check['error']),
            ], $check['error'] === 'bot_not_configured' ? 503 : 401);
        }

        /** @var Users $users */
        $users  = $k->container->get(Users::class);
        $result = $users->upsertFromTelegram($check['user']);

        if (!$result->ok) {
            return Response::json([
                'error'   => $result->error,
                'message' => $k->i18n->t('identity.' . $result->error),
            ], 422);
        }

        $session = $k->container->get(Contracts\Auth::class)->issueSession((int) $result->data['id']);

        return Response::json([
            'user'    => $users->publicView($result->data),
            'token'   => $session['token'] ?? null,
            'created' => (bool) ($result->meta['created'] ?? false),
        ])->withCookie('l180_session', (string) ($session['token'] ?? ''));
    });

    /** Привязка Telegram к аккаунту, созданному по номеру. */
    $router->post('/api/me/telegram', static function (Request $r, Kernel $k): Response {
        $check = $k->container->get(InitData::class)->verify($r->str('init_data'));
        if (!$check['ok']) {
            return Response::json(['error' => $check['error']], 401);
        }

        /** @var Users $users */
        $users  = $k->container->get(Users::class);
        $result = $users->linkTelegram((int) $r->userId(), $check['user']);

        return $result->ok
            ? Response::json(['user' => $users->publicView($result->data)])
            : Response::json([
                'error'   => $result->error,
                'message' => $k->i18n->t('identity.' . $result->error),
            ], 409);
    }, ['auth' => true]);

    /**
     * Вебхук бота. Пока обрабатывает только /start — присылает кнопку
     * запуска Mini App. Всё остальное игнорируется молча.
     */
    $router->post('/api/telegram/webhook', static function (Request $r, Kernel $k): Response {
        $expected = (string) $k->config->get('telegram.webhook_secret', '');
        if ($expected === '' || !hash_equals($expected, (string) $r->header('x-telegram-bot-api-secret-token', ''))) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $message = $r->input('message');
        $chatId  = (string) ($message['chat']['id'] ?? '');
        $text    = trim((string) ($message['text'] ?? ''));

        if ($chatId !== '' && str_starts_with($text, '/start')) {
            $url = rtrim((string) $k->config->get('app.url', ''), '/');
            $k->container->get(BotApi::class)->sendMessage(
                $chatId,
                $k->i18n->t('telegram.start_message'),
                ['reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => $k->i18n->t('telegram.open_app'), 'web_app' => ['url' => $url]],
                    ]],
                ])]
            );
        }

        // Telegram ждёт 200 на любой апдейт, иначе будет повторять доставку.
        return Response::json(['ok' => true]);
    });
};
