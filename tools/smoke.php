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
$noConsent = call($kernel, 'POST', '/api/onboarding/answers', $answers, $H);
check('без согласия на данные о здоровье анкета не сохраняется (Р-19)', ($noConsent['json']['error'] ?? '') === 'consent_required');
$answers['consent_health'] = 1;
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
    call($kernel, 'POST', '/api/onboarding/answers', $ans + ['consent_health' => 1], $h);
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
// В подбор идут только те, у кого есть сезон (§ 05). Здесь — подарок от модератора.
$billingSvc = $kernel->container->get(Modules\Billing\Domain\Billing::class);
foreach ($ids as $uid) {
    $billingSvc->grant((int) $uid, 1, 'smoke');
}
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
$billingSvc->grant($spare['id'], 1, 'smoke');
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

// ======================================================================
// СРЕЗ 5 — ТРЕНЕР, БРАСЛЕТ, БЕЗОПАСНОСТЬ
// ======================================================================
use Modules\Coach\Domain\Guard as CoachGuard;
use Modules\Coach\Domain\Classifier as CoachClassifier;
use Modules\Coach\Domain\LoadRules as CoachLoad;
use Modules\Safety\Domain\Triggers as SafetyTriggers;
use Modules\Wearable\Domain\CsvImport as WearCsv;

echo "\nКрасные линии и правило двух чисел (Р-12, § 07)\n";
$facts = ['steps_yesterday' => 9200, 'steps_prev' => 3100, 'sleep_last_h' => 5.5, 'done' => 2, 'norm' => 4];
check('пример из досье проходит', CoachGuard::violations('В прошлый вторник у вас было 9 200 шагов, в этот — 3 100, и сон меньше 6 часов.', $facts) === []);
check('«Ты стал меньше двигаться» отклоняется', in_array('two_numbers', CoachGuard::violations('Вы стали меньше двигаться.', $facts), true));
check('придуманные числа не засчитываются', in_array('two_numbers', CoachGuard::violations('У вас 12345 шагов и 777 минут.', $facts), true));
$cases = [
    'medical'    => 'Сделайте 2 из 4 дней и выпейте таблетку.',
    'fasting'    => 'Сделайте 2 из 4 дней, а в пятницу голодание.',
    'forever'    => '2 из 4 — и уберите сахар навсегда.',
    'calories'   => '2 из 4 дней, держите 1500 ккал.',
    'shame'      => 'Опять 2 из 4.',
    'comparison' => 'У вас 2 из 4, а другие участники уже закрыли неделю.',
    'body'       => '2 из 4 — жир сам не уйдёт.',
    'banality'   => '2 из 4. Главное — постоянство!',
    'many_actions' => "2 из 4. Советы:\n- гулять\n- спать\n- пить воду",
];
foreach ($cases as $code => $text) {
    check('отклоняется: ' . $code, in_array($code, CoachGuard::violations($text, $facts), true), json_encode(CoachGuard::violations($text, $facts)));
}
$sample = ['done' => 2, 'norm' => 4, 'left' => 2, 'checkins7' => 5, 'done7' => 3, 'energy_avg' => 1.7, 'adj_days' => 3, 'adj_pct' => 30,
           'rhr_last' => 72, 'rhr_base' => 61, 'sleep_last_h' => 5.2, 'sleep_avg_h' => 6.4, 'steps_week' => 6120, 'steps_prev' => 5480,
           'weight_delta' => -2.1, 'weight_weeks' => 2, 'streak' => 1];
$badTpl = [];
foreach (['ru', 'uz'] as $lng) {
    foreach ($kernel->i18n->all($lng) as $key => $tpl) {
        if (!str_starts_with($key, 'coach.tpl.')) { continue; }
        $filled = $tpl;
        foreach ($sample as $k2 => $v2) { $filled = str_replace('{' . $k2 . '}', (string) $v2, $filled); }
        $v = CoachGuard::violations($filled, $sample, str_starts_with($key, 'coach.tpl.week') ? 'week' : 'checkin');
        $v = array_values(array_diff($v, str_starts_with($key, 'coach.tpl.week.') && $key !== 'coach.tpl.week.base' ? ['two_numbers'] : []));
        if ($v !== []) { $badTpl[] = $lng . ':' . $key . '=' . implode(',', $v); }
    }
}
check('все шаблоны тренера сами проходят красные линии (RU и UZ)', $badTpl === [], implode('; ', $badTpl));

echo "\nТриггерные темы уходят человеку (Р-12)\n";
foreach ([
    'не хочу больше жить', 'иногда думаю о самоубийстве', 'вызываю рвоту после еды', 'пью слабительное',
    'не ела 3 дня', "o'zimni o'ldirgim keladi", 'oʻz joniga qasd qilmoqchiman', 'yashagim kelmayapti',
] as $phrase) {
    check('распознано: ' . $phrase, SafetyTriggers::detect($phrase) !== null);
}
foreach (['устал, сегодня не хочу гулять', 'не ел сладкое неделю', 'живу в Чиланзаре', 'bugun charchadim'] as $phrase) {
    check('не тревога: ' . $phrase, SafetyTriggers::detect($phrase) === null);
}

echo "\nКлассификатор спада на окне 14 дней (§ 07)\n";
$mkDays = static function (callable $f): array {
    $out = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = gmdate('Y-m-d', strtotime('2026-10-20 00:00:00 UTC') - $i * 86400);
        $out[] = $f(13 - $i, $d) + ['date' => $d, 'checked' => false, 'done' => null, 'energy' => null, 'mood' => null, 'steps' => null, 'rhr' => null, 'sleep_min' => null];
    }
    return $out;
};
$good = $mkDays(static fn($i) => ['checked' => true, 'done' => 'yes', 'energy' => 4, 'mood' => 4, 'steps' => 6000]);
check('ровный период — steady', CoachClassifier::classify(['days' => $good])['state'] === 'steady');
$gone = $mkDays(static fn($i) => $i < 7 ? ['checked' => true, 'done' => 'yes', 'energy' => 4, 'mood' => 4] : []);
check('7 дней без данных — отказ', CoachClassifier::classify(['days' => $gone])['state'] === 'quit');
check('отмечено событие — помеха', CoachClassifier::classify(['days' => $good, 'event_active' => true])['state'] === 'obstacle');
$tired = $mkDays(static fn($i) => ['checked' => true, 'done' => 'partial', 'energy' => $i >= 11 ? 2 : 4, 'mood' => 3]);
check('энергия ≤ 2 три дня — перегруз', CoachClassifier::classify(['days' => $tired])['state'] === 'overload');
$drift = $mkDays(static fn($i) => ['checked' => true, 'done' => $i >= 7 ? 'no' : 'yes', 'energy' => 3, 'mood' => 3]);
check('чек-ины без действий — потеря смысла', CoachClassifier::classify(['days' => $drift])['state'] === 'drift');

echo "\nФлаги нагрузки (Р-14)\n";
$rhrDays = static fn(int $a, int $b) => $mkDays(static fn($i) => ['checked' => true, 'done' => 'yes', 'energy' => 4, 'mood' => 4, 'rhr' => $i === 12 ? $a : ($i === 13 ? $b : 60)]);
check('пульс +5 два дня подряд — жёлтый', CoachLoad::evaluate(['days' => $rhrDays(66, 67), 'rhr_base' => 60])['level'] === 'yellow');
check('пульс +10 два дня — красный, нагрузка вдвое', ($r = CoachLoad::evaluate(['days' => $rhrDays(71, 72), 'rhr_base' => 60]))['level'] === 'red' && $r['factor'] === 0.5);
check('один день с высоким пульсом — не тренд', CoachLoad::evaluate(['days' => $rhrDays(60, 75), 'rhr_base' => 60])['level'] === 'none');
$sleepy = $mkDays(static fn($i) => ['checked' => true, 'done' => 'yes', 'energy' => 4, 'mood' => 4, 'sleep_min' => $i >= 12 ? 320 : 450]);
check('сон < 6 ч две ночи — жёлтый', in_array('short_sleep', CoachLoad::evaluate(['days' => $sleepy])['reasons'], true));
check('болезнь — жёлтый', CoachLoad::evaluate(['days' => $good, 'event_type' => 'illness'])['level'] === 'yellow');
check('вес −1%+ две недели подряд — жёлтый', in_array('fast_loss', CoachLoad::evaluate(['days' => $good, 'weights' => [['weight_kg' => 90], ['weight_kg' => 88.9], ['weight_kg' => 87.8]]])['reasons'], true));
check('без сигналов — нагрузку не трогаем', CoachLoad::evaluate(['days' => $good, 'rhr_base' => 60])['factor'] === 1.0);

echo "\nБраслет: ручной ввод, правдоподобие, импорт (§ 08, § 10)\n";
$wu = $onboard('955500001', ['goal_dir' => 'lose', 'sex' => 'female', 'birth_year' => 1995, 'height_cm' => 165, 'weight_kg' => 78.0,
    'time_budget' => 30, 'window' => 'morning', 'social' => 2, 'experience' => 'no_system'], 3500, 'ru');
$wh = $wu['h'];
$r1 = call($kernel, 'POST', '/api/wearable/day', ['steps' => 5400, 'sleep_min' => 420, 'rhr' => 62], $wh);
check('ручной ввод сохраняется', $r1['status'] === 200 && ($r1['json']['data']['steps'] ?? 0) === 5400);
check('невозможный пульс отклонён', call($kernel, 'POST', '/api/wearable/day', ['rhr' => 400], $wh)['json']['error'] === 'out_of_range');
check('пустой ввод отклонён', call($kernel, 'POST', '/api/wearable/day', [], $wh)['json']['error'] === 'nothing_to_save');
check('будущий день отклонён', call($kernel, 'POST', '/api/wearable/day', ['steps' => 100, 'date' => gmdate('Y-m-d', time() + 86400)], $wh)['json']['error'] === 'in_future');

$wm = $kernel->container->get(Modules\Wearable\Domain\Metrics::class);
for ($d = 12; $d >= 2; $d--) {
    $wm->record($wu['id'], ['date' => $ago($d), 'steps' => 5000 + $d * 10, 'active_min' => 30], 'import');
}
$spike = $wm->record($wu['id'], ['date' => $ago(1), 'steps' => 21000, 'active_min' => 30], 'import');
check('скачок шагов втрое без активных минут — подозрение', ($spike->data['suspect'] ?? false) === true);
check('подозрительные шаги не идут в расчёты', $kernel->container->get(App\Contracts\Wearable::class)->dayMetrics($wu['id'], $ago(1))['steps'] === null);
$real = $wm->record($wu['id'], ['date' => $ago(1), 'steps' => 21000, 'active_min' => 95], 'import');
check('скачок, подтверждённый активными минутами, — верим', ($real->data['suspect'] ?? true) === false);

