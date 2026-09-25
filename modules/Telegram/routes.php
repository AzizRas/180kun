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
     * Вебхук бота. В личке — /start с кнопкой запуска Mini App. В группах —
     * передаёт сообщения модулям событием telegram.group_message.
     * Чтобы бот видел все сообщения группы, он должен быть там админом.
     */
    $router->post('/api/telegram/webhook', static function (Request $r, Kernel $k): Response {
        $expected = (string) $k->config->get('telegram.webhook_secret', '');
        if ($expected === '' || !hash_equals($expected, (string) $r->header('x-telegram-bot-api-secret-token', ''))) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $message  = $r->input('message');
        $chatId   = (string) ($message['chat']['id'] ?? '');
        $chatType = (string) ($message['chat']['type'] ?? 'private');
        $text     = trim((string) ($message['text'] ?? ''));

        // Сообщение в группе. Кто-то из модулей (сейчас — сквады) может
        // вести по ним активность и ответить. Мы только узнаём человека
        // и доставляем ответ; текст никуда не сохраняется.
        if ($chatId !== '' && in_array($chatType, ['group', 'supergroup'], true)) {
            if (empty($message['from']['is_bot'])) {
                $tgId = (string) ($message['from']['id'] ?? '');
                $user = $tgId !== '' ? $k->container->get(Users::class)->findByTgId($tgId) : null;

                $result = $k->events->emit('telegram.group_message', [
                    'chat_id' => $chatId,
                    'user_id' => $user !== null ? (int) $user['id'] : null,
                    'text'    => $text,
                    'at'      => gmdate('c', (int) ($message['date'] ?? time())),
                    'reply'   => null,
                ]);
                if (!empty($result['reply'])) {
                    $k->container->get(BotApi::class)->sendMessage($chatId, (string) $result['reply']);
                }
            }
            return Response::json(['ok' => true]);
        }

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
