<?php
declare(strict_types=1);

/**
 * Смоук-тест без фреймворков: поднимает ядро в памяти и гоняет сценарии
 * через маршрутизатор. Запускать после каждого среза.
 *
 *   php tools/smoke.php
 *
 * Отдельно проверяет главное обещание архитектуры: при выключенном модуле
 * приложение отвечает корректно, а не падает.
 */

$root = dirname(__DIR__);

// Изолированная база: боевую не трогаем.
$testDb = $root . '/storage/db/smoke.sqlite';
foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}

/** @var App\Kernel $kernel */
$kernel = require $root . '/app/bootstrap.php';
$kernel->config->set('db.path', $testDb);

$pass = 0;
$fail = 0;

function check(string $title, bool $ok, string $note = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$title}\n";
    } else {
        $fail++;
        echo "  FAIL {$title}" . ($note !== '' ? "  <- {$note}" : '') . "\n";
    }
}

function call(App\Kernel $k, string $method, string $path, array $body = [], array $headers = []): array
{
    $response = $k->handle(App\Request::make($method, $path, $body, [], $headers));
    return ['status' => $response->status, 'json' => $response->decoded()];
}

echo "\nМиграции\n";
$m = (new App\Migrator($kernel))->migrate();
check('применились без ошибок', $m['errors'] === [], implode('; ', $m['errors']));
check('таблица identity_users создана', $kernel->db()->tableExists('identity_users'));

echo "\nМаршруты\n";
check('несуществующий путь отдаёт 404', call($kernel, 'GET', '/api/nope')['status'] === 404);

// 503 — законный ответ health: «сборка поднялась, но есть незакрытые пункты».
// Проверяем, что маршрут отработал и вернул разбираемый отчёт, а не упал.
$health = call($kernel, 'GET', '/health.json');
check('health отвечает отчётом', in_array($health['status'], [200, 503], true) && isset($health['json']['checks']));
check('health видит оба модуля', count($health['json']['modules'] ?? []) >= 2);

echo "\nРегистрация и вход\n";
$r = call($kernel, 'POST', '/api/auth/register', ['phone' => '901234567', 'password' => 'parol12345', 'name' => 'Тест']);
check('регистрация возвращает 201', $r['status'] === 201, json_encode($r['json'], JSON_UNESCAPED_UNICODE));
check('выдан токен сессии', !empty($r['json']['token']));
check('телефон нормализован и замаскирован', str_starts_with((string) ($r['json']['user']['phone'] ?? ''), '+998'));
$token = (string) ($r['json']['token'] ?? '');

$dup = call($kernel, 'POST', '/api/auth/register', ['phone' => '+998 90 123 45 67', 'password' => 'parol12345']);
check('повторная регистрация отклонена', $dup['status'] === 422 && ($dup['json']['error'] ?? '') === 'phone_taken');

$weak = call($kernel, 'POST', '/api/auth/register', ['phone' => '907777777', 'password' => '123']);
check('короткий пароль отклонён', ($weak['json']['error'] ?? '') === 'weak_password');

$badPhone = call($kernel, 'POST', '/api/auth/register', ['phone' => '12345', 'password' => 'parol12345']);
check('некорректный номер отклонён', ($badPhone['json']['error'] ?? '') === 'bad_phone');

$me = call($kernel, 'GET', '/api/me', [], ['x-session-token' => $token]);
check('/api/me с токеном отдаёт пользователя', $me['status'] === 200 && !empty($me['json']['user']['id']));

$anon = call($kernel, 'GET', '/api/me');
check('/api/me без токена отдаёт 401', $anon['status'] === 401);

$login = call($kernel, 'POST', '/api/auth/login', ['phone' => '901234567', 'password' => 'parol12345']);
check('вход по правильному паролю', $login['status'] === 200);

$badLogin = call($kernel, 'POST', '/api/auth/login', ['phone' => '901234567', 'password' => 'wrong']);
check('вход по неверному паролю отклонён', $badLogin['status'] === 401);