$takeout = "Date,Move Minutes count,Calories (kcal),Distance (m),Step count,Average heart rate (bpm)\n"
    . $ago(3) . ",41,2100,5300,6400,88\n" . $ago(2) . ",35,2050,4800,5900,85\n";
$p1 = WearCsv::parse($takeout);
check('Google Takeout: шаги и активные минуты распознаны', ($p1['days'][$ago(3)]['steps'] ?? 0) === 6400 && ($p1['days'][$ago(3)]['active_min'] ?? 0) === 41);
check('калории из выгрузки игнорируются', !isset($p1['days'][$ago(3)]['calories']));
$mi = "Дата;Шаги;Сон;Пульс покоя\n" . gmdate('d.m.Y', time() - 2 * 86400) . ";7 120;7:30;58\n";
$p2 = WearCsv::parse($mi);
check('Mi Fitness: точка с запятой, дд.мм.гггг, сон «7:30»', ($p2['days'][$ago(2)]['sleep_min'] ?? 0) === 450 && ($p2['days'][$ago(2)]['steps'] ?? 0) === 7120 && ($p2['days'][$ago(2)]['rhr'] ?? 0) === 58);
$samsung = "start_time,count\n" . $ago(2) . " 08:00:00,1200\n" . $ago(2) . " 12:00:00,3400\n" . $ago(2) . " 18:00:00,900\n";
$samsung = str_replace('count', 'step_count', $samsung);
$p3 = WearCsv::parse($samsung);
check('почасовые строки складываются в день', ($p3['days'][$ago(2)]['steps'] ?? 0) === 5500, json_encode($p3));
check('чужой файл — понятная ошибка', WearCsv::parse("name,phone\nA,1\n")['error'] === 'no_columns');
check('дни старше трёх месяцев отбрасываются', WearCsv::parse("date,steps\n2020-01-01,5000\n")['error'] === 'no_rows');
$imp = call($kernel, 'POST', '/api/wearable/import', ['csv' => $takeout], $wh);
check('импорт через API', $imp['status'] === 200 && ($imp['json']['data']['days'] ?? 0) === 2);

check('вес вне диапазона отклонён', call($kernel, 'POST', '/api/wearable/weight', ['weight_kg' => 12], $wh)['status'] === 422);
foreach ([21, 14, 7] as $k3 => $d) {
    $kernel->db()->run('INSERT OR REPLACE INTO wear_weights (user_id, date, weight_kg, created_at) VALUES (?, ?, ?, ?)', [$wu['id'], $ago($d), 80 - $k3 * 1.0, gmdate('c')]);
}
$wt = call($kernel, 'POST', '/api/wearable/weight', ['weight_kg' => 76.9], $wh);
check('вес пишется, тренд — по неделям, не по дням', $wt['status'] === 200 && count($wt['json']['data']['trend'] ?? []) === 4);

echo "\nОблегчение плана: легче — без спроса, тяжелее — нельзя (Р-13)\n";
$planSvc = $kernel->container->get(Modules\Planning\Domain\PlanService::class);
$before  = $planSvc->today($wu['id']);
$kernel->events->emit('plan.load_adjust', ['user_id' => $wu['id'], 'factor' => 0.7, 'from' => gmdate('Y-m-d'), 'days' => 3, 'level' => 'yellow', 'reasons' => ['short_sleep']]);
$after = $planSvc->today($wu['id']);
check('нормы на сегодня × 0,7', ($after['norm']['steps'] ?? 0) === (int) round(($before['norm']['steps'] ?? 0) * 0.7) && ($after['adjustment']['level'] ?? '') === 'yellow');
check('облегчение временное: через 3 дня план прежний', ($planSvc->today($wu['id'], gmdate('Y-m-d', time() + 3 * 86400))['adjustment'] ?? null) === null);
check('сделать тяжелее этим путём нельзя', $planSvc->adjust($wu['id'], 1.3, gmdate('Y-m-d'), 3, 'yellow') === false);
check('повторный сигнал не плодит облегчения', $planSvc->adjust($wu['id'], 0.7, gmdate('Y-m-d'), 3, 'yellow') === false);
$kernel->db()->run('DELETE FROM planning_adjustments WHERE user_id = ?', [$wu['id']]);

echo "\nТренер на шаблонах: без модели продукт работает (Р-22)\n";
$wu2 = $onboard('955500002', ['goal_dir' => 'lose', 'sex' => 'male', 'birth_year' => 1992, 'height_cm' => 180, 'weight_kg' => 95.0,
    'time_budget' => 45, 'window' => 'evening', 'social' => 3, 'experience' => 'lost_motivation'], 4200, 'ru');
$ck = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 4, 'mood' => 4], $wu2['h']);
$ctxSvc = $kernel->container->get(Modules\Coach\Domain\Context::class);
$f2 = $ctxSvc->build($wu2['id'])['facts'];
check('чек-ин получает ответ тренера', !empty($ck['json']['coach']['text']), json_encode($ck['json']['coach'] ?? null, JSON_UNESCAPED_UNICODE));
check('без согласия — шаблон, а не модель', ($ck['json']['coach']['source'] ?? '') === 'template');
check('ответ-шаблон проходит все правила, в нём два числа человека', CoachGuard::violations((string) ($ck['json']['coach']['text'] ?? ''), $f2) === [], json_encode(CoachGuard::violations((string) ($ck['json']['coach']['text'] ?? ''), $f2)));

$wk = call($kernel, 'GET', '/api/coach/week', [], $wu2['h']);
$wkText = (string) ($wk['json']['review']['text'] ?? '');
check('недельный обзор готов без модели', $wk['status'] === 200 && $wkText !== '');
check('КРИТЕРИЙ: в обзоре минимум два числа из данных человека', CoachGuard::userNumbers($wkText, $f2) >= 2, $wkText);
check('обзор один на неделю — повторно не пересчитывается', (call($kernel, 'GET', '/api/coach/week', [], $wu2['h'])['json']['review']['id'] ?? 0) === ($wk['json']['review']['id'] ?? -1));
check('«полезно / не очень» сохраняется', call($kernel, 'POST', '/api/coach/feedback', ['id' => $wk['json']['review']['id'], 'useful' => true], $wu2['h'])['status'] === 200);
check('чужой обзор оценить нельзя', call($kernel, 'POST', '/api/coach/feedback', ['id' => $wk['json']['review']['id'], 'useful' => false], $wh)['status'] === 404);

echo "\nТренер с моделью: согласие, проверка ответа, откат (Р-19, Р-22)\n";
$fake = new class implements Modules\Coach\Domain\LlmClient {
    public int $calls = 0;
    public ?string $next = null;
    public string $lastUser = '';
    public function isAvailable(): bool { return true; }
    public function complete(string $model, string $system, string $user, int $maxTokens, int $timeout): ?array
    {
        $this->calls++;
        $this->lastUser = $user;
        return $this->next === null ? null : ['text' => $this->next, 'tokens_in' => 900, 'tokens_out' => 60];
    }
};
$kernel->container->instance(Modules\Coach\Domain\LlmClient::class, $fake);
$coachSvc = $kernel->container->get(Modules\Coach\Domain\CoachService::class);

$fake->next = 'Хорошо.';
call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 4, 'mood' => 4], $wu2['h']);
check('без согласия модель не вызывается вовсе', $fake->calls === 0);

$kernel->container->get(Modules\Billing\Domain\Billing::class)->grant($wu2['id'], 1, 'smoke');
check('согласие даётся отдельным действием', call($kernel, 'POST', '/api/coach/consent', ['ai' => true], $wu2['h'])['json']['consent_ai'] === true);
$f2 = $ctxSvc->build($wu2['id'])['facts'];
$fake->next = 'На этой неделе ' . $f2['done'] . ' из ' . $f2['norm'] . ' дней. Завтра — то же действие утром.';
$m1 = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 4, 'mood' => 4], $wu2['h']);
check('корректный ответ модели показывается', ($m1['json']['coach']['source'] ?? '') === 'model' && $fake->calls === 1, json_encode($m1['json']['coach'] ?? null, JSON_UNESCAPED_UNICODE));
check('в модель не уходят имя и телефон — только псевдоним', !str_contains($fake->lastUser, 'U0002') && !str_contains($fake->lastUser, '955500002')
    && !str_contains($fake->lastUser, '"user_id"') && str_contains($fake->lastUser, '"pid"'));

$fake->next = 'Главное — постоянство, у вас всё получится!';
$m2 = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 4, 'mood' => 4], $wu2['h']);
check('банальность от модели не показывается — уходит шаблон', ($m2['json']['coach']['source'] ?? '') === 'template');
check('причина отказа записана для разбора — без текста и без имени', str_contains((string) $kernel->db()->value('SELECT codes FROM coach_rejections ORDER BY id DESC LIMIT 1'), 'banality'));

$fake->next = null;   // провайдер лежит
$m3 = call($kernel, 'POST', '/api/checkin', ['done' => 'yes', 'energy' => 4, 'mood' => 4], $wu2['h']);
check('модель недоступна — чек-ин не страдает, ответ по шаблону', $m3['status'] === 200 && ($m3['json']['coach']['source'] ?? '') === 'template');

$kernel->config->set('ai.daily_limit', 1);
$callsBefore = $fake->calls;
$fake->next = 'Неважно.';
call($kernel, 'POST', '/api/checkin', ['done' => 'partial', 'energy' => 3, 'mood' => 3], $wu2['h']);
check('суточный лимит обращений соблюдается', $fake->calls === $callsBefore);
$kernel->config->set('ai.daily_limit', 40);

$kernel->db()->run("DELETE FROM coach_msgs WHERE user_id = ? AND kind = 'week'", [$wu2['id']]);
$f2 = $ctxSvc->build($wu2['id'])['facts'];
$fake->next = 'За 7 дней ' . $f2['checkins7'] . ' чек-ина. Норма следующей недели — ' . $f2['norm'] . ' дня: выберите их в воскресенье.';
$wk2 = call($kernel, 'GET', '/api/coach/week', [], $wu2['h']);
check('недельный обзор от модели — с двумя числами человека', ($wk2['json']['review']['source'] ?? '') === 'model'
    && CoachGuard::userNumbers((string) $wk2['json']['review']['text'], $f2) >= 2, json_encode($wk2['json']['review'] ?? null, JSON_UNESCAPED_UNICODE));
