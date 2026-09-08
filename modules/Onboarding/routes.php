<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Onboarding\Domain\Profiles;
use Modules\Onboarding\Domain\Questions;
use Modules\Onboarding\Domain\Screening;

return static function (Router $router, Kernel $kernel): void {

    $fail = static fn(Kernel $k, App\Result $r, int $status = 422): Response => Response::json([
        'error'   => $r->error,
        'message' => $k->i18n->t('onboarding.' . $r->error, $r->meta),
    ] + $r->meta, $status);

    /** Структура анкеты — интерфейс рисует её сам, не зашивая вопросы в JS. */
    $router->get('/api/onboarding/questions', static function (Request $r, Kernel $k): Response {
        return Response::json([
            'questions' => Questions::all(),
            'screening' => ['parq' => Screening::PARQ, 'blockers' => Screening::BLOCKERS],
            'zero_cycle_days' => Profiles::ZERO_CYCLE_DAYS,
        ]);
    });

    $router->get('/api/onboarding/state', static function (Request $r, Kernel $k): Response {
        /** @var Profiles $profiles */
        $profiles = $k->container->get(Profiles::class);
        return Response::json($profiles->state((int) $r->userId()));
    }, ['auth' => true]);

    $router->post('/api/onboarding/answers', static function (Request $r, Kernel $k) use ($fail): Response {
        /** @var Profiles $profiles */
        $profiles = $k->container->get(Profiles::class);
        $result   = $profiles->saveAnswers((int) $r->userId(), $r->body());

        return $result->ok ? Response::json(['profile' => $result->data]) : $fail($k, $result);
    }, ['auth' => true]);

    $router->post('/api/onboarding/screening', static function (Request $r, Kernel $k) use ($fail): Response {
        /** @var Profiles $profiles */
        $profiles = $k->container->get(Profiles::class);
        $result   = $profiles->saveScreening((int) $r->userId(), (array) $r->input('answers', []));

        if ($result->ok) {
            return Response::json($result->data);
        }

        // Отказ — не ошибка ввода: отвечаем 200 с понятным объяснением,
        // чтобы интерфейс показал экран, а не сообщение об ошибке.
        if ($result->error === 'not_eligible') {
            $reason = (string) ($result->meta['reason'] ?? '');
            return Response::json([
                'eligible' => false,
                'reason'   => $reason,
                'message'  => $k->i18n->t('onboarding.reject.' . $reason),
                'advice'   => $k->i18n->t('onboarding.reject_advice'),
            ]);
        }

        return $fail($k, $result);
    }, ['auth' => true]);

    /** День нулевого цикла. На срезе 5 сюда же будет писать модуль Wearable. */
    $router->post('/api/onboarding/baseline', static function (Request $r, Kernel $k) use ($fail): Response {
        /** @var Profiles $profiles */
        $profiles = $k->container->get(Profiles::class);
        $result   = $profiles->addBaselineDay((int) $r->userId(), $r->body());

        return $result->ok ? Response::json($result->data) : $fail($k, $result);
    }, ['auth' => true]);

    $router->post('/api/onboarding/complete', static function (Request $r, Kernel $k) use ($fail): Response {
        /** @var Profiles $profiles */
        $profiles = $k->container->get(Profiles::class);

        // force разрешён только при отладке: он пропускает нулевой цикл,
        // и на бою это сломало бы всю логику baseline.
        $force  = $r->bool('force') && (bool) $k->config->get('app.debug', false);
        $result = $profiles->complete((int) $r->userId(), $force);

        return $result->ok ? Response::json($result->data) : $fail($k, $result);
    }, ['auth' => true]);
};
