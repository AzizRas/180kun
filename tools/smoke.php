<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';   // только из командной строки, никогда из браузера

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

// Изолированные база и папка данных: боевые не трогаем. Задаются через
// окружение, как на Railway, — поэтому автомиграции при загрузке ядра
// проверяются здесь тем же путём, каким они сработают после деплоя.
$testData = $root . '/storage/smoke-data';
$testDb   = $testData . '/db/smoke.sqlite';
foreach ([$testDb, $testDb . '-wal', $testDb . '-shm', $testData . '/.migrations', $testData . '/secret.key'] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
putenv('DATA_DIR=' . $testData);
putenv('DATABASE_PATH=' . $testDb);

/** @var App\Kernel $kernel */
$kernel = require $root . '/app/bootstrap.php';

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
// Ядро уже применило их само при загрузке — как на свежем деплое.
check('автомиграции создали таблицы до первого запроса', $kernel->db()->tableExists('identity_users'));
check('отпечаток миграций записан в папку данных', is_file($testData . '/.migrations'));
check('секретный ключ живёт в папке данных', $kernel->secret() !== '' && is_file($testData . '/secret.key'));
$m = (new App\Migrator($kernel))->migrate();
check('повторный запуск ничего не применяет', $m['errors'] === [] && $m['applied'] === [], implode('; ', $m['errors']));

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

echo "\nДиагностика закрыта от посторонних\n";
$kernel->config->set('app.debug', false);
$kernel->config->set('admin.health_key', 'k-' . bin2hex(random_bytes(4)));
$anon = call($kernel, 'GET', '/health.json');
check('аноним видит только статус', isset($anon['json']['ok']) && !isset($anon['json']['checks']) && !isset($anon['json']['routes']));
$anonHtml = $kernel->handle(App\Request::make('GET', '/health'))->body;
check('HTML-версия для анонима без путей и маршрутов', !str_contains($anonHtml, '/api/') && !str_contains($anonHtml, $root));
$byKey = call($kernel, 'GET', '/health.json?key=' . $kernel->config->get('admin.health_key'));
check('ключ HEALTH_KEY открывает полный отчёт', isset($byKey['json']['checks']));
check('неверный ключ не открывает', !isset(call($kernel, 'GET', '/health.json?key=wrong')['json']['checks']));
$asAdmin = call($kernel, 'GET', '/health.json', [], ['x-session-token' => $token]);
check('первый пользователь — админ и видит отчёт', isset($asAdmin['json']['checks']));
$kernel->config->set('app.debug', true);

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

echo "\nЕжедневный чек-ин\n";
class_alias(Modules\Checkin\Domain\Recovery::class, 'Recovery');
class_alias(Modules\Gamification\Domain\Ledger::class, 'Ledger');

$today = gmdate('Y-m-d');
$ago = static fn(int $d): string => gmdate('Y-m-d', time() - $d * 86400);

$c1 = call($kernel, 'GET', '/api/checkin/today', [], $H);
check('экран чек-ина отдаёт действие из плана', !empty($c1['json']['plan']['action']['title']));
check('день ещё не отмечен', ($c1['json']['recorded'] ?? true) === false);
check('норма недели пришла', ($c1['json']['week']['norm_days'] ?? 0) === 4);
check('щитов в месяце — два', ($c1['json']['shields'] ?? 0) === 2);

$rec = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 4, 'mood' => 4], $H);
check('чек-ин записан', $rec['status'] === 200 && ($rec['json']['week']['done_days'] ?? 0) === 1);

$again = call($kernel, 'POST', '/api/checkin', ['done' => 'partial', 'energy' => 3, 'mood' => 3], $H);
check('повторная отметка правит день, а не дублирует', ($again['json']['week']['checkins'] ?? 0) === 1);

$noReason = call($kernel, 'POST', '/api/checkin', ['done' => 'no'], $H);
check('без причины «не вышло» не принимается', ($noReason['json']['error'] ?? '') === 'reason_required');

$future = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'date' => gmdate('Y-m-d', time() + 86400)], $H);
check('будущий день отклонён', ($future['json']['error'] ?? '') === 'in_future');

$old = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'date' => $ago(5)], $H);
check('задним числом дальше вчера нельзя', ($old['json']['error'] ?? '') === 'too_old');

echo "\nОчки (решение Р-15)\n";
$prog = call($kernel, 'GET', '/api/me/progress', [], $H);
$xp1 = (int) ($prog['json']['progress']['xp'] ?? 0);
check('очки начислены за чек-ин и действие', $xp1 >= 10 + Ledger::RATES['action_half'], 'xp=' . $xp1);
check('щиты пришли из модуля Checkin через событие', ($prog['json']['progress']['shields'] ?? 0) === 2);

$before = $xp1;
call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 5, 'mood' => 5], $H);
$prog2 = call($kernel, 'GET', '/api/me/progress', [], $H);
check('повторный чек-ин того же дня очков не добавляет',
    (int) ($prog2['json']['progress']['xp'] ?? 0) === $before, 'было ' . $before . ', стало ' . ($prog2['json']['progress']['xp'] ?? '?'));

check('уровень считается от очков', Ledger::levelFor(0) === 1 && Ledger::levelFor(40) === 2 && Ledger::levelFor(100000) === 180);
check('возврат стоит дороже недельной нормы', Ledger::RATES['comeback'] > Ledger::RATES['week_kept']);
check('честный чек-ин оплачивается всегда', Ledger::RATES['checkin'] > 0);

echo "\nЧетыре состояния пропуска (§ 06)\n";
check('0-1 день — в графике', Recovery::stateForGap(0) === 'active' && Recovery::stateForGap(1) === 'active');
check('2-4 дня — пауза', Recovery::stateForGap(2) === 'attention' && Recovery::stateForGap(4) === 'attention');
check('5-10 дней — восстановление', Recovery::stateForGap(5) === 'recovery' && Recovery::stateForGap(10) === 'recovery');
check('больше 10 — сезон на паузе', Recovery::stateForGap(11) === 'dormant');

echo "\nВозврат после срыва — главная метрика\n";
// Отдельный пользователь: историю пишем прямо в базу, чтобы смоделировать разрыв.
$u2 = call($kernel, 'POST', '/api/auth/register', ['phone' => '944443322', 'password' => 'parol12345', 'name' => 'Test2'])['json'];
$H2 = ['x-session-token' => $u2['token']];
$uid2 = (int) $u2['user']['id'];

