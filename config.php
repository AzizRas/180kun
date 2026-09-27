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

/**
 * Хостинг без переменных окружения (обычная панель, cPanel): те же имена
 * переменных можно записать в config.local.php рядом с этим файлом —
 * см. config.local.example.php. Настоящая переменная окружения важнее.
 * Файл закрыт от браузера (.htaccess, Caddyfile) и не попадает в Git.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    foreach ((array) require __DIR__ . '/config.local.php' as $name => $value) {
        if (is_string($name) && getenv($name) === false && is_scalar($value)) {
            putenv($name . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }
    }
}

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

        // Где физически стоит сервер. «UZ» пишет владелец, когда приложение
        // развёрнуто в Узбекистане: без этого фото не принимаются (биометрия
        // по закону хранится внутри страны), а /health предупреждает.
        'data_residency' => strtoupper((string) $env('DATA_RESIDENCY', '')),

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

    // Контакты помощи в кризисном протоколе (Р-12). Пусто — берутся из
    // переводов модуля Safety. Строки разделяются «|».
    // ПЕРЕД ЗАПУСКОМ: проверить, что номера действуют.
    'safety' => [
        'contacts_ru' => $env('SAFETY_CONTACTS_RU', ''),
        'contacts_uz' => $env('SAFETY_CONTACTS_UZ', ''),
    ],

    // Ручной доступ, пока нет эквайринга (нужно юрлицо). Решение Р-20.
    'billing' => [
        'trial_days'   => 14,                       // Нулевой цикл
        // Куда переводить. Строки через «|»: «Uzcard 8600 1234 5678 9012 — Иванов И.»
        'cards'        => $env('BILLING_CARDS', ''),
        'contact'      => $env('BILLING_CONTACT', ''),   // @username или телефон для вопросов
        'prices'       => [
            'season'      => 390000,                // сезон разом
            'installment' => 79000,                 // 6 платежей по 79 000
            'duo'         => 690000,                // два места
            'second'      => 290000,                // второй сезон подряд
        ],
        'installments' => 6,
        'referral_bonus' => 50000,                  // обоим, когда приглашённый дошёл до дня 30
    ],

    // Срез 7. Фото действия (тарелка, зал, маршрут — не тело).
    'media' => [
        'enabled'        => $env('MEDIA_ENABLED', true),
        'dir'            => $env('MEDIA_DIR', $dataDir . '/media'),   // вне публичной папки
        'max_upload_mb'  => 10,
        'max_side'       => 1600,                   // длинная сторона полного фото
        'thumb_side'     => 480,
        'daily_limit'    => 6,                      // фото на человека в сутки
        'retention_days' => $env('MEDIA_RETENTION_DAYS', 120),   // потом файл стирается
    ],

    'feed' => [
        'posts_per_day' => 3,
        'caption_max'   => 280,
        'hide_after_reports' => 2,                  // «тело» и «чужое лицо» — скрываются сразу
    ],

    // Созвоны сквада. telegram — видеочат группы сквада; jitsi — своя
    // комната на JITSI_URL (например, Jitsi на том же узбекском сервере).
    'calls' => [
        'provider'  => $env('CALLS_PROVIDER', 'telegram'),
        'jitsi_url' => $env('JITSI_URL', ''),
    ],
];
