<?php
/**
 * Настройки для хостинга, где нельзя задать переменные окружения.
 *
 * 1. Скопируйте этот файл в config.local.php (рядом с config.php).
 * 2. Впишите свои значения. Пустые строки можно удалить.
 * 3. config.local.php не попадает в Git и не открывается из браузера.
 *
 * Имена — те же, что у переменных окружения в README.
 */
return [
    'DATA_RESIDENCY'          => 'UZ',          // сервер в Узбекистане — включает фото
    'DATA_DIR'                => '',            // свой сервер: /var/lib/level180; хостинг: пусто (storage/)
    'APP_URL'                 => 'https://level180.uz',
    'APP_DEBUG'               => false,
    'HEALTH_KEY'              => '',            // длинная случайная строка
    'TELEGRAM_BOT_TOKEN'      => '',
    'TELEGRAM_BOT_NAME'       => '',
    'TELEGRAM_WEBHOOK_SECRET' => '',
    'AI_ENABLED'              => false,
    'AI_KEY'                  => '',
    'BILLING_CARDS'           => '',            // «Uzcard 8600 … — ИП …|Humo 9860 …»
    'BILLING_CONTACT'         => '',
    'SMS_PROVIDER'            => '',            // eskiz
    'SMS_LOGIN'               => '',
    'SMS_PASSWORD'            => '',
    'CALLS_PROVIDER'          => 'telegram',    // telegram | jitsi
    'JITSI_URL'               => '',
];