echo "\nКоды подтверждения и сброс пароля\n";
$req = call($kernel, 'POST', '/api/auth/code/request', ['phone' => '901234567', 'purpose' => 'reset']);
check('код запрошен', $req['status'] === 200 && !empty($req['json']['sent']));
check('в режиме отладки код виден', !empty($req['json']['debug_code']));
$code = (string) ($req['json']['debug_code'] ?? '');

$again = call($kernel, 'POST', '/api/auth/code/request', ['phone' => '901234567', 'purpose' => 'reset']);
check('повторный запрос отбит паузой', $again['status'] === 429 && ($again['json']['error'] ?? '') === 'code_cooldown');

$wrong = call($kernel, 'POST', '/api/auth/code/verify', ['phone' => '901234567', 'purpose' => 'reset', 'code' => '000000']);
check('неверный код отклонён', ($wrong['json']['error'] ?? '') === 'code_wrong' || $code === '000000');

$ver = call($kernel, 'POST', '/api/auth/code/verify', ['phone' => '901234567', 'purpose' => 'reset', 'code' => $code]);
check('верный код принят, выдан талон', !empty($ver['json']['ticket']));
$ticket = (string) ($ver['json']['ticket'] ?? '');

$badTicket = call($kernel, 'POST', '/api/auth/password/reset', ['phone' => '901234567', 'ticket' => 'подделка', 'password' => 'newparol123']);
check('поддельный талон отклонён', $badTicket['status'] === 403);

$reset = call($kernel, 'POST', '/api/auth/password/reset', ['phone' => '901234567', 'ticket' => $ticket, 'password' => 'newparol123']);
check('пароль сменён, выдана сессия', $reset['status'] === 200 && !empty($reset['json']['token']));

$oldPass = call($kernel, 'POST', '/api/auth/login', ['phone' => '901234567', 'password' => 'parol12345']);
check('старый пароль больше не работает', $oldPass['status'] === 401);

$newPass = call($kernel, 'POST', '/api/auth/login', ['phone' => '901234567', 'password' => 'newparol123']);
check('новый пароль работает', $newPass['status'] === 200);

$oldSession = call($kernel, 'GET', '/api/me', [], ['x-session-token' => $token]);
check('старые сессии закрыты после сброса', $oldSession['status'] === 401);

$noSignup = call($kernel, 'POST', '/api/auth/code/request', ['phone' => '901234567', 'purpose' => 'signup']);
check('код на регистрацию занятого номера не выдаётся', ($noSignup['json']['error'] ?? '') === 'phone_taken');

echo "\nВход через Telegram\n";
$botToken = '123456:TEST-BOT-TOKEN-FOR-SMOKE';
$kernel->config->set('telegram.bot_token', $botToken);

$initData = Modules\Telegram\Domain\InitData::sign([
    'auth_date' => (string) time(),
    'query_id'  => 'AAF_test',
    'user'      => json_encode(['id' => 555777, 'first_name' => 'Aziz', 'username' => 'aziz', 'language_code' => 'uz'], JSON_UNESCAPED_UNICODE),
], $botToken);

$verifier = new Modules\Telegram\Domain\InitData($botToken);
check('подпись initData проверяется', ($verifier->verify($initData))['ok'] === true);
check('подделанная подпись отклоняется', ($verifier->verify($initData . 'x'))['ok'] === false);
check('чужой токен бота не проходит', (new Modules\Telegram\Domain\InitData('999:OTHER'))->verify($initData)['ok'] === false);

$stale = Modules\Telegram\Domain\InitData::sign([
    'auth_date' => (string) (time() - 200000),
    'user'      => json_encode(['id' => 1, 'first_name' => 'Old']),
], $botToken);
check('просроченный initData отклоняется', ($verifier->verify($stale))['error'] === 'stale_init_data');