$metricsC = call($kernel, 'GET', '/api/admin/coach/metrics', [], $adminH);
check('модератор видит качество тренера', $metricsC['status'] === 200 && isset($metricsC['json']['rejected']['banality']));
check('участнику метрики тренера закрыты', call($kernel, 'GET', '/api/admin/coach/metrics', [], $wu2['h'])['status'] === 403);
call($kernel, 'POST', '/api/coach/consent', ['ai' => false], $wu2['h']);
check('согласие отзывается — снова только шаблоны', $coachSvc->hasConsent($wu2['id']) === false);

echo "\nФлаг нагрузки из реальных данных → облегчение плана\n";
// Три дня подряд энергия 2: вчера, позавчера и сегодня.
foreach ([2, 1] as $d) {
    $kernel->db()->run('INSERT OR REPLACE INTO checkin_days (user_id, date, done, energy, mood, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$wu2['id'], $ago($d), 'partial', 2, 3, gmdate('c')]);
}
$tiredCk = call($kernel, 'POST', '/api/checkin', ['done' => 'partial', 'energy' => 2, 'mood' => 3], $wu2['h']);
$adj = $planSvc->today($wu2['id'])['adjustment'] ?? null;
check('энергия ≤ 2 три дня — план облегчён на 30% на 3 дня', ($adj['level'] ?? '') === 'yellow' && ($adj['factor'] ?? 0) === 0.7);
check('тренер объясняет облегчение числами', str_contains((string) ($tiredCk['json']['coach']['text'] ?? ''), '30%'), (string) ($tiredCk['json']['coach']['text'] ?? ''));
check('и говорит, что это ошибка плана, не человека', str_contains((string) ($tiredCk['json']['coach']['text'] ?? ''), 'ошибка плана'));

// Пульс покоя +10 два дня подряд — красный.
for ($d = 20; $d >= 2; $d--) { $wm->record($wu2['id'], ['date' => $ago($d), 'rhr' => 60], 'import'); }
$wm->record($wu2['id'], ['date' => $ago(1), 'rhr' => 71], 'import');
$wm->record($wu2['id'], ['date' => gmdate('Y-m-d'), 'rhr' => 72], 'import');
$redCk = call($kernel, 'POST', '/api/checkin', ['done' => 'partial', 'energy' => 3, 'mood' => 3], $wu2['h']);
check('пульс +10 два дня — красный флаг, нагрузка вдвое', ($planSvc->today($wu2['id'])['adjustment']['factor'] ?? 0) === 0.5);
check('красный флаг: совет показаться врачу, без диагнозов', str_contains((string) ($redCk['json']['coach']['text'] ?? ''), 'врачу'), (string) ($redCk['json']['coach']['text'] ?? ''));

$kernel->db()->run('DELETE FROM planning_adjustments WHERE user_id = ?', [$wu2['id']]);
$kernel->events->emit('user.returned', ['user_id' => $wu2['id'], 'gap_days' => 9, 'date' => gmdate('Y-m-d')]);
check('возврат после паузы > 7 дней — старт с 60%', ($planSvc->today($wu2['id'])['adjustment']['factor'] ?? 0) === 0.6);
check('через неделю — 75%, ещё через неделю — 90%', ($planSvc->today($wu2['id'], gmdate('Y-m-d', time() + 7 * 86400))['adjustment']['factor'] ?? 0) === 0.75
    && ($planSvc->today($wu2['id'], gmdate('Y-m-d', time() + 14 * 86400))['adjustment']['factor'] ?? 0) === 0.9);

echo "\nКризисный протокол: человек, а не ИИ (Р-12)\n";
$crisis = call($kernel, 'POST', '/api/checkin', ['done' => 'no', 'skip_reason' => 'didnt_want', 'energy' => 1, 'mood' => 1, 'note' => 'не хочу больше жить'], $wh);
check('в ответе — заранее написанный текст и контакты', !empty($crisis['json']['safety']['text']) && str_contains((string) $crisis['json']['safety']['contacts'], '103'));
check('тренер в этом сценарии молчит', array_key_exists('coach', $crisis['json']) && $crisis['json']['coach'] === null);
check('отметка дня при этом сохранена', $crisis['status'] === 200);
check('модератор получил сигнал', count(call($kernel, 'GET', '/api/admin/safety/alerts', [], $adminH)['json']['alerts'] ?? []) >= 1);
check('текст заметки в сигнал не копируется', !str_contains(json_encode($kernel->db()->all('SELECT * FROM safety_alerts'), JSON_UNESCAPED_UNICODE), 'жить'));
check('соревновательные элементы скрыты', (call($kernel, 'GET', '/api/me/progress', [], $wh)['json']['progress']['quiet'] ?? false) === true
    && (call($kernel, 'GET', '/api/safety/state', [], $wh)['json']['quiet'] ?? false) === true);
call($kernel, 'POST', '/api/checkin', ['done' => 'no', 'skip_reason' => 'didnt_want', 'energy' => 1, 'mood' => 1, 'note' => 'всё ещё не хочу жить'], $wh);
check('повторное сообщение не плодит сигналы', (int) $kernel->db()->value('SELECT COUNT(*) FROM safety_alerts WHERE user_id = ? AND resolved_at IS NULL', [$wu['id']]) === 1);
$alertId = (int) $kernel->db()->value('SELECT id FROM safety_alerts WHERE user_id = ? AND resolved_at IS NULL', [$wu['id']]);
check('участник не видит чужие сигналы', call($kernel, 'GET', '/api/admin/safety/alerts', [], $wh)['status'] === 403);
call($kernel, 'POST', '/api/admin/safety/alerts/' . $alertId . '/resolve', [], $adminH);
check('модератор связался — сигнал закрыт, тишина снята', (call($kernel, 'GET', '/api/safety/state', [], $wh)['json']['quiet'] ?? true) === false);
$kernel->events->emit('telegram.group_message', ['chat_id' => '-100999', 'user_id' => $wu['id'], 'text' => 'вызываю рвоту после еды', 'at' => gmdate('c')]);
check('тема в чате сквада — тоже сигнал модератору', (int) $kernel->db()->value("SELECT COUNT(*) FROM safety_alerts WHERE user_id = ? AND source = 'group'", [$wu['id']]) === 1);

// ======================================================================
// СРЕЗ 6 — ОПЛАТА
// ======================================================================
echo "\nОплата сезона: перевод на карту и подтверждение (Р-20)\n";
$kernel->config->set('billing.cards', 'Uzcard 8600 0000 0000 0001 — LEVEL 180|Humo 9860 0000 0000 0002');
$bu = $onboard('966600001', ['goal_dir' => 'lose', 'sex' => 'male', 'birth_year' => 1993, 'height_cm' => 177, 'weight_kg' => 91.0,
    'time_budget' => 30, 'window' => 'evening', 'social' => 2, 'experience' => 'no_time'], 3300, 'ru');
$bh = $bu['h'];
$offer = call($kernel, 'GET', '/api/billing', [], $bh)['json'];
check('новичок в Нулевом цикле — 14 дней', ($offer['status']['status'] ?? '') === 'trial' && ($offer['status']['trial_left'] ?? 0) === 14);
check('тарифы Р-20: 390 000, 6 × 79 000, 690 000', array_column($offer['tariffs'] ?? [], 'price', 'key') == ['season' => 390000, 'installment' => 79000, 'duo' => 690000]);
check('второй сезон со скидкой новичку не предлагается', !in_array('second', array_column($offer['tariffs'] ?? [], 'key'), true));
check('без сезона в сквад не попасть', (call($kernel, 'GET', '/api/squad', [], $bh)['json']['needs_season'] ?? false) === true);

$co = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season'], $bh)['json'];
check('оформление: сумма, код для комментария и реквизиты', ($co['data']['instructions']['amount'] ?? 0) === 390000
    && str_starts_with((string) ($co['data']['instructions']['code'] ?? ''), 'L180-') && count($co['data']['instructions']['cards'] ?? []) === 2);
$payId = (int) ($co['data']['payment']['id'] ?? 0);
check('повторное оформление не плодит платежи', (call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'duo'], $bh)['json']['data']['payment']['id'] ?? 0) === $payId);
check('«Я оплатил» отмечается', (call($kernel, 'POST', '/api/billing/payments/' . $payId . '/paid', ['note' => '1234'], $bh)['json']['data']['marked_paid'] ?? false) === true);
check('до подтверждения сезона нет', $billingSvc->hasSeason($bu['id']) === false && (call($kernel, 'GET', '/api/billing', [], $bh)['json']['status']['status'] ?? '') === 'pending');
check('участник не подтверждает сам себе', call($kernel, 'POST', '/api/admin/billing/payments/' . $payId . '/confirm', [], $bh)['status'] === 403);
$queue = call($kernel, 'GET', '/api/admin/billing/payments', [], $adminH)['json']['payments'] ?? [];
check('модератор видит платёж первым в очереди', ($queue[0]['id'] ?? 0) === $payId && ($queue[0]['marked_paid'] ?? false) === true);
check('модератор подтвердил — сезон включился', call($kernel, 'POST', '/api/admin/billing/payments/' . $payId . '/confirm', [], $adminH)['status'] === 200 && $billingSvc->hasSeason($bu['id']));
check('повторное подтверждение ничего не делает', call($kernel, 'POST', '/api/admin/billing/payments/' . $payId . '/confirm', [], $adminH)['status'] === 422);
check('с сезоном купить второй нельзя', (call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season'], $bh)['json']['error'] ?? '') === 'already_active');
check('в пуле человек больше не «ждёт оплату»', (call($kernel, 'GET', '/api/squad', [], $bh)['json']['needs_season'] ?? true) === false);

echo "\nРассрочка 6 × 79 000\n";
$iu = $onboard('966600002', ['goal_dir' => 'lose', 'sex' => 'female', 'birth_year' => 1997, 'height_cm' => 160, 'weight_kg' => 70.0,
    'time_budget' => 15, 'window' => 'morning', 'social' => 1, 'experience' => 'never_tried'], 2800, 'uz');
$i1 = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'installment'], $iu['h'])['json']['data']['payment'];
check('первый платёж рассрочки — 79 000', ($i1['amount'] ?? 0) === 79000 && ($i1['installment_no'] ?? 0) === 1);
$billingSvc->confirm((int) $i1['id'], 1);
$st = $billingSvc->status($iu['id']);
check('после первого платежа — сезон и дата следующего', $st['status'] === 'active' && $st['next_due'] === gmdate('Y-m-d', time() + 30 * 86400));
$i2 = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'installment'], $iu['h'])['json']['data']['payment'] ?? [];
check('второй платёж рассрочки можно внести заранее', ($i2['installment_no'] ?? 0) === 2);
$billingSvc->confirm((int) $i2['id'], 1);
check('после второго — срок сдвинулся на месяц', $billingSvc->status($iu['id'])['next_due'] === gmdate('Y-m-d', time() + 60 * 86400));
check('просрочка больше недели — доступ на паузе', $billingSvc->hasSeason($iu['id'], gmdate('Y-m-d', time() + 68 * 86400)) === false
    && $billingSvc->hasSeason($iu['id'], gmdate('Y-m-d', time() + 66 * 86400)) === true);