$kernel->db()->insert('checkin_days', [
    'user_id' => $uid2, 'date' => $ago(6), 'done' => 'yes', 'created_at' => gmdate('c'),
]);
$back = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 3, 'mood' => 3], $H2);
check('возврат после 6 дней зафиксирован', ($back['json']['returned'] ?? false) === true && ($back['json']['gap_days'] ?? 0) === 6);

$prog3 = call($kernel, 'GET', '/api/me/progress', [], $H2);
check('за возврат начислено 100', (int) ($prog3['json']['progress']['xp'] ?? 0) >= Ledger::RATES['comeback']);

$rate = $kernel->db()->first('SELECT gap_days FROM checkin_returns WHERE user_id = ?', [$uid2]);
check('возврат попал в источник Return Rate', (int) ($rate['gap_days'] ?? 0) === 6);

// Полное имя, а не алиас: у алиаса ::class возвращает короткое имя,
// и контейнер такого сервиса не найдёт.
$recoverySvc = $kernel->container->get(Modules\Checkin\Domain\Recovery::class);
check('один разрыв — одна запись возврата', $recoverySvc->registerReturn($uid2, $today) === 0);

$u5 = call($kernel, 'POST', '/api/auth/register', ['phone' => '911110099', 'password' => 'parol12345'])['json'];
$uid5 = (int) $u5['user']['id'];
$kernel->db()->insert('checkin_days', [
    'user_id' => $uid5, 'date' => $ago(2), 'done' => 'yes', 'created_at' => gmdate('c'),
]);
check('перерыв в 2 дня возвратом не считается', $recoverySvc->registerReturn($uid5, $today) === 0);

echo "\nНедельная норма и щиты\n";
$u3 = call($kernel, 'POST', '/api/auth/register', ['phone' => '933332211', 'password' => 'parol12345'])['json'];
$uid3 = (int) $u3['user']['id'];
$week = $kernel->container->get(Modules\Checkin\Domain\Week::class);
$streak = $kernel->container->get(Modules\Checkin\Domain\Streak::class);

// Прошлая неделя: три выполненных дня из нормы 4 — не хватает ровно одного.
$prevWeek = $week->startFor($uid3, $ago(9));
foreach ([0, 1, 2] as $i) {
    $kernel->db()->insert('checkin_days', [
        'user_id' => $uid3,
        'date'    => gmdate('Y-m-d', strtotime($prevWeek) + $i * 86400),
        'done'    => 'yes',
        'created_at' => gmdate('c'),
    ]);
}
$openWeek = $streak->recompute($uid3, $prevWeek);
check('пока неделя открыта, норма не выполнена', $openWeek['kept'] === false);
check('щит не тратится на открытой неделе', $streak->shieldsLeft($uid3, $prevWeek) === 2);

$closed = $streak->recompute($uid3, $prevWeek, true);
check('при закрытии щит спасает неделю', $closed['kept'] === true && $closed['shield_used'] === true);
// Щит принадлежит месяцу спасаемой недели, а не сегодняшнему дню —
// поэтому и проверяем месяц той недели.
check('щит списан', $streak->shieldsLeft($uid3, $prevWeek) === 1);

$reclosed = $streak->recompute($uid3, $prevWeek, true);
check('повторное закрытие второй щит не тратит', $streak->shieldsLeft($uid3, $prevWeek) === 1);
check('повторный пересчёт не снимает зачёт недели', $reclosed['kept'] === true);
check('серия — одна неделя', $streak->current($uid3) === 1);

$u4 = call($kernel, 'POST', '/api/auth/register', ['phone' => '922221100', 'password' => 'parol12345'])['json'];
$uid4 = (int) $u4['user']['id'];
$prevWeek4 = $week->startFor($uid4, $ago(9));
$kernel->db()->insert('checkin_days', [
    'user_id' => $uid4, 'date' => $prevWeek4, 'done' => 'yes', 'created_at' => gmdate('c'),
]);
$far = $streak->recompute($uid4, $prevWeek4, true);
check('щит не спасает неделю, где не хватило двух дней', $far['kept'] === false && $streak->shieldsLeft($uid4) === 2);

echo "\nСобытия-помехи (тўй, болезнь, поездка)\n";
$ev = call($kernel, 'POST', '/api/checkin/event', [
    'type' => 'toy', 'date_from' => $today, 'date_to' => gmdate('Y-m-d', time() + 86400),
], $H2);
check('событие отмечено', $ev['status'] === 200 && ($ev['json']['type'] ?? '') === 'toy');

$afterEvent = call($kernel, 'GET', '/api/checkin/today', [], $H2);
check('норма недели снижена событием', ($afterEvent['json']['week']['norm_days'] ?? 4) < 4, 'норма ' . ($afterEvent['json']['week']['norm_days'] ?? '?'));
check('дни события помечены в неделе',
    count(array_filter($afterEvent['json']['week']['days'] ?? [], static fn($d) => !empty($d['excused']))) >= 1);

$badEvent = call($kernel, 'POST', '/api/checkin/event', ['type' => 'holiday', 'date_from' => $today], $H2);
check('неизвестный тип события отклонён', ($badEvent['json']['error'] ?? '') === 'bad_event_type');

$longEvent = call($kernel, 'POST', '/api/checkin/event', [
    'type' => 'trip', 'date_from' => $today, 'date_to' => gmdate('Y-m-d', time() + 30 * 86400),
], $H2);
check('слишком длинное событие отклонено', ($longEvent['json']['error'] ?? '') === 'event_too_long');

check('норма не падает ниже двух дней', Modules\Checkin\Domain\Week::MIN_NORM_DAYS === 2);

echo "\nМетрика Return Rate доступна только администратору\n";
$rr = call($kernel, 'GET', '/api/metrics/return-rate', [], $H2);
check('обычному пользователю метрика закрыта', $rr['status'] === 403);

// Администратором стал первый зарегистрированный аккаунт; его пароль
// был изменён тестом сброса, поэтому входим заново.
$adminLogin = call($kernel, 'POST', '/api/auth/login', ['phone' => '901234567', 'password' => 'newparol123']);
$rrAdmin = call($kernel, 'GET', '/api/metrics/return-rate', [], ['x-session-token' => (string) ($adminLogin['json']['token'] ?? '')]);
check('администратор метрику видит', $rrAdmin['status'] === 200 && isset($rrAdmin['json']['rate']),
    'статус ' . $rrAdmin['status']);

// ======================================================================
// СРЕЗ 4 — СКВАДЫ
// ======================================================================

echo "\nПодбор сквадов: жёсткие правила Р-06 на случайных пулах\n";
use Modules\Squad\Domain\Matcher as SquadMatcher;
use Modules\Squad\Domain\Scoring as SquadScoring;