$tgLogin = call($kernel, 'POST', '/api/auth/telegram', ['init_data' => $initData]);
check('вход через Telegram создаёт аккаунт', $tgLogin['status'] === 200 && !empty($tgLogin['json']['created']));
check('язык взят из профиля Telegram', ($tgLogin['json']['user']['lang'] ?? '') === 'uz');
check('аккаунту нужен номер телефона', !empty($tgLogin['json']['user']['needs_phone']));
$tgToken = (string) ($tgLogin['json']['token'] ?? '');

$tgAgain = call($kernel, 'POST', '/api/auth/telegram', ['init_data' => $initData]);
check('повторный вход не плодит аккаунты', $tgAgain['status'] === 200 && empty($tgAgain['json']['created']));

$tgMe = call($kernel, 'GET', '/api/me', [], ['x-session-token' => $tgToken]);
check('сессия Telegram работает', $tgMe['status'] === 200);
check('вход по паролю в такой аккаунт невозможен', call($kernel, 'POST', '/api/auth/login', ['phone' => 'tg:555777', 'password' => ''])['status'] === 401);

echo "\nПривязка номера к аккаунту Telegram\n";
$req2 = call($kernel, 'POST', '/api/auth/code/request', ['phone' => '935554433', 'purpose' => 'signup']);
$code2 = (string) ($req2['json']['debug_code'] ?? '');
$ver2 = call($kernel, 'POST', '/api/auth/code/verify', ['phone' => '935554433', 'purpose' => 'signup', 'code' => $code2]);
$attach = call($kernel, 'POST', '/api/me/phone', [
    'phone' => '935554433', 'ticket' => (string) ($ver2['json']['ticket'] ?? ''), 'password' => 'parol12345',
], ['x-session-token' => $tgToken]);
check('номер привязан', $attach['status'] === 200 && empty($attach['json']['user']['needs_phone']));
check('теперь работает вход по паролю', call($kernel, 'POST', '/api/auth/login', ['phone' => '935554433', 'password' => 'parol12345'])['status'] === 200);

echo "\nВеб-интерфейс\n";
$shell = $kernel->handle(App\Request::make('GET', '/'));
check('главная отдаёт оболочку', $shell->status === 200 && str_contains($shell->body, 'app.js'));
check('в оболочке подключён Telegram SDK', str_contains($shell->body, 'telegram-web-app.js'));
$i18n = call($kernel, 'GET', '/api/i18n', [], []);
check('строки интерфейса отдаются', !empty($i18n['json']['strings']['ui.enter']));

echo "\nОнбординг: анкета и скрининг\n";

// Полные имена вместо use: смоук-тест — один линейный скрипт,
// и импорты посреди файла читаются хуже, чем явные ссылки.
class_alias(Modules\Onboarding\Domain\Screening::class, 'Screening');
class_alias(Modules\Onboarding\Domain\Tier::class,      'PlanTier');
class_alias(Modules\Planning\Domain\Calculator::class,  'Calculator');
class_alias(Modules\Planning\Domain\Library::class,     'Library');

$H = ['x-session-token' => $tgToken];   // аккаунт, созданный через Telegram

$answers = [
    'goal_dir' => 'lose', 'sex' => 'male', 'birth_year' => 1994,
    'height_cm' => 178, 'weight_kg' => 92.0, 'time_budget' => 30,
    'window' => 'evening', 'social' => 2, 'experience' => 'lost_motivation',
];
$onb = call($kernel, 'POST', '/api/onboarding/answers', $answers, $H);
check('анкета принята', $onb['status'] === 200 && ($onb['json']['profile']['bmi'] ?? 0) > 0);
check('ИМТ посчитан верно', abs(($onb['json']['profile']['bmi'] ?? 0) - 29.0) < 0.2, (string) ($onb['json']['profile']['bmi'] ?? '?'));

$bad = call($kernel, 'POST', '/api/onboarding/answers', ['goal_dir' => 'fly'] + $answers, $H);
check('недопустимый вариант ответа отклонён', ($bad['json']['error'] ?? '') === 'invalid_answers');