echo "\nПромокоды\n";
check('участник не создаёт промокоды', call($kernel, 'POST', '/api/admin/billing/promos', ['code' => 'X1', 'kind' => 'free_season'], $bh)['status'] === 403);
check('кривой код отклонён', call($kernel, 'POST', '/api/admin/billing/promos', ['code' => 'a', 'kind' => 'free_season'], $adminH)['status'] === 422);
call($kernel, 'POST', '/api/admin/billing/promos', ['code' => 'LEADER-AZIZ', 'kind' => 'free_season', 'max_uses' => 1], $adminH);
call($kernel, 'POST', '/api/admin/billing/promos', ['code' => 'OSEN20', 'kind' => 'percent', 'value' => 20, 'max_uses' => 100], $adminH);
$pu1 = $onboard('966600003', ['goal_dir' => 'gain', 'sex' => 'male', 'birth_year' => 1999, 'height_cm' => 182, 'weight_kg' => 68.0,
    'time_budget' => 45, 'window' => 'day', 'social' => 3, 'experience' => 'never_tried'], 9000, 'ru');
$pu2 = $onboard('966600004', ['goal_dir' => 'gain', 'sex' => 'male', 'birth_year' => 1998, 'height_cm' => 180, 'weight_kg' => 66.0,
    'time_budget' => 45, 'window' => 'day', 'social' => 2, 'experience' => 'no_system'], 8800, 'ru');
$free = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season', 'promo' => 'leader-aziz'], $pu1['h'])['json'];
check('промокод «сезон бесплатно» включает сразу', ($free['data']['activated'] ?? false) === true && $billingSvc->hasSeason($pu1['id']));
check('исчерпанный промокод не работает', (call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season', 'promo' => 'LEADER-AZIZ'], $pu2['h'])['json']['error'] ?? '') === 'bad_promo');
$disc = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season', 'promo' => 'OSEN20'], $pu2['h'])['json'];
check('скидка 20%: 312 000 вместо 390 000', ($disc['data']['payment']['amount'] ?? 0) === 312000 && ($disc['data']['payment']['list_price'] ?? 0) === 390000);
call($kernel, 'POST', '/api/billing/payments/' . $disc['data']['payment']['id'] . '/cancel', [], $pu2['h']);
check('отмена возвращает промокод', (int) $kernel->db()->value("SELECT used FROM billing_promos WHERE code = 'OSEN20'") === 0);

echo "\nСезон вдвоём: два места, разные сквады\n";
$du = $onboard('966600005', ['goal_dir' => 'lose', 'sex' => 'female', 'birth_year' => 1991, 'height_cm' => 165, 'weight_kg' => 80.0,
    'time_budget' => 30, 'window' => 'evening', 'social' => 2, 'experience' => 'lost_motivation'], 3000, 'ru');
$dp = $onboard('966600006', ['goal_dir' => 'lose', 'sex' => 'female', 'birth_year' => 1992, 'height_cm' => 163, 'weight_kg' => 79.0,
    'time_budget' => 30, 'window' => 'evening', 'social' => 2, 'experience' => 'lost_motivation'], 3100, 'ru');
$duoPay = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'duo'], $du['h'])['json']['data']['payment'];
check('сезон вдвоём — 690 000', ($duoPay['amount'] ?? 0) === 690000);
$billingSvc->confirm((int) $duoPay['id'], 1);
$duoCode = (string) (call($kernel, 'GET', '/api/billing/duo', [], $du['h'])['json']['codes'][0]['code'] ?? '');
check('покупатель получил код второго места', str_starts_with($duoCode, 'DUO-'));
$redeem = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season', 'promo' => $duoCode], $dp['h'])['json'];
check('партнёр включает сезон кодом', ($redeem['data']['activated'] ?? false) === true && $billingSvc->hasSeason($dp['id']));
check('пара записана в «родню» — сквады их разведут (Р-06)', $kernel->db()->value('SELECT kind FROM squad_relations WHERE user_a = ? AND user_b = ?', [min($du['id'], $dp['id']), max($du['id'], $dp['id'])]) === 'duo');
check('код второго места одноразовый', (call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season', 'promo' => $duoCode], $pu2['h'])['json']['error'] ?? '') === 'bad_promo');

echo "\nРеферальная программа: бонус на дне 30, а не при оплате\n";
$refCode = (string) (call($kernel, 'GET', '/api/billing', [], $bh)['json']['ref_code'] ?? '');
$ru = $onboard('966600007', ['goal_dir' => 'lose', 'sex' => 'male', 'birth_year' => 1990, 'height_cm' => 175, 'weight_kg' => 88.0,
    'time_budget' => 30, 'window' => 'evening', 'social' => 2, 'experience' => 'no_time'], 3200, 'ru');
check('свой код ввести нельзя', call($kernel, 'POST', '/api/billing/referral', ['code' => $refCode], $bh)['status'] === 422);
check('новичок вводит код пригласившего', call($kernel, 'POST', '/api/billing/referral', ['code' => $refCode], $ru['h'])['status'] === 200);
check('второй раз — нет', call($kernel, 'POST', '/api/billing/referral', ['code' => $refCode], $ru['h'])['status'] === 422);
$kernel->events->emit('checkin.recorded', ['user_id' => $ru['id'], 'date' => gmdate('Y-m-d'), 'done' => 'yes', 'day_number' => 12]);
check('на дне 12 бонуса ещё нет', $billingSvc->creditBalance($bu['id']) === 0);
$kernel->events->emit('checkin.recorded', ['user_id' => $ru['id'], 'date' => gmdate('Y-m-d'), 'done' => 'yes', 'day_number' => 30]);
check('день 30 — по 50 000 обоим', $billingSvc->creditBalance($bu['id']) === 50000 && $billingSvc->creditBalance($ru['id']) === 50000);
$kernel->events->emit('checkin.recorded', ['user_id' => $ru['id'], 'date' => gmdate('Y-m-d'), 'done' => 'yes', 'day_number' => 31]);
check('бонус один раз', $billingSvc->creditBalance($bu['id']) === 50000);
$withCredit = call($kernel, 'POST', '/api/billing/checkout', ['tariff' => 'season'], $ru['h'])['json']['data']['payment'] ?? [];
check('бонус списывается с оплаты: 340 000', ($withCredit['amount'] ?? 0) === 340000 && ($withCredit['credit_used'] ?? 0) === 50000);
$billingSvc->confirm((int) $withCredit['id'], 1);
check('после подтверждения бонус израсходован', $billingSvc->creditBalance($ru['id']) === 0);

echo "\nПанель метрик § 13\n";
$mx = call($kernel, 'GET', '/api/admin/metrics', [], $adminH);
$keys = array_column($mx['json']['metrics'] ?? [], 'key');
check('панель метрик открывается модератору', $mx['status'] === 200);
check('участнику метрики закрыты', call($kernel, 'GET', '/api/admin/metrics', [], $bh)['status'] === 403);
$need = ['return_rate', 'kept_week', 'onboarding_done', 'plan_built', 'first_action_24h', 'three_checkins_week1', 'd7', 'd30', 'd90', 'd180',
         'chapter1_done', 'first_reaction', 'alive_d30', 'alive_d90', 'alive_d180', 'review_useful', 'review_opened', 'trial_to_paid', 'referral_share', 'open_alerts'];
check('все метрики § 13 на месте — и свои, и доложенные модулями', array_diff($need, $keys) === [], implode(',', array_diff($need, $keys)));
$byKey = array_column($mx['json']['metrics'] ?? [], null, 'key');
check('у каждой метрики есть цель и название', ($byKey['return_rate']['target'] ?? 0) == 55 && ($byKey['kept_week']['target'] ?? 0) == 60
    && !str_starts_with((string) ($byKey['kept_week']['title'] ?? 'metric.'), 'metric.'));
check('онбординг посчитан по журналу событий', ($byKey['onboarding_done']['value'] ?? null) !== null && ($byKey['onboarding_done']['n'] ?? 0) >= 50);
check('где данных нет — «мало данных», а не ноль', ($byKey['d180']['status'] ?? '') === 'none' && ($byKey['trial_to_paid']['status'] ?? '') === 'none');
check('первая реакция в скваде — из модуля сквадов', ($byKey['first_reaction']['value'] ?? null) == 12.0 && ($byKey['first_reaction']['better'] ?? '') === 'lower');
check('когорты по неделе регистрации', count($mx['json']['cohorts'] ?? []) >= 1 && ($mx['json']['cohorts'][0]['size'] ?? 0) > 0);
$dup = (int) $kernel->db()->value("SELECT COUNT(*) FROM analytics_events WHERE name = 'checkin' GROUP BY user_id, day ORDER BY COUNT(*) DESC LIMIT 1");
check('правка чек-ина не удваивает событие в журнале', $dup === 1);

echo "\nЛюди, роли и журнал решений\n";
$mod = $onboard('977700001', ['goal_dir' => 'lose', 'sex' => 'male', 'birth_year' => 1988, 'height_cm' => 179, 'weight_kg' => 90.0,
    'time_budget' => 30, 'window' => 'evening', 'social' => 2, 'experience' => 'no_time'], 3300, 'ru');
$found = call($kernel, 'GET', '/api/admin/users?q=977700001', [], $adminH)['json']['users'] ?? [];
check('поиск человека по номеру', ($found[0]['id'] ?? 0) === $mod['id']);
check('модератором назначает только админ', call($kernel, 'POST', '/api/admin/users/' . $mod['id'] . '/role', ['role' => 'moderator'], $bh)['status'] === 403);
check('админ назначил модератора', call($kernel, 'POST', '/api/admin/users/' . $mod['id'] . '/role', ['role' => 'moderator'], $adminH)['status'] === 200);
check('модератору открылась панель сквадов', call($kernel, 'GET', '/api/admin/squad/waves', [], $mod['h'])['status'] === 200);
check('модератор не раздаёт роли', call($kernel, 'POST', '/api/admin/users/' . $bu['id'] . '/role', ['role' => 'admin'], $mod['h'])['status'] === 403);
check('себе роль не поменять', call($kernel, 'POST', '/api/admin/users/' . $mod['id'] . '/block', [], $mod['h'])['status'] === 422);
call($kernel, 'POST', '/api/admin/users/' . $pu2['id'] . '/block', [], $mod['h']);
check('заблокированный сразу теряет вход', call($kernel, 'GET', '/api/me', [], $pu2['h'])['status'] === 401);
call($kernel, 'POST', '/api/admin/users/' . $pu2['id'] . '/unblock', [], $mod['h']);
$audit = array_column(call($kernel, 'GET', '/api/admin/audit', [], $adminH)['json']['audit'] ?? [], 'action');
check('журнал: подтверждения оплат, промокоды, роли, блокировки', array_diff(['payment_confirmed', 'promo_created', 'role', 'block', 'unblock'], $audit) === [], implode(',', $audit));
check('миграции кнопкой — только админ', call($kernel, 'POST', '/api/admin/migrate', [], $mod['h'])['status'] === 403);
$mig = call($kernel, 'POST', '/api/admin/migrate', [], $adminH);
check('миграции кнопкой: всё уже применено', $mig['status'] === 200 && ($mig['json']['applied'] ?? ['x']) === []);

echo "\nПрава на данные: выгрузка (Р-19)\n";
$exp = $kernel->handle(App\Request::make('GET', '/api/me/export', [], [], $wh));
$ej  = $exp->decoded();
$sections = array_keys($ej['sections'] ?? []);
check('выгрузка собирается из всех модулей', array_diff(['identity', 'onboarding', 'planning', 'checkin', 'wearable', 'coach', 'squad', 'billing'], $sections) === [], implode(',', $sections));
check('в выгрузке — свои чек-ины и данные браслета', count($ej['sections']['checkin']['checkin_days'] ?? []) >= 1 && count($ej['sections']['wearable']['wear_days'] ?? []) >= 10);
check('секретов в выгрузке нет', !str_contains($exp->body, 'password_hash') && !str_contains($exp->body, 'token_hash'));
check('чужих данных в выгрузке нет', !in_array($wu2['id'], array_map('intval', array_column($ej['sections']['checkin']['checkin_days'] ?? [], 'user_id')), true));
$csvResp = $kernel->handle(App\Request::make('GET', '/api/me/export?format=csv', [], [], $wh));
check('CSV по дням: чек-ины и браслет в одной таблице', str_starts_with($csvResp->body, 'date,done,energy,mood,skip_reason,steps') && substr_count($csvResp->body, "\n") >= 10);

echo "\nПрава на данные: удаление аккаунта (Р-19)\n";
$eraseId = $bu['id'];
check('без слова-подтверждения не удаляется', call($kernel, 'POST', '/api/me/erase', ['confirm' => 'да'], $bh)['status'] === 422);
$er = call($kernel, 'POST', '/api/me/erase', ['confirm' => 'удалить'], $bh);
check('аккаунт удалён — модули отчитались', $er['status'] === 200 && count($er['json']['erased'] ?? []) >= 8, json_encode($er['json']['erased'] ?? null));
check('сессия умерла сразу', call($kernel, 'GET', '/api/me', [], $bh)['status'] === 401);
$left = 0;
foreach (['onboarding_profiles', 'onboarding_baseline', 'planning_plans', 'checkin_days', 'gami_ledger', 'wear_days', 'coach_msgs', 'squad_pool', 'billing_seasons', 'billing_accounts'] as $tbl) {
    $left += (int) $kernel->db()->value("SELECT COUNT(*) FROM {$tbl} WHERE user_id = ?", [$eraseId], 0);
}
check('в таблицах модулей ни строки об удалённом', $left === 0, (string) $left);
check('оплата осталась как бухгалтерский документ — без человека', (int) $kernel->db()->value('SELECT COUNT(*) FROM billing_payments WHERE user_id = 0 AND amount = 390000', [], 0) >= 1
    && (int) $kernel->db()->value('SELECT COUNT(*) FROM billing_payments WHERE user_id = ?', [$eraseId], 0) === 0);
check('статистика осталась обезличенной', (int) $kernel->db()->value('SELECT COUNT(*) FROM analytics_events WHERE user_id = ?', [$eraseId], 0) === 0);
check('от аккаунта остался только номер строки', $kernel->db()->value('SELECT phone FROM identity_users WHERE id = ?', [$eraseId]) === 'deleted:' . $eraseId);
check('номер телефона освободился — можно зарегистрироваться заново', call($kernel, 'POST', '/api/auth/register', ['phone' => '966600001', 'password' => 'parol12345'])['status'] === 201);
check('модуль не может стереть чужую таблицу даже по ошибке', (static function () use ($kernel): bool {
    try { App\UserData::register($kernel, 'coach', 'coach_', ['checkin_days' => []]); return false; } catch (\LogicException) { return true; }
})());

// ======================================================================
// СРЕЗ 7 — ФОТО, ЛЕНТА СКВАДА, СОЗВОНЫ
// ======================================================================
use Modules\Media\Domain\Images as MediaImages;
use Modules\Media\Domain\MediaService;
use Modules\Calls\Domain\Calls as CallsSvc;
use Modules\Calls\Domain\JitsiRoom;

$rmTree = static function (string $dir) use (&$rmTree): void {
    foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
        is_dir($f) ? $rmTree($f) : @unlink($f);
    }
    @rmdir($dir);
};
$mediaDir = $testData . '/media';
$rmTree($mediaDir);
$kernel->config->set('media.dir', $mediaDir);

/** JPEG с меткой поворота EXIF и «координатами» в комментарии — как с телефона. */
$phonePhoto = static function (int $w, int $h, int $orientation = 1): string {
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, (int) ($w / 2), (int) ($h / 2), imagecolorallocate($img, 220, 30, 30));
    imagefilledrectangle($img, (int) ($w / 2), (int) ($h / 2), $w, $h, imagecolorallocate($img, 30, 30, 220));
    ob_start();
    imagejpeg($img, null, 90);
    $jpeg = (string) ob_get_clean();
    $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . pack('v', 0) . pack('V', 0);
    $app1 = "Exif\0\0" . $tiff . 'GPS41.3111,69.2797';
    $com  = 'GPS41.3111,69.2797 iPhone';
    return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . "\xFF\xFE" . pack('n', strlen($com) + 2) . $com . substr($jpeg, 2);
};
$b64 = static fn(string $bytes): string => 'data:image/jpeg;base64,' . base64_encode($bytes);
$fetch = static function (App\Kernel $k, ?string $url): App\Response {
    return $k->handle(App\Request::make('GET', (string) $url));
};