$synth = static function (int $n, int $seed): array {
    mt_srand($seed);
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $t = [0, 0, 0, 0, 1, 1, 1, 2, 2, 3][mt_rand(0, 9)];
        $out[] = [
            'user_id' => $i, 'goal_dir' => mt_rand(0, 9) < 8 ? 'lose' : 'gain', 'sex' => mt_rand(0, 1) ? 'male' : 'female',
            'age' => mt_rand(23, 36), 'lang' => mt_rand(0, 9) < 6 ? 'ru' : 'uz', 'tier' => 'T' . $t,
            'steps' => [3000, 5500, 9000, 12500][$t] + mt_rand(-900, 900), 'time_budget' => [15, 30, 45, 60][mt_rand(0, 3)],
            'bmi' => mt_rand(220, 340) / 10, 'window' => ['morning', 'day', 'evening'][mt_rand(0, 2)], 'social' => mt_rand(1, 3),
            'experience' => ['never_tried', 'lost_motivation', 'no_time'][mt_rand(0, 2)], 'mixed_ok' => mt_rand(0, 3) === 0,
            'commit' => mt_rand(1, 3),
        ];
    }
    return $out;
};

$violations = 0; $dupes = 0; $badSize = 0; $manyAnchors = 0; $placedTotal = 0; $nondet = 0;
foreach ([11, 12, 13, 14, 15] as $seed) {
    $pool = $synth(300, $seed);
    $byId = array_column($pool, null, 'user_id');
    $res  = SquadMatcher::match($pool);
    $seen = [];
    foreach ($res['squads'] as $sq) {
        $members = array_map(static fn($id) => $byId[$id], $sq['members']);
        $violations += SquadMatcher::violations($members) === [] ? 0 : 1;
        $badSize    += (count($members) < 5 || count($members) > 7) ? 1 : 0;
        $manyAnchors += SquadMatcher::anchors($members) > 1 ? 1 : 0;
        foreach ($sq['members'] as $id) {
            $dupes += isset($seen[$id]) ? 1 : 0;
            $seen[$id] = true;
        }
    }
    $placedTotal += count($seen);
    $nondet += json_encode(SquadMatcher::match(array_reverse($pool))) === json_encode($res) ? 0 : 1;
}
check('1500 кандидатов: ни одно жёсткое правило не нарушено', $violations === 0, "нарушений: {$violations}");
check('никто не попал в два сквада', $dupes === 0);
check('размер каждого сквада 5–7', $badSize === 0);
check('якорей не больше одного', $manyAnchors === 0);
check('подбор детерминирован: порядок заявок не влияет', $nondet === 0);
// Случайный пул разбит на 8 страт × 4 ступени — часть людей честно не
// проходит правила. Живые пулы однороднее; здесь проверяем, что алгоритм
// не «сдаётся» раньше времени.
check('большинство распределено даже в случайном пуле', $placedTotal >= 1500 * 0.6, "распределено {$placedTotal}");

// Точечные правила на ручных составах.
$base = ['goal_dir' => 'lose', 'sex' => 'male', 'age' => 28, 'lang' => 'ru', 'tier' => 'T0', 'steps' => 3000,
         'time_budget' => 30, 'bmi' => 28.0, 'window' => 'evening', 'social' => 2, 'experience' => 'no_time',
         'mixed_ok' => false, 'commit' => 2];
$six = [];
for ($i = 1; $i <= 6; $i++) {
    $six[] = ['user_id' => $i] + $base;
}
check('однородная шестёрка допустима', SquadMatcher::violations($six) === []);
$x = $six; $x[0]['goal_dir'] = 'gain';
check('снижение и набор вместе — запрещено', in_array('goal', SquadMatcher::violations($x), true));
$x = $six; $x[0]['tier'] = 'T2';
check('T0 рядом с T2 — запрещено', in_array('tier_spread', SquadMatcher::violations($x), true));
$x = $six; $x[0]['tier'] = 'T1'; $x[1]['tier'] = 'T1';
check('два якоря — запрещено', in_array('anchors_many', SquadMatcher::violations($x), true));
$x = $six; $x[0]['lang'] = 'uz';
check('разные языки — запрещено', in_array('lang', SquadMatcher::violations($x), true));
$x = $six; $x[0]['sex'] = 'female';
check('смешанный без согласия — запрещено', in_array('sex', SquadMatcher::violations($x), true));
$x = $six; foreach ($x as &$m) { $m['mixed_ok'] = true; } unset($m); $x[0]['sex'] = 'female';
check('смешанный, если все согласны, — можно', SquadMatcher::violations($x) === []);
$x = $six; $x[0]['age'] = 40;
check('возраст вне ±6 — запрещено', in_array('age', SquadMatcher::violations($x), true));
$x = $six; $x[0]['window'] = 'morning'; $x[1]['window'] = 'morning'; $x[2]['window'] = 'day';
check('нет общего окна у двух третей — запрещено', in_array('window', SquadMatcher::violations($x), true));
check('коллеги в одном скваде — запрещено', in_array('related', SquadMatcher::violations($six, [SquadMatcher::relationKey(2, 5) => true]), true));

// Родственники при сборке расходятся по разным составам.
$twelve = [];
for ($i = 1; $i <= 12; $i++) {
    $twelve[] = ['user_id' => $i, 'tier' => $i % 6 === 0 ? 'T1' : 'T0'] + $base;
}
$rel = [SquadMatcher::relationKey(1, 2) => true, SquadMatcher::relationKey(3, 4) => true];
$m12 = SquadMatcher::match($twelve, $rel);
$together = false;
foreach ($m12['squads'] as $sq) {
    if ((in_array(1, $sq['members'], true) && in_array(2, $sq['members'], true)) || (in_array(3, $sq['members'], true) && in_array(4, $sq['members'], true))) {
        $together = true;
    }
}
check('родня при сборке попадает в разные сквады', count($m12['squads']) === 2 && !$together, json_encode($m12));
check('в каждом скваде ровно один якорь, когда якоря есть', array_sum(array_map(static fn($s) => $s['anchor'] !== null ? 1 : 0, $m12['squads'])) === 2);
$c1 = SquadMatcher::cost(array_slice($twelve, 0, 5));
check('штраф за отсутствие якоря заложен в цену', $c1 >= 4.0, (string) $c1);