$scr = call($kernel, 'POST', '/api/onboarding/screening', ['answers' => []], $H);
check('скрининг пройден', ($scr['json']['needs_doctor'] ?? true) === false);

echo "\nГраницы допуска (решение Р-12)\n";
$cases = [
    ['под 18 лет',            ['birth_year' => (int) gmdate('Y') - 16], [], 'under_18'],
    ['беременность',          [], ['pregnant' => 1], 'pregnancy'],
    ['расстройство питания',  [], ['eating_disorder' => 1], 'eating_disorder'],
    ['ИМТ ниже 18,5',         ['weight_kg' => 52.0, 'height_cm' => 178], [], 'bmi_too_low'],
    ['ИМТ выше 40',           ['weight_kg' => 135.0, 'height_cm' => 178], [], 'bmi_too_high'],
    ['снижать нечего',        ['weight_kg' => 66.0, 'height_cm' => 178], [], 'no_need_to_lose'],
];
foreach ($cases as [$label, $override, $flags, $expected]) {
    $verdict = Screening::evaluate(array_merge($answers, $override), $flags);
    check('отказ: ' . $label, $verdict['reject'] === $expected, 'получено ' . var_export($verdict['reject'], true));
}
$doc = Screening::evaluate($answers, ['chest_pain' => 1]);
check('боль в груди -> направление к врачу, но не отказ', $doc['ok'] === true && $doc['needs_doctor'] === true);

echo "\nНулевой цикл и ступень\n";
check('T0 по низкой активности', PlanTier::classify(3200, 0) === 'T0');
check('T2 по тренировкам, а не шагам', PlanTier::classify(3200, 3) === 'T2');
check('ступень не понижается тренировками', PlanTier::classify(11500, 0) === 'T3');
check('медиана устойчива к выбросу', PlanTier::median([3000, 3200, 3100, 30000, 2900, 3050, 3300]) < 4000);

$early = call($kernel, 'POST', '/api/onboarding/complete', [], $H);
check('без нулевого цикла план не строится', ($early['json']['error'] ?? '') === 'no_baseline');

for ($d = 1; $d <= 7; $d++) {
    call($kernel, 'POST', '/api/onboarding/baseline', ['steps' => 3000 + $d * 60, 'workouts' => 0, 'sleep_min' => 400], $H);
}
$state = call($kernel, 'GET', '/api/onboarding/state', [], $H);
check('нулевой цикл собран за 7 дней', ($state['json']['days_left'] ?? 9) === 0);

$done = call($kernel, 'POST', '/api/onboarding/complete', [], $H);
check('онбординг завершён, ступень определена', ($done['json']['tier'] ?? '') === 'T0', (string) ($done['json']['tier'] ?? '?'));

echo "\nПлан на 180 дней\n";
$plan = call($kernel, 'GET', '/api/plan', [], $H);
check('план построен автоматически по событию', $plan['status'] === 200 && !empty($plan['json']['plan']));
$p = $plan['json']['plan'] ?? [];
check('шесть глав', count($p['chapters'] ?? []) === 6);
check('26 недель', count($p['weeks'] ?? []) === 26);
check('главы названы на языке пользователя', !empty($p['chapters'][0]['title']));

$today = call($kernel, 'GET', '/api/plan/today', [], $H);
check('есть действие на сегодня', !empty($today['json']['today']['action']['title']));
check('день 1 в первой главе', ($today['json']['today']['day'] ?? 0) === 1 && ($today['json']['today']['chapter'] ?? 0) === 1);
check('норма недели — 4 дня из 7', ($today['json']['today']['norm']['days'] ?? 0) === 4);

$d100 = call($kernel, 'GET', '/api/plan/today?date=' . gmdate('Y-m-d', time() + 99 * 86400), [], $H);
check('на 100-й день четвёртая глава', ($d100['json']['today']['chapter'] ?? 0) === 4, 'глава ' . ($d100['json']['today']['chapter'] ?? '?'));