// Сквады E и F из волны выше — их не трогали другие проверки.
$sqE = (int) $ourSquads[4]['id'];
$sqF = (int) $ourSquads[5]['id'];
$membersOf = static fn(int $sq): array => array_map('intval', array_column($kernel->db()->all("SELECT user_id FROM squad_members WHERE squad_id = ? AND status <> 'left' ORDER BY seat", [$sq]), 'user_id'));
$E = $membersOf($sqE);
$F = $membersOf($sqF);
$leaderE = (int) $kernel->db()->value('SELECT leader_id FROM squad_squads WHERE id = ?', [$sqE]);
$E = array_values(array_merge([$leaderE], array_diff($E, [$leaderE])));   // E[0] — лидер
[$e1, $e2, $e3, $e4, $e5] = [$E[1], $E[2], $E[3], $E[4], $E[5]];

echo "\nФото: только на сервере в Узбекистане (закон о персональных данных)\n";
$kernel->config->set('app.data_residency', '');
$st = call($kernel, 'GET', '/api/feed', [], $hOf($e1))['json'];
check('без DATA_RESIDENCY=UZ фото не принимаются', ($st['state']['reason'] ?? '') === 'media_off' && ($st['state']['media_reason'] ?? '') === 'residency', json_encode($st['state'] ?? null, JSON_UNESCAPED_UNICODE));
check('лента при этом читается', ($st['state']['can_read'] ?? false) === true && ($st['state']['can_post'] ?? true) === false);
$refused = call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(800, 600)), 'confirm' => true, 'tag' => 'gym'], $hOf($e1));
check('загрузка отклонена с объяснением про узбекский сервер', $refused['status'] === 422 && str_contains((string) ($refused['json']['message'] ?? ''), 'Узбекистан'), json_encode($refused['json'], JSON_UNESCAPED_UNICODE));
$hc = array_column(call($kernel, 'GET', '/health.json', [], $adminH)['json']['checks'] ?? [], null, 'title');
check('/health честно пишет, почему фото выключены', isset($hc['Фото: приём и хранение']) && $hc['Фото: приём и хранение']['ok'] === false && $hc['Фото: приём и хранение']['warn'] === true);
check('/health предупреждает, что сервер не заявлен узбекским', ($hc['Сервер в Узбекистане']['ok'] ?? true) === false);

$kernel->config->set('app.data_residency', 'UZ');
putenv('RAILWAY_ENVIRONMENT=production');
$mediaSvc = $kernel->container->get(MediaService::class);
check('на Railway флаг UZ не помогает — платформа за рубежом', $mediaSvc->available()['reason'] === 'foreign_platform');
putenv('RAILWAY_ENVIRONMENT');
check('на узбекском сервере приём включён', $mediaSvc->available()['ok'] === true, json_encode($mediaSvc->available()));

echo "\nФото: проверка, поворот, метаданные\n";
check('JPEG, PNG, WebP узнаются по байтам, а не по имени', MediaImages::sniff("\xFF\xD8\xFF\xE0") === 'jpeg' && MediaImages::sniff("\x89PNG\r\n\x1a\n....") === 'png' && MediaImages::sniff('RIFF1234WEBPVP8 ') === 'webp' && MediaImages::sniff('<?php echo 1;') === null);
$ihdr = pack('NN', 20000, 20000) . "\x08\x02\x00\x00\x00";
$bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
check('«бомба распаковки» 20000×20000 отклоняется до распаковки', MediaImages::process($bomb)->error === 'too_many_pixels');
check('мусор вместо фото отклоняется', MediaImages::process('GIF89a не фото')->error === 'bad_image');
check('поворот по EXIF: снимок «на боку» встаёт прямо', MediaImages::exifOrientation($phonePhoto(40, 30, 6)) === 6
    && (MediaImages::process($phonePhoto(1200, 900, 6))->data['width'] ?? 0) === 900);