echo "\nКомандный счёт по слабейшему звену (Р-08)\n";
$s = SquadScoring::score([100, 100, 100, 100, 100, 100]);
check('все выполнили — 100', $s['score'] === 100.0, json_encode($s));
$s = SquadScoring::score([100, 100, 100, 100, 100, 0]);
check('один выпал — команда получает 60, а не 83', $s['score'] === 60.0, json_encode($s));
$s = SquadScoring::score([150, 150, 150, 150, 150, 50]);
check('перевыполнение не вытягивает команду', $s['score'] === SquadScoring::score([100, 100, 100, 100, 100, 50])['score']);
// Одинаковый суммарный труд (525): распределённый поровну даёт больше очков.
$helped = SquadScoring::score([87.5, 87.5, 87.5, 87.5, 87.5, 87.5]);
$solo   = SquadScoring::score([100, 100, 100, 100, 100, 25]);
check('при том же труде подтянуть отстающего выгоднее', $helped['score'] > $solo['score'], $helped['score'] . ' vs ' . $solo['score']);
$s = SquadScoring::score([100, 100, 100, 100, 100, 100], 2);
check('прибавка за вернувшихся после пропуска', $s['score'] === 105.0, json_encode($s));

// ---------------- живой сценарий ----------------

echo "\nВолна: 48 человек → 8 сквадов по шесть\n";

// Тестовая «доставка» в Telegram: запоминаем, что ушло бы в группы.
$sentToGroups = [];
$kernel->events->on('telegram.group_send', static function (array $p) use (&$sentToGroups): array {
    $sentToGroups[] = $p;
    $p['sent'] = true;
    return $p;
}, 'smoke', 10);

$adminH = ['x-session-token' => (string) ($adminLogin['json']['token'] ?? '')];

$onboard = static function (string $phone, array $ans, int $steps, string $lang, int $workouts = 0) use ($kernel): array {
    $reg = call($kernel, 'POST', '/api/auth/register', ['phone' => $phone, 'password' => 'parol12345', 'name' => 'U' . substr($phone, -4)])['json'];
    $h   = ['x-session-token' => (string) ($reg['token'] ?? '')];
    call($kernel, 'POST', '/api/me/lang', ['lang' => $lang], $h);
    call($kernel, 'POST', '/api/onboarding/answers', $ans, $h);
    call($kernel, 'POST', '/api/onboarding/screening', ['answers' => []], $h);
    for ($d = 1; $d <= 7; $d++) {
        call($kernel, 'POST', '/api/onboarding/baseline', ['steps' => $steps, 'workouts' => $workouts, 'sleep_min' => 420], $h);
    }
    call($kernel, 'POST', '/api/onboarding/complete', [], $h);
    return ['id' => (int) ($reg['user']['id'] ?? 0), 'h' => $h];
};

// Восемь групп: по пять человек T0 и один T1 (якорь). Языки и пол разные.
$groups = [
    ['male', 'ru'], ['male', 'ru'], ['male', 'ru'], ['female', 'ru'],
    ['female', 'ru'], ['male', 'uz'], ['male', 'uz'], ['female', 'uz'],
];
$people = [];
$phoneN = 970000100;
foreach ($groups as $g => [$sex, $lang]) {
    for ($i = 0; $i < 6; $i++) {
        $anchor = $i === 5;
        $ans = [
            'goal_dir' => 'lose', 'sex' => $sex, 'birth_year' => (int) gmdate('Y') - (26 + ($g % 3) * 2 + ($i % 2)),
            'height_cm' => 175, 'weight_kg' => 88.0 + $i, 'time_budget' => 30, 'window' => 'evening',
            'social' => $i === 0 ? 3 : 2, 'experience' => $i % 2 ? 'never_tried' : 'lost_motivation',
        ];
        $people[] = $onboard((string) $phoneN++, $ans, $anchor ? 5200 : 3000 + $i * 100, $lang) + ['group' => $g];
    }
}
$ids = array_column($people, 'id');
check('48 человек прошли онбординг и попали в пул', (int) $kernel->db()->value(
    "SELECT COUNT(*) FROM squad_pool WHERE status = 'waiting' AND user_id IN (" . implode(',', $ids) . ')', [], 0
) === 48);

$waitView = call($kernel, 'GET', '/api/squad', [], $people[0]['h']);
check('до волны человек видит «вы в списке»', ($waitView['json']['status'] ?? '') === 'waiting' && array_key_exists('wave', $waitView['json']) && $waitView['json']['wave'] === null, json_encode($waitView['json'], JSON_UNESCAPED_UNICODE));

$prefs = call($kernel, 'POST', '/api/squad/prefs', ['mixed_ok' => false, 'commit' => 3], $people[0]['h']);
check('вопросы для подбора сохраняются', $prefs['status'] === 200 && (int) ($prefs['json']['data']['commit_level'] ?? 0) === 3);
check('неверный ответ отклонён', call($kernel, 'POST', '/api/squad/prefs', ['commit' => 7], $people[0]['h'])['status'] === 422);
// Возвращаем 2, чтобы не менять цену составов в тесте.
call($kernel, 'POST', '/api/squad/prefs', ['mixed_ok' => false, 'commit' => 2], $people[0]['h']);

check('обычному участнику панель модератора закрыта', call($kernel, 'GET', '/api/admin/squad/waves', [], $people[1]['h'])['status'] === 403);

$startDate = gmdate('Y-m-d', time() + 3 * 86400);
$w = call($kernel, 'POST', '/api/admin/squad/waves', ['start_date' => $startDate, 'title' => 'Осень'], $adminH);
$waveId = (int) ($w['json']['data']['id'] ?? 0);
check('модератор создал волну', $w['status'] === 200 && $waveId > 0, json_encode($w['json'], JSON_UNESCAPED_UNICODE));
check('дата в прошлом отклонена', call($kernel, 'POST', '/api/admin/squad/waves', ['start_date' => $ago(1)], $adminH)['status'] === 422);

$plan0 = call($kernel, 'GET', '/api/plan', [], $people[0]['h']);
check('план сдвинут на день старта волны (Р-07)', ($plan0['json']['plan']['start_date'] ?? '') === $startDate, (string) ($plan0['json']['plan']['start_date'] ?? '?'));
$waitView = call($kernel, 'GET', '/api/squad', [], $people[0]['h']);
check('человек видит дату старта и сколько ждать', ($waitView['json']['wave']['days_left'] ?? 0) === 3);

$match = call($kernel, 'POST', '/api/admin/squad/waves/' . $waveId . '/match', [], $adminH);
check('подбор отработал', $match['status'] === 200, json_encode($match['json'], JSON_UNESCAPED_UNICODE));
$waveView = call($kernel, 'GET', '/api/admin/squad/waves/' . $waveId, [], $adminH)['json'];
$proposals = $waveView['squads'] ?? [];