$after = call($kernel, 'GET', '/api/plan/today?date=' . gmdate('Y-m-d', time() + 200 * 86400), [], $H);
check('после 180 дня сезон закрыт', !empty($after['json']['today']['finished']));

echo "\nБезопасность расчёта (решения Р-11, Р-12, Р-14)\n";
$calc = Calculator::build(
    ['goal_dir' => 'lose', 'tier' => 'T0', 'weight_kg' => 92.0, 'height_cm' => 178, 'time_budget' => 30, 'target_kg' => 40.0],
    ['steps_med' => 3200, 'workouts' => 0]
);
$maxRate = max(array_column($calc['weeks'], 'rate_pct'));
check('темп не превышает 1% в неделю', $maxRate <= Calculator::MAX_LOSS_RATE, 'максимум ' . $maxRate);
check('нереальная цель не пробивает безопасный порог', $calc['meta']['weight_final'] >= $calc['meta']['safe_floor_kg']);
check('порог соответствует ИМТ 21', abs($calc['meta']['safe_floor_kg'] - round(21.0 * 1.78 ** 2, 1)) < 0.2);

$deloadWeeks = array_values(array_filter($calc['weeks'], static fn($w) => $w['deload']));
check('разгрузка на каждой 4-й неделе', count($deloadWeeks) === 6 && $deloadWeeks[0]['n'] === 4);

$w7 = $calc['weeks'][7]; $w8 = $calc['weeks'][8]; $w9 = $calc['weeks'][9];
check('разгрузка снижает объём недели', $w8['minutes'] < $w7['minutes']);
check('но не срезает прогрессию дальше', $w9['minutes'] >= $w7['minutes'], "нед7={$w7['minutes']} нед8={$w8['minutes']} нед9={$w9['minutes']}");

check('шаги не выше потолка', max(array_column($calc['weeks'], 'steps_target')) <= Calculator::MAX_STEPS);
check('минуты не выше нормы ВОЗ', max(array_column($calc['weeks'], 'minutes')) <= Calculator::WHO_MAX_MINUTES);
check('минуты не выше заявленного бюджета', max(array_column($calc['weeks'], 'minutes')) <= $calc['meta']['minutes_cap']);

$loseTargets = [
    'день 30'  => [$calc['weeks'][4]['weight_target'], -3.0, -1.5],
    'день 90'  => [$calc['weeks'][13]['weight_target'], -7.0, -5.0],
    'день 180' => [$calc['meta']['weight_final'], -12.0, -8.0],
];
foreach ($loseTargets as $label => [$value, $lo, $hi]) {
    $pct = ($value - 92.0) / 92.0 * 100;
    check("{$label} в коридоре досье", $pct >= $lo && $pct <= $hi, sprintf('%+.1f%%', $pct));
}

$gain = Calculator::build(
    ['goal_dir' => 'gain', 'tier' => 'T1', 'weight_kg' => 62.0, 'height_cm' => 180, 'time_budget' => 45],
    ['steps_med' => 5400, 'workouts' => 1]
);
check('набор массы идёт вверх', $gain['meta']['change_kg'] > 0);
check('при наборе шаги не разгоняются до потолка', $gain['meta']['steps_final'] <= 8000);

$ch5 = array_values(array_filter($calc['weeks'], static fn($w) => $w['chapter'] === 5));
check('глава «Испытание» — удержание, вес не снижается', $ch5[0]['weight_target'] === end($ch5)['weight_target']);

echo "\nБиблиотека действий\n";
$missing = [];
foreach (Library::allKeys() as $key) {
    foreach (['ru', 'uz'] as $lng) {
        if ($kernel->i18n->t('action.' . $key, [], $lng) === 'action.' . $key) {
            $missing[] = $lng . ':' . $key;
        }
        if ($kernel->i18n->t('action.' . $key . '.hint', [], $lng) === 'action.' . $key . '.hint') {
            $missing[] = $lng . ':' . $key . '.hint';
        }
    }
}
check('у всех действий есть текст на двух языках', $missing === [], implode(', ', array_slice($missing, 0, 6)));

