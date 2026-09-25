<?php
/**
 * Конфигурация.
 *
 * Любое значение можно задать переменной окружения — это нужно для
 * Railway, Docker и любого деплоя из Git, где править файл нельзя,
 * не закоммитив секреты в репозиторий. Переменная всегда важнее файла.
 *
 * Приоритет: переменная окружения → значение в этом файле.
 */

/** Читает переменную окружения с приведением типов. */
$env = static function (string $name, mixed $default = null): mixed {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return match (true) {
        in_array(strtolower($value), ['true', '1', 'yes', 'on'], true)   => true,
        in_array(strtolower($value), ['false', '0', 'no', 'off'], true)  => false,
        is_numeric($value) && (string) (int) $value === $value           => (int) $value,
        default                                                          => $value,
    };
};

/**
 * Папка постоянных данных: база, секретный ключ, токены провайдеров.
 *
 * На обычном хостинге это storage/. В контейнере — только смонтированный
 * том, иначе всё стирается при каждом деплое. Railway сам сообщает путь
 * подключённого тома (RAILWAY_VOLUME_MOUNT_PATH), поэтому там достаточно
 * создать Volume — настраивать ничего не нужно. DATA_DIR важнее всего.
 */
$dataDir = rtrim(
    (string) $env('DATA_DIR', getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: __DIR__ . '/storage'),
    '/\\'
);

/** На Railway по умолчанию боевой режим, локально — отладка. */
$onPlatform = getenv('RAILWAY_ENVIRONMENT') !== false;

return [

    'app' => [
        'name'         => $env('APP_NAME', 'LEVEL 180'),
        'url'          => $env('APP_URL', 'https://example.uz'),   // без слэша на конце
        'debug'        => $env('APP_DEBUG', !$onPlatform),         // на хостинге: false
        'default_lang' => $env('APP_LANG', 'ru'),                  // ru | uz
        'timezone'     => $env('APP_TZ', 'Asia/Tashkent'),
        'secret'       => $env('APP_SECRET', ''),                  // пусто = DATA_DIR/secret.key
        'data_dir'     => $dataDir,

        // Применять миграции самостоятельно при первом запросе после
        // деплоя. Выключайте только если запускаете tools/migrate.php сами.
        'auto_migrate' => $env('APP_AUTO_MIGRATE', true),
    ],

    'db' => [
        /**
         * ВАЖНО для Railway, Fly, Render и любого контейнера: файловая
         * система контейнера временная. Без подключённого тома база
         * стирается при каждом деплое вместе со всеми пользователями.
         * Достаточно задать DATA_DIR=/data (путь тома) — база ляжет туда.
         * DATABASE_PATH нужен, только если базу хочется положить отдельно.
         */
        'path' => $env('DATABASE_PATH', $dataDir . '/db/level180.sqlite'),
    ],

    'admin' => [
        'email'    => $env('ADMIN_EMAIL', 'davronkasimov65@gmail.com'),
        'password' => $env('ADMIN_PASSWORD', 'CHANGE_ME'),         // понадобится в срезе 6

        // Ключ для полного отчёта /health без входа в аккаунт:
        //   /health?key=...   Пусто = подробности видит только админ.
        'health_key' => $env('HEALTH_KEY', ''),
    ],

    // Регистрация: телефон + пароль, Telegram, SMS.
    'auth' => [
        'phone_prefix' => '+998',
        'password_min' => 8,
        'session_days' => 180,
        'max_attempts' => 5,                        // попыток входа за 15 минут с IP
        'verify_phone' => $env('AUTH_VERIFY_PHONE', false),
    ],

    'telegram' => [
        'bot_token'      => $env('TELEGRAM_BOT_TOKEN', ''),
        'bot_name'       => $env('TELEGRAM_BOT_NAME', ''),
        'webhook_secret' => $env('TELEGRAM_WEBHOOK_SECRET', ''),
    ],

    'sms' => [
        'provider' => $env('SMS_PROVIDER', ''),     // eskiz
        'login'    => $env('SMS_LOGIN', ''),
        'password' => $env('SMS_PASSWORD', ''),
        'sender'   => $env('SMS_SENDER', '4546'),
    ],

    // Решение Р-22: Flash-Lite на ежедневное, Flash на недельное.
    'ai' => [
        'enabled'     => $env('AI_ENABLED', false),
        'provider'    => 'openai',                  // любой OpenAI-совместимый endpoint
        'base'        => $env('AI_BASE', 'https://generativelanguage.googleapis.com/v1beta/openai'),
        'api_key'     => $env('AI_KEY', ''),
        'model_fast'  => $env('AI_MODEL_FAST', 'gemini-2.5-flash-lite'),
        'model_deep'  => $env('AI_MODEL_DEEP', 'gemini-2.5-flash'),
        'timeout'     => 20,
        'daily_limit' => 40,
    ],

    // Ручной доступ, пока нет эквайринга (нужно юрлицо).
    'billing' => [
        'trial_days'   => 14,                       // Нулевой цикл
        'cards'        => [],                       // ['bank' => 'Uzcard', 'number' => '8600 …']
        'contact'      => $env('BILLING_CONTACT', ''),
        'season_price' => 390000,                   // сум, решение Р-20
    ],
];