// В пуле есть и человек из ранних тестов — алгоритм вправе подсадить его
// седьмым. Наши сквады — те, где большинство из этих 48.
$ourSquads = array_values(array_filter($proposals, static function ($sq) use ($ids) {
    return count(array_intersect(array_column($sq['members'], 'user_id'), $ids)) >= 5;
}));
$placed48 = [];
foreach ($ourSquads as $sq) {
    foreach (array_intersect(array_column($sq['members'], 'user_id'), $ids) as $uid) {
        $placed48[$uid] = true;
    }
}
check('собрано 8 сквадов из 48 человек', count($ourSquads) === 8, 'сквадов: ' . count($ourSquads));
check('все 48 распределены', count($placed48) === 48, (string) count($placed48));
$allSix = true; $oneAnchor = true; $clean = true;
foreach ($ourSquads as $sq) {
    $allSix    = $allSix && in_array(count($sq['members']), [6, 7], true);
    $oneAnchor = $oneAnchor && count(array_filter($sq['members'], static fn($m) => $m['role'] === 'anchor')) === 1;
    $clean     = $clean && $sq['rules'] === [];
}
check('в каждом шесть человек (седьмой — только подсаженный)', $allSix);
check('в каждом ровно один якорь', $oneAnchor);
check('ни один состав не нарушает правил', $clean);
check('модератор видит код для привязки группы', !empty($ourSquads[0]['code']));

$waitingUser = $people[0];
$squadViewEarly = call($kernel, 'GET', '/api/squad', [], $waitingUser['h']);
check('до утверждения участник состав не видит', ($squadViewEarly['json']['status'] ?? '') === 'waiting');

// Ручная перестановка, нарушающая правила, не проходит.
$ruMale = null; $uzSquad = null;
foreach ($ourSquads as $sq) {
    if ($sq['lang'] === 'uz' && $uzSquad === null) { $uzSquad = $sq; }
    if ($sq['lang'] === 'ru' && $sq['sex'] === 'male' && $ruMale === null) { $ruMale = $sq; }
}
$badMove = call($kernel, 'POST', '/api/admin/squad/move', ['user_id' => $ruMale['members'][0]['user_id'], 'squad_id' => $uzSquad['id']], $adminH);
check('перестановка в сквад на другом языке отклонена', $badMove['status'] === 422 && in_array('lang', array_column($badMove['json']['rules'] ?? [], 'code'), true));

foreach ($ourSquads as $sq) {
    call($kernel, 'POST', '/api/admin/squad/' . $sq['id'] . '/approve', [], $adminH);
}
$approved = (int) $kernel->db()->value("SELECT COUNT(*) FROM squad_squads WHERE wave_id = ? AND status = 'approved'", [$waveId], 0);
check('модератор утвердил все восемь', $approved === count($ourSquads));

$started = call($kernel, 'POST', '/api/admin/squad/waves/' . $waveId . '/start', [], $adminH);
check('волна стартовала', $started['status'] === 200, json_encode($started['json'], JSON_UNESCAPED_UNICODE));
check('день 1 — назначенная дата волны', ($started['json']['data']['start_date'] ?? '') === $startDate);
check('у каждого сквада есть лидер', (int) $kernel->db()->value("SELECT COUNT(*) FROM squad_squads WHERE wave_id = ? AND status = 'active' AND leader_id IS NOT NULL", [$waveId], 0) === count($ourSquads));

$sqA = (int) $ourSquads[0]['id'];
$sqB = (int) $ourSquads[1]['id'];
$membersA = array_column($ourSquads[0]['members'], 'user_id');
$membersB = array_column($ourSquads[1]['members'], 'user_id');
$hOf = static function (int $uid) use ($people): array {
    foreach ($people as $p) { if ($p['id'] === $uid) { return $p['h']; } }
    return [];
};
$day = static fn(int $n): string => gmdate('Y-m-d', strtotime($startDate . ' 00:00:00 UTC') + ($n - 1) * 86400);

$viewA = call($kernel, 'GET', '/api/squad?date=' . $day(1), [], $hOf($membersA[0]));
check('участник видит свой сквад', ($viewA['json']['status'] ?? '') === 'active' && count($viewA['json']['squad']['members'] ?? []) === 6);
check('день сквада считается от старта', ($viewA['json']['squad']['day'] ?? 0) === 1);
$leaderA = (int) $kernel->db()->value('SELECT leader_id FROM squad_squads WHERE id = ?', [$sqA]);
check('первым лидером стал самый общительный по анкете', $leaderA === (int) $membersA[0], "лидер {$leaderA}");

echo "\nЧат сквада в Telegram\n";
$codeA = (string) $kernel->db()->value('SELECT code FROM squad_squads WHERE id = ?', [$sqA]);
$bind = $kernel->events->emit('telegram.group_message', ['chat_id' => '-100500', 'user_id' => null, 'text' => '/bind ' . $codeA, 'reply' => null]);
check('группа привязывается командой /bind', str_contains((string) ($bind['reply'] ?? ''), $codeA), (string) ($bind['reply'] ?? ''));
check('карточки участников ушли в группу', $sentToGroups !== [] && substr_count((string) end($sentToGroups)['text'], '•') === 6);
$contactAt = (string) $kernel->db()->value('SELECT first_contact_at FROM squad_squads WHERE id = ?', [$sqA]);
check('время первого контакта записано', $contactAt !== '');
$bindAgain = $kernel->events->emit('telegram.group_message', ['chat_id' => '-100500', 'user_id' => null, 'text' => '/bind ZZZZZZ', 'reply' => null]);
check('неизвестный код — понятный ответ', str_contains((string) $bindAgain['reply'], 'кода') || str_contains((string) $bindAgain['reply'], 'kod'));

$reactAt = gmdate('c', strtotime($contactAt) + 12 * 60);
$kernel->events->emit('telegram.group_message', ['chat_id' => '-100500', 'user_id' => $membersA[2], 'text' => 'Всем привет!', 'at' => $reactAt]);
check('первая реакция живого человека зафиксирована', (string) $kernel->db()->value('SELECT first_reaction_at FROM squad_squads WHERE id = ?', [$sqA]) === $reactAt);
$metrics = call($kernel, 'GET', '/api/admin/squad/metrics', [], $adminH)['json'];
check('метрика «до первой реакции» — 12 минут', ($metrics['reaction_minutes']['squads'] ?? null) == 12.0, json_encode($metrics['reaction_minutes'] ?? null));

