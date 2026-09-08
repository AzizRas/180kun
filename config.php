<?php
/**
 * ЕДИНСТВЕННЫЙ файл, который нужно править при установке.
 * Не хранится в публичной папке и не отдаётся веб-сервером.
 */

return [

    'app' => [
        'name'          => 'LEVEL 180',
        'url'           => 'https://example.uz',   // без слэша на конце
        'debug'         => true,                   // ПЕРЕД ЗАПУСКОМ ПОСТАВИТЬ false
        'default_lang'  => 'ru',                   // ru | uz
        'timezone'      => 'Asia/Tashkent',
        'secret'        => '',                     // пусто = сгенерируется в storage/secret.key
    ],

    'db' => [
        // Вне публичной папки: базу нельзя скачать из браузера.
        'path' => __DIR__ . '/storage/db/level180.sqlite',
    ],

    'admin' => [
        'email'    => 'davronkasimov65@gmail.com',  // этот аккаунт станет админом при регистрации
        'password' => 'CHANGE_ME',                   // пароль входа в админку
    ],

    // Регистрация: телефон + пароль. Telegram-бот и SMS подключаются в слайсе 2.
    'auth' => [
        'phone_prefix'   => '+998',
        'password_min'   => 8,
        'session_days'   => 180,
        'max_attempts'   => 5,      // попыток входа за 15 минут с одного IP
        // Требовать код из SMS при регистрации по номеру.
        // Включить, когда будет подключён SMS-шлюз.
        'verify_phone'   => false,
    ],

    'telegram' => [
        'bot_token'  => '',         // токен от @BotFather
        'bot_name'   => '',         // без @
        'webhook_secret' => '',
    ],

    'sms' => [
        'provider' => '',           // eskiz | playmobile
        'login'    => '',
        'password' => '',
        'sender'   => '4546',
    ],

    // Решение Р-22: Flash-Lite на ежедневное, Flash на недельное.
    // Провайдер остаётся OpenAI-совместимым, чтобы менять поставщика одной строкой.
    'ai' => [
        'enabled'     => false,
        'provider'    => 'openai',
        'base'        => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'api_key'     => '',
        'model_fast'  => 'gemini-2.5-flash-lite',
        'model_deep'  => 'gemini-2.5-flash',
        'timeout'     => 20,
        'daily_limit' => 40,        // защита бюджета: сообщений на пользователя в сутки
    ],

    // Ручной доступ, пока нет эквайринга (нужно юрлицо).
    'billing' => [
        'trial_days' => 14,          // Нулевой цикл
        'cards'      => [
            // ['bank' => 'Uzcard', 'number' => '8600 **** **** ****', 'holder' => 'DAVRON K.'],
        ],
        'contact'    => '',          // ваш Telegram для подтверждения
        'season_price' => 390000,    // сум, решение Р-20
    ],
];