echo "\nЛента сквада: публикация\n";
// Группа сквада E привязана — объявления о фото и созвонах уходят в неё.
$codeE = (string) $kernel->db()->value('SELECT code FROM squad_squads WHERE id = ?', [$sqE]);
$kernel->events->emit('telegram.group_message', ['chat_id' => '-100700', 'user_id' => null, 'text' => '/bind ' . $codeE, 'reply' => null]);
call($kernel, 'POST', '/api/admin/squad/' . $sqE . '/link', ['invite_link' => 'https://t.me/+squadE'], $adminH);
$groupBefore = count($sentToGroups);

check('без подтверждения правил не публикуется', (call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(800, 600)), 'tag' => 'gym'], $hOf($e1))['json']['error'] ?? '') === 'confirm_rules');
check('не картинка — понятная ошибка', (call($kernel, 'POST', '/api/feed', ['image' => base64_encode('просто текст'), 'confirm' => true], $hOf($e1))['json']['error'] ?? '') === 'media.bad_image');
$p1 = call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(2400, 1800, 6)), 'confirm' => true, 'tag' => 'gym', 'caption' => '<b>Зал</b>, 40 минут'], $hOf($e1));
check('фото опубликовано', $p1['status'] === 201, json_encode($p1['json'], JSON_UNESCAPED_UNICODE));
$post1 = (int) ($p1['json']['post']['id'] ?? 0);
check('подпись без разметки', ($p1['json']['post']['caption'] ?? '') === 'Зал, 40 минут');

$full = $fetch($kernel, $p1['json']['post']['full'] ?? '');
$dims = @getimagesizefromstring($full->body);
check('полное фото отдаётся как JPEG', $full->status === 200 && ($full->headers['Content-Type'] ?? '') === 'image/jpeg');
check('повёрнуто и ужато до 1600 по длинной стороне', is_array($dims) && $dims[0] === 1200 && $dims[1] === 1600, json_encode($dims));
check('метаданных нет: ни EXIF, ни координат, ни модели телефона', !str_contains($full->body, 'Exif') && !str_contains($full->body, 'GPS41') && !str_contains($full->body, 'iPhone'));
check('отдача закрыта от встраивания и кешируется только лично', str_contains($full->headers['Cache-Control'] ?? '', 'private') && ($full->headers['X-Content-Type-Options'] ?? '') === 'nosniff');
$thumb = @getimagesizefromstring($fetch($kernel, $p1['json']['post']['thumb'] ?? '')->body);
check('превью — не больше 480 точек', is_array($thumb) && max($thumb[0], $thumb[1]) === 480);
$onDisk = glob($mediaDir . '/*/*.img') ?: [];
check('на диске — только пересжатые копии под случайными именами', count($onDisk) === 2 && !str_contains(implode('', array_map('file_get_contents', $onDisk)), 'GPS41'));
check('в папке фото стоит запрет для Apache', is_file($mediaDir . '/.htaccess'));
check('в чат сквада ушло объявление о фото', count($sentToGroups) === $groupBefore + 1 && str_contains((string) end($sentToGroups)['text'], 'зал'));

echo "\nЛента сквада: видят только сокомандники\n";
$v2 = call($kernel, 'GET', '/api/feed', [], $hOf($e2))['json'];
check('сокомандник видит фото', count($v2['posts'] ?? []) === 1 && ($v2['posts'][0]['id'] ?? 0) === $post1);
check('ссылка у каждого зрителя своя', ($v2['posts'][0]['full'] ?? '') !== ($p1['json']['post']['full'] ?? ''));
$vF = call($kernel, 'GET', '/api/feed', [], $hOf($F[0]))['json'];
check('человек из другого сквада не видит ничего', ($vF['posts'] ?? null) === []);
check('и не может поддержать или пожаловаться', call($kernel, 'POST', '/api/feed/' . $post1 . '/support', [], $hOf($F[0]))['status'] === 404
    && call($kernel, 'POST', '/api/feed/' . $post1 . '/report', ['reason' => 'spam'], $hOf($F[0]))['status'] === 404);
$url = (string) ($v2['posts'][0]['full'] ?? '');
check('подпись подделать нельзя', $fetch($kernel, preg_replace('/s=[a-f0-9]+/', 's=' . str_repeat('0', 32), $url))->status === 404);
check('чужого зрителя в ссылку не подставить', $fetch($kernel, preg_replace('/u=\d+/', 'u=' . $F[0], $url))->status === 404);
check('срок ссылки не продлить', $fetch($kernel, preg_replace('/e=\d+/', 'e=' . (time() + 999999), $url))->status === 404);
check('просроченная ссылка не работает', $fetch($kernel, preg_replace('/e=\d+/', 'e=' . (time() - 10), $url))->status === 404);
check('анонимный доступ без подписи — 404', $fetch($kernel, '/api/media/' . str_repeat('a', 32) . '/full')->status === 404);

echo "\nЛента сквада: поддержка без счётчиков\n";
check('себя поддержать нельзя', (call($kernel, 'POST', '/api/feed/' . $post1 . '/support', [], $hOf($e1))['json']['error'] ?? '') === 'own_post');
check('сокомандник поддержал', (call($kernel, 'POST', '/api/feed/' . $post1 . '/support', [], $hOf($e2))['json']['supported'] ?? null) === true);
$mine = call($kernel, 'GET', '/api/feed', [], $hOf($e1))['json']['posts'][0] ?? [];
$other = call($kernel, 'GET', '/api/feed', [], $hOf($e3))['json']['posts'][0] ?? [];
check('автор видит, кто поддержал', count($mine['supporters'] ?? []) === 1);
check('остальные не видят ни имён, ни числа', array_key_exists('supporters', $other) && $other['supporters'] === null && !isset($other['count']) && !isset($other['likes']));
check('повторное нажатие снимает поддержку', (call($kernel, 'POST', '/api/feed/' . $post1 . '/support', [], $hOf($e2))['json']['supported'] ?? null) === false);
call($kernel, 'POST', '/api/feed/' . $post1 . '/support', [], $hOf($e2));

echo "\nЛента сквада: безопасность и лимиты\n";
$crisis = call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(800, 600)), 'confirm' => true, 'caption' => 'не хочу больше жить'], $hOf($e3));
check('тревожная подпись не публикуется — человеку помощь', ($crisis['json']['error'] ?? '') === 'safety' && !empty($crisis['json']['safety']['contacts']));
check('и фото не сохранилось', (int) $kernel->db()->value('SELECT COUNT(*) FROM media_files WHERE user_id = ?', [$e3], 0) === 0);
check('модератор получил сигнал', (int) $kernel->db()->value("SELECT COUNT(*) FROM safety_alerts WHERE user_id = ? AND source = 'feed'", [$e3], 0) === 1);
$p2 = call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(900, 900)), 'confirm' => true, 'tag' => 'plate'], $hOf($e1));
$p3 = call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(900, 900)), 'confirm' => true, 'tag' => 'walk'], $hOf($e1));
check('ещё два фото за день', $p2['status'] === 201 && $p3['status'] === 201);
check('объявление в чат — не чаще раза в два часа', count($sentToGroups) === $groupBefore + 1);
$p4 = call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(900, 900)), 'confirm' => true], $hOf($e1));
check('четвёртое за день — нет', ($p4['json']['error'] ?? '') === 'daily_limit');
$post2 = (int) ($p2['json']['post']['id'] ?? 0);
$post3 = (int) ($p3['json']['post']['id'] ?? 0);

echo "\nЛента сквада: жалобы и модератор\n";
check('одна жалоба «реклама» фото не скрывает', (call($kernel, 'POST', '/api/feed/' . $post2 . '/report', ['reason' => 'spam'], $hOf($e3))['json']['hidden'] ?? null) === false);
check('вторая жалоба скрывает', (call($kernel, 'POST', '/api/feed/' . $post2 . '/report', ['reason' => 'other'], $hOf($e4))['json']['hidden'] ?? null) === true);
check('жалоба «тело» скрывает сразу', (call($kernel, 'POST', '/api/feed/' . $post3 . '/report', ['reason' => 'body'], $hOf($e2))['json']['hidden'] ?? null) === true);
$ids2 = array_column(call($kernel, 'GET', '/api/feed', [], $hOf($e2))['json']['posts'] ?? [], 'id');
check('скрытое сокомандники не видят', !in_array($post2, $ids2, true) && !in_array($post3, $ids2, true));
$authorView = array_column(call($kernel, 'GET', '/api/feed', [], $hOf($e1))['json']['posts'] ?? [], 'status', 'id');
check('автор видит, что фото на проверке', ($authorView[$post2] ?? '') === 'hidden');
check('обычному участнику очередь жалоб закрыта', call($kernel, 'GET', '/api/admin/feed/reports', [], $hOf($e2))['status'] === 403);
$queue = call($kernel, 'GET', '/api/admin/feed/reports', [], $adminH)['json']['reports'] ?? [];
check('модератор видит два фото с жалобами', count($queue) === 2, json_encode(array_column($queue, 'id')));
$q3 = array_values(array_filter($queue, static fn($q) => $q['id'] === $post3))[0] ?? [];
check('и само фото — по своей ссылке', ($q3['reasons']['body'] ?? 0) === 1 && $fetch($kernel, $q3['full'] ?? '')->status === 200);
check('вернул в ленту', (call($kernel, 'POST', '/api/admin/feed/' . $post2 . '/restore', [], $adminH)['json']['status'] ?? '') === 'visible'
    && in_array($post2, array_column(call($kernel, 'GET', '/api/feed', [], $hOf($e2))['json']['posts'] ?? [], 'id'), true));
$media3 = (int) $kernel->db()->value('SELECT media_id FROM feed_posts WHERE id = ?', [$post3]);
check('удалил насовсем — файлов на диске нет', call($kernel, 'POST', '/api/admin/feed/' . $post3 . '/remove', [], $adminH)['status'] === 200
    && !$mediaSvc->fileExists($media3) && $fetch($kernel, $q3['full'])->status === 404);
check('решение — в журнале модераторов', in_array('post_removed', array_column(call($kernel, 'GET', '/api/admin/audit', [], $adminH)['json']['audit'] ?? [], 'action'), true));
check('очередь пуста', call($kernel, 'GET', '/api/admin/feed/reports', [], $adminH)['json']['reports'] === []);