// Самый активный в чате за первые три дня становится лидером на 3-й день.
for ($i = 0; $i < 5; $i++) {
    $kernel->events->emit('telegram.group_message', ['chat_id' => '-100500', 'user_id' => $membersA[3], 'text' => 'сообщение', 'at' => $day(2) . 'T10:0' . $i . ':00+00:00']);
}
call($kernel, 'GET', '/api/squad?date=' . $day(4), [], $hOf($membersA[0]));
check('на 3-й день лидер — самый активный в чате', (int) $kernel->db()->value('SELECT leader_id FROM squad_squads WHERE id = ?', [$sqA]) === (int) $membersA[3]);

echo "\nНеделя сквада и командный счёт\n";
// Сквад A: все шестеро по 4 дня. Сквад B: пятеро по 4, один не отмечался.
$ins = static function (int $uid, int $fromDay, int $n) use ($kernel, $day): void {
    for ($d = $fromDay; $d < $fromDay + $n; $d++) {
        $kernel->db()->run('INSERT OR REPLACE INTO checkin_days (user_id, date, done, created_at) VALUES (?, ?, ?, ?)', [$uid, $day($d), 'yes', gmdate('c')]);
    }
};
foreach ($membersA as $uid) { $ins((int) $uid, 1, 7); }
foreach ($membersB as $k => $uid) { if ($k < 5) { $ins((int) $uid, 1, 4); } }

$viewA8 = call($kernel, 'GET', '/api/squad?date=' . $day(8), [], $hOf($membersA[1]));
$scoreA = (float) $kernel->db()->value('SELECT score FROM squad_week_scores WHERE squad_id = ? AND week_no = 1', [$sqA]);
check('неделя сквада A: все выполнили — 100', $scoreA === 100.0, (string) $scoreA);
call($kernel, 'GET', '/api/squad?date=' . $day(8), [], $hOf($membersB[0]));
$scoreB = (float) $kernel->db()->value('SELECT score FROM squad_week_scores WHERE squad_id = ? AND week_no = 1', [$sqB]);
check('неделя сквада B: один выпал — 60 на всех', $scoreB === 60.0, (string) $scoreB);
check('история недель видна участнику', count($viewA8['json']['history'] ?? []) === 1);
call($kernel, 'GET', '/api/squad?date=' . $day(9), [], $hOf($membersA[1]));
check('закрытая неделя не пересчитывается повторно', (int) $kernel->db()->value('SELECT COUNT(*) FROM squad_week_scores WHERE squad_id = ?', [$sqA]) === 1);

echo "\nЖизненный цикл участника (§ 05)\n";
$silent = (int) $membersB[5];   // не отмечался с начала
$st = static fn(int $sq, int $uid): string => (string) $kernel->db()->value('SELECT status FROM squad_members WHERE squad_id = ? AND user_id = ?', [$sq, $uid]);
call($kernel, 'GET', '/api/squad?date=' . $day(5), [], $hOf($membersB[0]));
check('4 дня тишины — ещё в деле', $st($sqB, $silent) === 'active');
call($kernel, 'GET', '/api/squad?date=' . $day(6), [], $hOf($membersB[0]));
check('5 дней — «на паузе»', $st($sqB, $silent) === 'paused');
$viewB = call($kernel, 'GET', '/api/squad?date=' . $day(6), [], $hOf($membersB[0]))['json'];
$silentView = array_values(array_filter($viewB['squad']['members'] ?? [], static fn($m) => $m['user_id'] === $silent))[0] ?? [];
check('сквад видит паузу, но не очки и не серии', ($silentView['status'] ?? '') === 'paused' && !isset($silentView['xp']) && !isset($silentView['streak']));
call($kernel, 'GET', '/api/squad?date=' . $day(11), [], $hOf($membersB[0]));
check('10 дней — восстановление, место заморожено', $st($sqB, $silent) === 'recovery');

// Чтобы сквад B не ушёл в роспуск, остальные продолжают отмечаться.
foreach ($membersB as $k => $uid) { if ($k < 5) { $ins((int) $uid, 5, 12); } }
call($kernel, 'GET', '/api/squad?date=' . $day(15), [], $hOf($membersB[0]));
check('14 дней — место освобождено', $st($sqB, $silent) === 'left');
check('после ухода одного замена не нужна: пятеро доигрывают', count(array_filter(
    call($kernel, 'GET', '/api/squad?date=' . $day(15), [], $hOf($membersB[0]))['json']['squad']['members'] ?? [],
    static fn($m) => true
)) === 5);

$ins($silent, 20, 1);
call($kernel, 'GET', '/api/squad?date=' . $day(20), [], $hOf($membersB[0]));
check('вернулся в течение 30 дней — место снова его', $st($sqB, $silent) === 'active');

echo "\nЛидер: ротация, отказ, бездействие, награда\n";
// Сквад A: лидер — membersA[3] с дня 1 (срок до дня 15). Даём ему быть активным.
$ins((int) $membersA[3], 5, 10);
foreach ($membersA as $uid) { $ins((int) $uid, 5, 12); }
call($kernel, 'GET', '/api/squad?date=' . $day(15), [], $hOf($membersA[0]));
$terms = $kernel->db()->all('SELECT user_id, end_reason FROM squad_leader_terms WHERE squad_id = ? ORDER BY id', [$sqA]);
$completed = array_values(array_filter($terms, static fn($t) => $t['end_reason'] === 'completed'));
check('срок 14 дней завершён и роль перешла по кругу', count($completed) === 1 && (int) $completed[0]['user_id'] === (int) $membersA[3]);
$newLeader = (int) $kernel->db()->value('SELECT leader_id FROM squad_squads WHERE id = ?', [$sqA]);
check('новый лидер — следующий по кругу', $newLeader !== (int) $membersA[3] && in_array($newLeader, array_map('intval', $membersA), true));
$xpLeader = (int) $kernel->db()->value("SELECT amount FROM gami_ledger WHERE user_id = ? AND reason = 'leader_term'", [(int) $membersA[3]]);
check('за полный срок лидера — 250 XP, без суточного потолка', $xpLeader === 250, (string) $xpLeader);

$panel = call($kernel, 'GET', '/api/squad/leader?date=' . $day(15), [], $hOf($newLeader));
check('панель лидера открывается лидеру', $panel['status'] === 200 && count($panel['json']['data']['duties'] ?? []) === 3);
check('панель лидера закрыта остальным', call($kernel, 'GET', '/api/squad/leader?date=' . $day(15), [], $hOf((int) $membersA[3]))['status'] === 403);
$help = call($kernel, 'POST', '/api/squad/help', ['user_id' => (int) $membersA[4]], $hOf($newLeader));
check('лидер может отметить «нужна помощь»', $help['status'] === 200);
check('исключать людей лидер не может', call($kernel, 'POST', '/api/admin/squad/' . $sqA . '/remove', ['user_id' => (int) $membersA[4]], $hOf($newLeader))['status'] === 403);