$sameDay = Library::actionFor(42, $calc['weeks'][6], $calc['meta'], false);
$again   = Library::actionFor(42, $calc['weeks'][6], $calc['meta'], false);
check('выбор действия детерминирован', $sameDay['key'] === $again['key']);

$limited = Library::actionFor(65, $calc['weeks'][10], $calc['meta'], true);
check('при ограничениях не даём ударную нагрузку', !in_array($limited['key'], ['strength_session', 'cardio_session'], true));

echo "\nПереводы\n";
check('русские строки загружены', $kernel->i18n->t('identity.phone_taken') !== 'identity.phone_taken');
check('узбекские строки загружены', $kernel->i18n->t('identity.phone_taken', [], 'uz') !== 'identity.phone_taken');
check('нет непереведённых ключей', $kernel->i18n->untranslated() === [], implode(', ', $kernel->i18n->untranslated()));

echo "\nСобытия\n";
check('нет ошибок в слушателях', $kernel->events->errors() === [], json_encode($kernel->events->errors(), JSON_UNESCAPED_UNICODE));

echo "\nГлавное обещание: выключение модуля не роняет приложение\n";
// Собираем второе ядро, где identity выключен.
$backup = $root . '/modules.php.bak';
copy($root . '/modules.php', $backup);
file_put_contents($root . '/modules.php', "<?php\nreturn ['health'];\n");

$code = <<<'PHP'
$k = require dirname(__DIR__) . '/app/bootstrap.php';

// Маршрут выключенного модуля должен исчезнуть целиком, а не отвечать ошибкой.
$goneStatus = $k->handle(App\Request::make('GET', '/api/me'))->status;

// А маршрут оставшегося модуля, требующий авторизации, обязан вежливо
// ответить 401 через заглушку, а не упасть с 500.
$k->router->get('/api/_guarded', fn() => App\Response::json(['secret' => true]), ['auth' => true]);
$guarded = $k->handle(App\Request::make('GET', '/api/_guarded'));

$health = $k->handle(App\Request::make('GET', '/health.json'));

echo json_encode([
    'auth_class'      => $k->container->get(App\Contracts\Auth::class)::class,
    'coach_reply'     => $k->container->get(App\Contracts\Coach::class)->replyToCheckin(['done' => true])['source'],
    'gone_status'     => $goneStatus,
    'guarded_status'  => $guarded->status,
    'health_status'   => $health->status,
    'health_parsable' => isset($health->decoded()['checks']),
], JSON_UNESCAPED_UNICODE);
PHP;
file_put_contents($root . '/tools/_off.php', "<?php\n" . $code . "\n");
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/_off.php') . ' 2>&1');
@unlink($root . '/tools/_off.php');
copy($backup, $root . '/modules.php');
@unlink($backup);

$off = json_decode((string) $out, true);
check('приложение поднимается без identity', is_array($off), trim((string) $out));
if (is_array($off)) {
    check('Auth падает на заглушку', ($off['auth_class'] ?? '') === 'App\Contracts\NullAuth', (string) ($off['auth_class'] ?? ''));
    check('маршруты модуля исчезли (404, не 500)', ($off['gone_status'] ?? 0) === 404, 'получено ' . ($off['gone_status'] ?? '?'));
    check('защищённый маршрут отвечает 401, а не 500', ($off['guarded_status'] ?? 0) === 401, 'получено ' . ($off['guarded_status'] ?? '?'));
    check('Coach работает на шаблонах', ($off['coach_reply'] ?? '') === 'template');
    check('health продолжает отвечать отчётом', !empty($off['health_parsable']) && in_array($off['health_status'] ?? 0, [200, 503], true));
}

echo "\n" . str_repeat('-', 46) . "\n";
echo $fail === 0 ? "ВСЁ ПРОШЛО: {$pass} проверок\n" : "ПРОВАЛЕНО: {$fail}, прошло: {$pass}\n";
exit($fail === 0 ? 0 : 1);