echo "\nЛента сквада: автор удаляет, ушедший пропадает, срок хранения\n";
$media1 = (int) $kernel->db()->value('SELECT media_id FROM feed_posts WHERE id = ?', [$post1]);
check('автор удалил своё — файла нет', call($kernel, 'POST', '/api/feed/' . $post1 . '/delete', [], $hOf($e1))['status'] === 200 && !$mediaSvc->fileExists($media1));
check('чужое удалить нельзя', call($kernel, 'POST', '/api/feed/' . $post2 . '/delete', [], $hOf($e2))['status'] === 404);
$p5 = call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(700, 700)), 'confirm' => true, 'tag' => 'workout'], $hOf($e5));
$post5 = (int) ($p5['json']['post']['id'] ?? 0);
check('фото ещё одного участника видно', in_array($post5, array_column(call($kernel, 'GET', '/api/feed', [], $hOf($e2))['json']['posts'] ?? [], 'id'), true));
call($kernel, 'POST', '/api/admin/squad/' . $sqE . '/remove', ['user_id' => $e5, 'reason' => 'moved'], $adminH);
check('ушёл из сквада — его фото бывшим сокомандникам не видны', !in_array($post5, array_column(call($kernel, 'GET', '/api/feed', [], $hOf($e2))['json']['posts'] ?? [], 'id'), true));
check('а он сам больше не видит ленту сквада', (call($kernel, 'GET', '/api/feed', [], $hOf($e5))['json']['state']['reason'] ?? '') === 'no_team');
$media2 = (int) $kernel->db()->value('SELECT media_id FROM feed_posts WHERE id = ?', [$post2]);
$kernel->db()->run('UPDATE media_files SET expires_at = ? WHERE id = ?', [gmdate('c', time() - 60), $media2]);
check('такт без ключа не запускается', call($kernel, 'GET', '/api/tick')['status'] === 404);
$tick = call($kernel, 'GET', '/api/tick?key=' . $kernel->config->get('admin.health_key'));
check('такт по ключу: истёкшее фото стёрто', $tick['status'] === 200 && !$mediaSvc->fileExists($media2) && str_contains(implode(' ', $tick['json']['done'] ?? []), 'media'), json_encode($tick['json'], JSON_UNESCAPED_UNICODE));
$post2view = array_values(array_filter(call($kernel, 'GET', '/api/feed', [], $hOf($e2))['json']['posts'] ?? [], static fn($p) => $p['id'] === $post2))[0] ?? [];
check('пост остался, фото — нет', $post2view !== [] && $post2view['thumb'] === null);

echo "\nСозвоны сквада\n";
$personal = [];
$kernel->events->on('notify.send', static function (array $p) use (&$personal): array {
    $personal[] = $p;
    return $p;
}, 'smoke', 5);
$callsSvc = $kernel->container->get(CallsSvc::class);
$tz   = new DateTimeZone('Asia/Tashkent');
$in2d = (new DateTimeImmutable('now', $tz))->modify('+2 days')->format('Y-m-d');
check('назначает только лидер', call($kernel, 'POST', '/api/calls', ['date' => $in2d, 'time' => '20:00', 'topic' => 'week'], $hOf($e1))['status'] === 403);
$groupBefore = count($sentToGroups);
$c1 = call($kernel, 'POST', '/api/calls', ['date' => $in2d, 'time' => '20:00', 'duration' => 30, 'topic' => 'week', 'note' => 'итоги'], $hOf($leaderE));
check('лидер назначил созвон', $c1['status'] === 200 && ($c1['json']['session']['local_time'] ?? '') === '20:00', json_encode($c1['json'], JSON_UNESCAPED_UNICODE));
$call1 = (int) ($c1['json']['session']['id'] ?? 0);
check('время хранится в UTC: 20:00 Ташкента = 15:00 UTC', str_contains((string) $kernel->db()->value('SELECT starts_at FROM calls_sessions WHERE id = ?', [$call1]), 'T15:00:00'));
check('сквад узнал в чате', count($sentToGroups) === $groupBefore + 1 && str_contains((string) end($sentToGroups)['text'], '20:00'));
check('это засчитано лидеру как дело недели', $kernel->db()->value('SELECT last_action_at FROM squad_members WHERE squad_id = ? AND user_id = ?', [$sqE, $leaderE]) !== null);
$now = time();
$soon = (new DateTimeImmutable('@' . ($now + 600)))->setTimezone($tz);
check('слишком скоро — нет', (call($kernel, 'POST', '/api/calls', ['date' => $soon->format('Y-m-d'), 'time' => $soon->format('H:i')], $hOf($leaderE))['json']['error'] ?? '') === 'too_soon');
check('дальше двух недель — нет', (call($kernel, 'POST', '/api/calls', ['date' => (new DateTimeImmutable('now', $tz))->modify('+20 days')->format('Y-m-d'), 'time' => '20:00'], $hOf($leaderE))['json']['error'] ?? '') === 'too_far');
check('несуществующее время — нет', (call($kernel, 'POST', '/api/calls', ['date' => $in2d, 'time' => '25:99'], $hOf($leaderE))['json']['error'] ?? '') === 'bad_time');
check('второй в тот же день — нет', (call($kernel, 'POST', '/api/calls', ['date' => $in2d, 'time' => '21:00'], $hOf($leaderE))['json']['error'] ?? '') === 'same_day');
$in3d = (new DateTimeImmutable('now', $tz))->modify('+3 days')->format('Y-m-d');
$in4d = (new DateTimeImmutable('now', $tz))->modify('+4 days')->format('Y-m-d');
check('второй на другой день — да', call($kernel, 'POST', '/api/calls', ['date' => $in3d, 'time' => '19:30', 'topic' => 'free'], $hOf($leaderE))['status'] === 200);
check('третий впрок — нет', (call($kernel, 'POST', '/api/calls', ['date' => $in4d, 'time' => '19:30'], $hOf($leaderE))['json']['error'] ?? '') === 'too_many');

check('ответ «приду»', (call($kernel, 'POST', '/api/calls/' . $call1 . '/rsvp', ['answer' => 'yes'], $hOf($e2))['json']['answer'] ?? '') === 'yes');
call($kernel, 'POST', '/api/calls/' . $call1 . '/rsvp', ['answer' => 'maybe'], $hOf($e3));
call($kernel, 'POST', '/api/calls/' . $call1 . '/rsvp', ['answer' => 'no'], $hOf($e4));
check('непонятный ответ — нет', call($kernel, 'POST', '/api/calls/' . $call1 . '/rsvp', ['answer' => 'ok'], $hOf($e2))['status'] === 422);
check('чужой сквад ответить не может', call($kernel, 'POST', '/api/calls/' . $call1 . '/rsvp', ['answer' => 'yes'], $hOf($F[0]))['status'] === 404);
$ov = call($kernel, 'GET', '/api/calls', [], $hOf($e2))['json'];
$up = $ov['upcoming'][0] ?? [];
check('экран: два созвона, мой ответ, кто придёт', count($ov['upcoming'] ?? []) === 2 && ($up['my_answer'] ?? '') === 'yes' && count($up['coming'] ?? []) === 2);
check('чужой сквад этих созвонов не видит', (call($kernel, 'GET', '/api/calls', [], $hOf($F[0]))['json']['upcoming'] ?? null) === []);
check('ссылку раньше времени не дают', (call($kernel, 'POST', '/api/calls/' . $call1 . '/join', [], $hOf($e2))['json']['error'] ?? '') === 'not_yet');

$start1 = strtotime((string) $kernel->db()->value('SELECT starts_at FROM calls_sessions WHERE id = ?', [$call1]));
$groupBefore = count($sentToGroups);
check('за час — одно напоминание', $callsSvc->remindDue($start1 - 1800) === 1 && $callsSvc->remindDue($start1 - 1700) === 0);
check('напоминание — в чат сквада', count($sentToGroups) === $groupBefore + 1 && str_contains((string) end($sentToGroups)['text'], '20:00'));
$toUsers = array_map('intval', array_column($personal, 'user_id'));
check('и лично тем, кто ответил «приду» или «возможно»', in_array($e2, $toUsers, true) && in_array($e3, $toUsers, true) && !in_array($e4, $toUsers, true));
$join = $callsSvc->join($e2, $call1, $start1 - 300);
check('за 5 минут до начала — ссылка на видеочат группы', $join->ok && ($join->data['url'] ?? '') === 'https://t.me/+squadE' && ($join->data['provider'] ?? '') === 'telegram');
check('после конца — нет', $callsSvc->join($e2, $call1, $start1 + 31 * 60)->error === 'ended');
$jitsi = new JitsiRoom('https://meet.example.uz', 'secret');
$r1 = $jitsi->room(['id' => 1], ['id' => 1]);
check('своя комната Jitsi: у каждого созвона своя неугадываемая', str_starts_with((string) $r1['url'], 'https://meet.example.uz/L180-') && $r1['url'] !== $jitsi->room(['id' => 1], ['id' => 2])['url']);
$c2id = (int) ($ov['upcoming'][1]['id'] ?? 0);
check('отменить может только лидер', call($kernel, 'POST', '/api/calls/' . $c2id . '/cancel', [], $hOf($e2))['status'] === 403);
$groupBefore = count($sentToGroups);
check('лидер отменил — сквад узнал', call($kernel, 'POST', '/api/calls/' . $c2id . '/cancel', [], $hOf($leaderE))['status'] === 200 && count($sentToGroups) === $groupBefore + 1);
check('отменённого в списке нет', count(call($kernel, 'GET', '/api/calls', [], $hOf($e2))['json']['upcoming'] ?? []) === 1);

echo "\nСрез 7: метрики, диагностика, права на данные\n";
$mk = array_column(call($kernel, 'GET', '/api/admin/metrics', [], $adminH)['json']['metrics'] ?? [], null, 'key');
check('в панели: поддержка фото, жалобы, явка на созвоны', isset($mk['feed_supported'], $mk['feed_reports_open'], $mk['calls_attendance']));
check('у новых метрик человеческие названия', ($mk['feed_supported']['title'] ?? 'metric.') !== 'metric.feed_supported' && ($mk['calls_attendance']['title'] ?? 'metric.') !== 'metric.calls_attendance');
$contracts = call($kernel, 'GET', '/health.json', [], $adminH)['json']['contracts'] ?? [];
check('контракты Team, Media, CallProvider — настоящие', ($contracts['Team']['provider'] ?? '') === 'squad' && ($contracts['Media']['provider'] ?? '') === 'media' && ($contracts['CallProvider']['provider'] ?? '') === 'calls');
check('модератор видит склад фото', (call($kernel, 'GET', '/api/admin/media', [], $adminH)['json']['files'] ?? -1) >= 1);

$exp7 = call($kernel, 'GET', '/api/me/export', [], $hOf($e5))['json'];
check('выгрузка: свои фото со ссылкой на сутки, без имени файла', count($exp7['sections']['media']['media_files'] ?? []) === 1
    && str_starts_with((string) ($exp7['sections']['media']['media_files'][0]['link'] ?? ''), '/api/media/')
    && !isset($exp7['sections']['media']['media_files'][0]['token']));