$decl = call($kernel, 'POST', '/api/squad/leader/decline', [], $hOf($newLeader));
$afterDecline = (int) $kernel->db()->value('SELECT leader_id FROM squad_squads WHERE id = ?', [$sqA]);
check('отказ одним нажатием — роль у следующего', $decl['status'] === 200 && $afterDecline !== $newLeader && $afterDecline > 0);
check('за отказ награды нет', (int) $kernel->db()->value("SELECT COUNT(*) FROM gami_ledger WHERE user_id = ? AND reason = 'leader_term'", [$newLeader]) === 0);

// Бездействие: лидер не отмечается и не пишет 4 дня.
$kernel->db()->run('DELETE FROM checkin_days WHERE user_id = ? AND date > ?', [$afterDecline, $day(15)]);
foreach ($membersA as $uid) { if ((int) $uid !== $afterDecline) { $ins((int) $uid, 16, 6); } }
call($kernel, 'GET', '/api/squad?date=' . $day(20), [], $hOf($membersA[0]));
$afterIdle = (int) $kernel->db()->value('SELECT leader_id FROM squad_squads WHERE id = ?', [$sqA]);
check('4 дня бездействия — роль молча переходит дальше', $afterIdle !== $afterDecline,
    json_encode($kernel->db()->all('SELECT user_id, started_on, ended_on, end_reason FROM squad_leader_terms WHERE squad_id = ?', [$sqA])));

echo "\nЗамена и роспуск\n";
// Сквад C: трое уходят молча — осталось трое, а в пуле есть подходящий человек.
$sqC = (int) $ourSquads[2]['id'];
$membersC = array_map('intval', array_column($ourSquads[2]['members'], 'user_id'));
foreach (array_slice($membersC, 0, 3) as $uid) { $ins($uid, 1, 40); }
$spare = $onboard('977777001', [
    'goal_dir' => 'lose', 'sex' => $ourSquads[2]['sex'], 'birth_year' => (int) gmdate('Y') - 27, 'height_cm' => 175,
    'weight_kg' => 90.0, 'time_budget' => 30, 'window' => 'evening', 'social' => 2, 'experience' => 'no_time',
], 3100, $ourSquads[2]['lang']);
call($kernel, 'GET', '/api/squad?date=' . $day(16), [], $hOf($membersC[0]));
$seatsC = (int) $kernel->db()->value("SELECT COUNT(*) FROM squad_members WHERE squad_id = ? AND status <> 'left'", [$sqC], 0);
check('меньше пяти и до конца >45 дней — замена из листа ожидания', $seatsC >= 4 && (int) $kernel->db()->value("SELECT COUNT(*) FROM squad_members WHERE squad_id = ? AND user_id = ? AND status = 'active'", [$sqC, $spare['id']], 0) === 1);
$spareView = call($kernel, 'GET', '/api/squad?date=' . $day(16), [], $spare['h'])['json'];
check('новичок видит свой сквад', ($spareView['status'] ?? '') === 'active');

// Сквад D: все молчат — активных меньше трёх дольше 10 дней → роспуск.
$sqD = (int) $ourSquads[3]['id'];
$membersD = array_map('intval', array_column($ourSquads[3]['members'], 'user_id'));
$ins($membersD[0], 1, 3);
foreach ([6, 10, 18, 27] as $d) { call($kernel, 'GET', '/api/squad?date=' . $day($d), [], $hOf($membersD[0])); }
check('роспуск при долгой нехватке активных', (string) $kernel->db()->value('SELECT status FROM squad_squads WHERE id = ?', [$sqD]) === 'disbanded');
check('после роспуска люди снова ждут сквад, а не выброшены', (int) $kernel->db()->value(
    "SELECT COUNT(*) FROM squad_pool WHERE status = 'waiting' AND user_id IN (" . implode(',', $membersD) . ')', [], 0
) === 6);

echo "\nВебхук Telegram передаёт сообщения групп модулям\n";
$kernel->config->set('telegram.webhook_secret', 'smoke-secret');
$seenGroup = null;
$kernel->events->on('telegram.group_message', static function (array $p) use (&$seenGroup): array {
    $seenGroup = $p;
    return $p;
}, 'smoke', 200);
$wh = $kernel->handle(App\Request::make('POST', '/api/telegram/webhook', ['message' => [
    'message_id' => 1, 'date' => time(), 'text' => 'Салом!',
    'chat' => ['id' => -100777, 'type' => 'supergroup'],
    'from' => ['id' => 555777, 'is_bot' => false, 'first_name' => 'Aziz'],
]], [], ['x-telegram-bot-api-secret-token' => 'smoke-secret']));
check('вебхук принимает сообщение группы', $wh->status === 200);
check('человек узнан по Telegram ID', ($seenGroup['user_id'] ?? null) === (int) $kernel->db()->value("SELECT id FROM identity_users WHERE tg_id = '555777'"));
$seenGroup = null;
$kernel->handle(App\Request::make('POST', '/api/telegram/webhook', ['message' => [
    'message_id' => 2, 'date' => time(), 'text' => 'бот пишет',
    'chat' => ['id' => -100777, 'type' => 'supergroup'], 'from' => ['id' => 42, 'is_bot' => true],
]], [], ['x-telegram-bot-api-secret-token' => 'smoke-secret']));
check('сообщения ботов не считаются', $seenGroup === null);
check('без секрета вебхук закрыт', $kernel->handle(App\Request::make('POST', '/api/telegram/webhook', ['message' => []]))->status === 403);

echo "\nВозвращение объявляется скваду\n";
$before = count($sentToGroups);
$kernel->db()->run('DELETE FROM checkin_days WHERE user_id = ? AND date > ?', [(int) $membersA[5], $day(4)]);
call($kernel, 'GET', '/api/squad?date=' . $day(16), [], $hOf($membersA[0]));
check('ушедший в восстановление отмечен', $st($sqA, (int) $membersA[5]) === 'recovery');
$ins((int) $membersA[5], 17, 1);
call($kernel, 'GET', '/api/squad?date=' . $day(17), [], $hOf($membersA[0]));
$lastMsg = (string) (end($sentToGroups)['text'] ?? '');
check('сквад узнаёт о возвращении — с теплом, без разбора', count($sentToGroups) > $before && str_contains($lastMsg, 'снова с нами'), $lastMsg);

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

echo "\nЧек-ин живёт без плана и без очков\n";
// Второй сценарий отключения: оставляем ядро продукта, но убираем
// планирование и геймификацию. Это проверяет, что ежедневный цикл
// не зависит от того, что на него навешано.
copy($root . '/modules.php', $backup);
file_put_contents(
    $root . '/modules.php',
    "<?php\nreturn ['health','identity','web','checkin'];\n"
);

$code2 = <<<'PHP'
// База задаётся до загрузки ядра: ядро само применяет миграции при
// старте и сразу открывает соединение — менять путь потом поздно.
$db = dirname(__DIR__) . '/storage/db/nodeps.sqlite';
foreach (['', '-wal', '-shm'] as $s) { @unlink($db . $s); }
putenv('DATABASE_PATH=' . $db);
$k = require dirname(__DIR__) . '/app/bootstrap.php';

$reg = $k->handle(App\Request::make('POST', '/api/auth/register',
    ['phone' => '900001122', 'password' => 'parol12345']))->decoded();
$h = ['x-session-token' => (string) ($reg['token'] ?? '')];

$today = $k->handle(App\Request::make('GET', '/api/checkin/today', [], [], $h));
$rec   = $k->handle(App\Request::make('POST', '/api/checkin',
    ['done' => 'yes', 'energy' => 4, 'mood' => 4], [], $h));
$prog  = $k->handle(App\Request::make('GET', '/api/me/progress', [], [], $h));

echo json_encode([
    'planner'     => $k->container->get(App\Contracts\Planner::class)::class,
    'gami'        => $k->container->get(App\Contracts\Gamification::class)::class,
    'today_status'=> $today->status,
    'plan_null'   => $today->decoded()['plan'] === null,
    'norm_days'   => $today->decoded()['week']['norm_days'] ?? null,
    'rec_status'  => $rec->status,
    'done_days'   => $rec->decoded()['week']['done_days'] ?? null,
    'progress'    => $prog->status,
], JSON_UNESCAPED_UNICODE);
PHP;
file_put_contents($root . '/tools/_off2.php', "<?php\n" . $code2 . "\n");
$out2 = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/_off2.php') . ' 2>&1');
@unlink($root . '/tools/_off2.php');
@unlink($root . '/storage/db/nodeps.sqlite');
copy($backup, $root . '/modules.php');
@unlink($backup);

$off2 = json_decode((string) $out2, true);
check('приложение поднимается без planning и gamification', is_array($off2), trim((string) $out2));
if (is_array($off2)) {
    check('Planner падает на заглушку', ($off2['planner'] ?? '') === 'App\Contracts\NullPlanner');
    check('Gamification падает на заглушку', ($off2['gami'] ?? '') === 'App\Contracts\NullGamification');
    check('экран чек-ина работает без плана', ($off2['today_status'] ?? 0) === 200 && !empty($off2['plan_null']));
    check('норма недели берётся по умолчанию', ($off2['norm_days'] ?? 0) === 4);
    check('чек-ин записывается без плана и очков', ($off2['rec_status'] ?? 0) === 200 && ($off2['done_days'] ?? 0) === 1);
    check('маршрут очков исчез вместе с модулем', ($off2['progress'] ?? 0) === 404);
}

echo "\nСквады живут без чек-ина, онбординга, очков и Telegram\n";
// Третий сценарий отключения: остаются только вход и сквады. Модуль не
// должен падать, если некому ответить на его вопросы-события.
copy($root . '/modules.php', $backup);
file_put_contents($root . '/modules.php', "<?php\nreturn ['health','identity','web','squad'];\n");

$code3 = <<<'PHP'
// База задаётся до загрузки ядра: ядро само применяет миграции при
// старте и сразу открывает соединение — менять путь потом поздно.
$db = dirname(__DIR__) . '/storage/db/nosquaddeps.sqlite';
foreach (['', '-wal', '-shm'] as $s) { @unlink($db . $s); }
putenv('DATABASE_PATH=' . $db);
$k = require dirname(__DIR__) . '/app/bootstrap.php';

$admin = $k->handle(App\Request::make('POST', '/api/auth/register', ['phone' => '900009900', 'password' => 'parol12345']))->decoded();
$user  = $k->handle(App\Request::make('POST', '/api/auth/register', ['phone' => '900009901', 'password' => 'parol12345']))->decoded();
$ha = ['x-session-token' => (string) ($admin['token'] ?? '')];
$hu = ['x-session-token' => (string) ($user['token'] ?? '')];

$view  = $k->handle(App\Request::make('GET', '/api/squad', [], [], $hu));
$wave  = $k->handle(App\Request::make('POST', '/api/admin/squad/waves', ['start_date' => gmdate('Y-m-d')], [], $ha));
$wid   = (int) ($wave->decoded()['data']['id'] ?? 0);
$match = $k->handle(App\Request::make('POST', '/api/admin/squad/waves/' . $wid . '/match', [], [], $ha));
$act   = $k->handle(App\Request::make('GET', '/api/admin/squad/active', [], [], $ha));

echo json_encode([
    'view'   => $view->status, 'view_status' => $view->decoded()['status'] ?? null,
    'wave'   => $wave->status, 'match' => $match->status, 'active' => $act->status,
    'errors' => count($k->events->errors()),
], JSON_UNESCAPED_UNICODE);
PHP;
file_put_contents($root . '/tools/_off3.php', "<?php\n" . $code3 . "\n");
$out3 = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/_off3.php') . ' 2>&1');
@unlink($root . '/tools/_off3.php');
@unlink($root . '/storage/db/nosquaddeps.sqlite');
copy($backup, $root . '/modules.php');
@unlink($backup);

$off3 = json_decode((string) $out3, true);
check('приложение поднимается со сквадами без соседей', is_array($off3), trim((string) $out3));
if (is_array($off3)) {
    check('экран сквада отвечает «пока нет»', ($off3['view'] ?? 0) === 200 && ($off3['view_status'] ?? '') === 'none');
    check('волна создаётся и подбор проходит на пустом пуле', ($off3['wave'] ?? 0) === 200 && ($off3['match'] ?? 0) === 200);
    check('список сквадов отвечает, а не падает', ($off3['active'] ?? 0) === 200);
    check('ни один слушатель не упал', ($off3['errors'] ?? 1) === 0);
}

echo "\n" . str_repeat('-', 46) . "\n";
echo $fail === 0 ? "ВСЁ ПРОШЛО: {$pass} проверок\n" : "ПРОВАЛЕНО: {$fail}, прошло: {$pass}\n";
exit($fail === 0 ? 0 : 1);