check('выгрузка: свои посты и ответы на созвоны', isset($exp7['sections']['feed']['feed_posts']) && isset($exp7['sections']['calls']));
call($kernel, 'POST', '/api/feed', ['image' => $b64($phonePhoto(600, 600)), 'confirm' => true], $hOf($e2));
$filesE2 = (int) $kernel->db()->value("SELECT COUNT(*) FROM media_files WHERE user_id = ? AND status = 'active'", [$e2], 0);
$tokensE2 = array_column($kernel->db()->all('SELECT token FROM media_files WHERE user_id = ?', [$e2]), 'token');
$er7 = call($kernel, 'POST', '/api/me/erase', ['confirm' => 'удалить'], $hOf($e2));
check('удаление аккаунта: фото, лента, созвоны отчитались', $er7['status'] === 200 && array_diff(['media', 'feed', 'calls'], $er7['json']['erased'] ?? []) === []);
$leftFiles = 0;
foreach ($tokensE2 as $tk) {
    $leftFiles += count(glob($mediaDir . '/' . substr($tk, 0, 2) . '/' . $tk . '_*') ?: []);
}
check('файлы фото стёрты с диска', $filesE2 === 1 && $leftFiles === 0);
check('в таблицах ленты и созвонов ни строки о нём', (int) $kernel->db()->value('SELECT (SELECT COUNT(*) FROM feed_posts WHERE user_id = ?) + (SELECT COUNT(*) FROM feed_support WHERE user_id = ?) + (SELECT COUNT(*) FROM feed_reports WHERE user_id = ?) + (SELECT COUNT(*) FROM calls_rsvp WHERE user_id = ?) + (SELECT COUNT(*) FROM media_files WHERE user_id = ?)', [$e2, $e2, $e2, $e2, $e2], 0) === 0);

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

echo "\nТренер и безопасность живут без чек-ина, плана и браслета\n";
copy($root . '/modules.php', $backup);
file_put_contents($root . '/modules.php', "<?php\nreturn ['health','identity','web','coach','safety'];\n");
$code4 = <<<'PHP'
$db = dirname(__DIR__) . '/storage/db/nocoachdeps.sqlite';
foreach (['', '-wal', '-shm'] as $s) { @unlink($db . $s); }
putenv('DATABASE_PATH=' . $db);
$k = require dirname(__DIR__) . '/app/bootstrap.php';
$u = $k->handle(App\Request::make('POST', '/api/auth/register', ['phone' => '900008800', 'password' => 'parol12345']))->decoded();
$h = ['x-session-token' => (string) ($u['token'] ?? '')];
$week = $k->handle(App\Request::make('GET', '/api/coach/week', [], [], $h));
$text = (string) ($week->decoded()['review']['text'] ?? '');
echo json_encode([
    'week'    => $week->status,
    'numbers' => count(array_unique(Modules\Coach\Domain\Guard::numbers($text))),
    'safety'  => $k->handle(App\Request::make('GET', '/api/safety/state', [], [], $h))->status,
    'checkin' => $k->handle(App\Request::make('POST', '/api/checkin', ['done' => 'yes'], [], $h))->status,
    'errors'  => count($k->events->errors()),
], JSON_UNESCAPED_UNICODE);
PHP;
file_put_contents($root . '/tools/_off4.php', "<?php\n" . $code4 . "\n");
$out4 = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/_off4.php') . ' 2>&1');
@unlink($root . '/tools/_off4.php');
@unlink($root . '/storage/db/nocoachdeps.sqlite');
copy($backup, $root . '/modules.php');
@unlink($backup);
$off4 = json_decode((string) $out4, true);
check('приложение поднимается с тренером без соседей', is_array($off4), trim((string) $out4));
if (is_array($off4)) {
    check('недельный обзор отвечает и в нём есть числа', ($off4['week'] ?? 0) === 200 && ($off4['numbers'] ?? 0) >= 2);
    check('безопасность работает без тренера и чек-ина', ($off4['safety'] ?? 0) === 200);
    check('маршрута чек-ина нет — 404, а не 500', ($off4['checkin'] ?? 0) === 404);
    check('ни один слушатель не упал', ($off4['errors'] ?? 1) === 0);
}

echo "\nМетрики, журнал и права на данные — без остальных модулей\n";
copy($root . '/modules.php', $backup);
file_put_contents($root . '/modules.php', "<?php\nreturn ['health','identity','web','analytics','admin'];\n");
$code5 = <<<'PHP'
$db = dirname(__DIR__) . '/storage/db/noadmindeps.sqlite';
foreach (['', '-wal', '-shm'] as $s) { @unlink($db . $s); }
putenv('DATABASE_PATH=' . $db);
$k = require dirname(__DIR__) . '/app/bootstrap.php';
$a = $k->handle(App\Request::make('POST', '/api/auth/register', ['phone' => '900007700', 'password' => 'parol12345']))->decoded();
$u = $k->handle(App\Request::make('POST', '/api/auth/register', ['phone' => '900007701', 'password' => 'parol12345']))->decoded();
$ha = ['x-session-token' => (string) ($a['token'] ?? '')];
$hu = ['x-session-token' => (string) ($u['token'] ?? '')];
$m = $k->handle(App\Request::make('GET', '/api/admin/metrics', [], [], $ha));
echo json_encode([
    'metrics' => $m->status,
    'count'   => count($m->decoded()['metrics'] ?? []),
    'export'  => $k->handle(App\Request::make('GET', '/api/me/export', [], [], $hu))->status,
    'erase'   => $k->handle(App\Request::make('POST', '/api/me/erase', ['confirm' => 'DELETE'], [], $hu))->status,
    'access'  => $k->container->get(App\Contracts\Access::class)::class,
    'errors'  => count($k->events->errors()),
], JSON_UNESCAPED_UNICODE);
PHP;
file_put_contents($root . '/tools/_off5.php', "<?php\n" . $code5 . "\n");
$out5 = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/_off5.php') . ' 2>&1');
@unlink($root . '/tools/_off5.php');
@unlink($root . '/storage/db/noadmindeps.sqlite');
copy($backup, $root . '/modules.php');
@unlink($backup);
$off5 = json_decode((string) $out5, true);
check('приложение поднимается с метриками без соседей', is_array($off5), trim((string) $out5));
if (is_array($off5)) {
    check('панель метрик отвечает своими цифрами', ($off5['metrics'] ?? 0) === 200 && ($off5['count'] ?? 0) >= 8);
    check('выгрузка и удаление работают без остальных модулей', ($off5['export'] ?? 0) === 200 && ($off5['erase'] ?? 0) === 200);
    check('без модуля оплаты доступ открыт всем (заглушка)', ($off5['access'] ?? '') === 'App\Contracts\NullAccess');
    check('ни один слушатель не упал', ($off5['errors'] ?? 1) === 0);
}

echo "\nЛента, фото и созвоны — без сквадов, оплаты и Telegram\n";
copy($root . '/modules.php', $backup);
file_put_contents($root . '/modules.php', "<?php\nreturn ['health','identity','web','media','feed','calls'];\n");
$code6 = <<<'PHP'
$db = dirname(__DIR__) . '/storage/db/nofeeddeps.sqlite';
foreach (['', '-wal', '-shm'] as $s) { @unlink($db . $s); }
putenv('DATABASE_PATH=' . $db);
$k = require dirname(__DIR__) . '/app/bootstrap.php';
$k->config->set('app.data_residency', 'UZ');
$u = $k->handle(App\Request::make('POST', '/api/auth/register', ['phone' => '900006600', 'password' => 'parol12345']))->decoded();
$h = ['x-session-token' => (string) ($u['token'] ?? '')];
$feed  = $k->handle(App\Request::make('GET', '/api/feed', [], [], $h));
$post  = $k->handle(App\Request::make('POST', '/api/feed', ['image' => 'AAAA', 'confirm' => true], [], $h));
$calls = $k->handle(App\Request::make('GET', '/api/calls', [], [], $h));
$sched = $k->handle(App\Request::make('POST', '/api/calls', ['date' => '2030-01-01', 'time' => '20:00'], [], $h));
$tick  = $k->events->emit('system.tick', ['now' => gmdate('c'), 'done' => []]);
echo json_encode([
    'feed'        => $feed->status,
    'feed_reason' => $feed->decoded()['state']['reason'] ?? null,
    'post'        => $post->decoded()['error'] ?? null,
    'calls'       => $calls->status,
    'calls_team'  => array_key_exists('team', (array) $calls->decoded()) ? $calls->decoded()['team'] : 'x',
    'schedule'    => $sched->decoded()['error'] ?? null,
    'team'        => $k->container->get(App\Contracts\Team::class)::class,
    'erase'       => $k->handle(App\Request::make('POST', '/api/me/erase', ['confirm' => 'DELETE'], [], $h))->status,
    'errors'      => count($k->events->errors()),
], JSON_UNESCAPED_UNICODE);
PHP;
file_put_contents($root . '/tools/_off6.php', "<?php\n" . $code6 . "\n");
$out6 = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/_off6.php') . ' 2>&1');
@unlink($root . '/tools/_off6.php');
@unlink($root . '/storage/db/nofeeddeps.sqlite');
copy($backup, $root . '/modules.php');
@unlink($backup);
$off6 = json_decode((string) $out6, true);
check('приложение поднимается с лентой и созвонами без сквадов', is_array($off6), trim((string) $out6));
if (is_array($off6)) {
    check('без сквада контракт Team — заглушка', ($off6['team'] ?? '') === 'App\Contracts\NullTeam');
    check('лента отвечает «вы пока не в скваде»', ($off6['feed'] ?? 0) === 200 && ($off6['feed_reason'] ?? '') === 'no_team');
    check('публикация вежливо отклонена', ($off6['post'] ?? '') === 'no_team');
    check('созвоны отвечают пустым экраном', ($off6['calls'] ?? 0) === 200 && array_key_exists('calls_team', $off6) && $off6['calls_team'] === null && ($off6['schedule'] ?? '') === 'no_team');
    check('удаление аккаунта работает', ($off6['erase'] ?? 0) === 200);
    check('ни один слушатель не упал', ($off6['errors'] ?? 1) === 0);
}

echo "\n" . str_repeat('-', 46) . "\n";
echo $fail === 0 ? "ВСЁ ПРОШЛО: {$pass} проверок\n" : "ПРОВАЛЕНО: {$fail}, прошло: {$pass}\n";
exit($fail === 0 ? 0 : 1);
