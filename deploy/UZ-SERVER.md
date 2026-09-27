# Переезд на сервер в Узбекистане

С поправками 2026 года в закон «О персональных данных» биометрические данные
граждан Узбекистана хранятся только внутри страны. Фото с лицом — биометрия.
Поэтому фото в ленте сквада включаются только на сервере в Узбекистане, а на
Railway они выключены жёстко, даже если выставить флаг по ошибке.

Остальные данные (телефон, чек-ины, анкета) по новой редакции можно хранить и
за рубежом при соблюдении условий закона. Но проще и надёжнее держать всё
приложение на одном узбекском сервере: одна база, одна копия, один ответ
юристу. Эта инструкция — для такого варианта. Подтвердите у юриста, что
ваша схема соответствует действующей редакции закона.

---

## Вариант А. Свой сервер (VDS) — рекомендуется

**Что арендовать:** VDS в дата-центре в Узбекистане, Ubuntu 24.04,
1–2 ядра, 2 ГБ памяти, 20+ ГБ диска. Попросите у провайдера письменное
подтверждение, что сервер физически находится в Узбекистане, — пригодится
для регистрации оператора персональных данных. Нужен домен (например,
`level180.uz`) с записью A на IP сервера.

### 1. Программы

```bash
sudo apt update
sudo apt install -y php8.3-fpm php8.3-sqlite3 php8.3-gd php8.3-mbstring php8.3-curl sqlite3 git
# Caddy (сам получает HTTPS-сертификат):
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy
```

### 2. Код и папка данных

```bash
sudo git clone https://github.com/ВАШ-АККАУНТ/level180.git /var/www/level180
sudo mkdir -p /var/lib/level180
sudo chown -R www-data:www-data /var/lib/level180 /var/www/level180/storage
```

База, секретный ключ и фото живут в `/var/lib/level180` — вне папки сайта.

### 3. Настройки

```bash
cd /var/www/level180
sudo cp config.local.example.php config.local.php
sudo nano config.local.php     # DATA_DIR=/var/lib/level180, домен, HEALTH_KEY, токен бота, карты
sudo chown root:www-data config.local.php && sudo chmod 640 config.local.php
```

`DATA_RESIDENCY => 'UZ'` — это и есть заявление «сервер в Узбекистане»,
которое включает приём фото. Файл читают и сайт, и планировщик.

### 4. PHP, веб-сервер, планировщик

```bash
sudo cp /var/www/level180/deploy/php-fpm-level180.conf /etc/php/8.3/fpm/pool.d/level180.conf
sudo systemctl restart php8.3-fpm

sudo cp /var/www/level180/deploy/Caddyfile.uz /etc/caddy/Caddyfile
sudo nano /etc/caddy/Caddyfile                       # замените level180.uz на свой домен
sudo systemctl reload caddy

sudo cp /var/www/level180/deploy/level180.cron /etc/cron.d/level180
sudo chmod 644 /etc/cron.d/level180
sudo touch /var/log/level180-tick.log && sudo chown www-data /var/log/level180-tick.log
```

### 5. Проверка

Откройте `https://ваш-домен/health?key=ВАШ_HEALTH_KEY`. Должны быть зелёными:

- «Сервер в Узбекистане» — `DATA_RESIDENCY=UZ`;
- «Фото: приём и хранение» — движок gd, папка `/var/lib/level180/media`;
- «База данных открывается», «Миграции применены».

Такт планировщика: `sudo tail /var/log/level180-tick.log` — раз в 5 минут
строка с временем.

### 6. Telegram

1. @BotFather → ваш бот → Bot Settings → Configure Mini App → новый адрес.
2. Вебхук на новый адрес:
   `https://api.telegram.org/bot<ТОКЕН>/setWebhook?url=https://ваш-домен/api/telegram/webhook&secret_token=<TELEGRAM_WEBHOOK_SECRET>`

### 7. Обновления

```bash
cd /var/www/level180 && sudo git pull
```

Миграции применятся сами при первом запросе. Перезапускать ничего не нужно.

### 8. Резервные копии

Скрипт `deploy/backup.sh` запускается каждую ночь (см. `level180.cron`) и
держит 14 копий в `/var/backups/level180`. Копии — тоже только внутри
Узбекистана.

---

## Вариант Б. Обычный хостинг с панелью (Apache)

Подходит, если хостинг в Узбекистане и на нём есть PHP 8 с `pdo_sqlite`,
`mbstring` и `gd`.

1. Залейте папку в корень сайта. `.htaccess` уже закрывает всё, кроме
   `assets/` и `index.php`.
2. Скопируйте `config.local.example.php` в `config.local.php` и впишите
   значения (в панелях обычно нельзя задать переменные окружения).
3. В панели → Cron: каждые 5 минут `php /путь/к/сайту/tools/tick.php`.
4. Проверка — как в пункте 5 выше.

---

## Своя видеокомната (по желанию)

По умолчанию созвон сквада — видеочат в его группе Telegram. Если понадобится
своя комната, поставьте Jitsi Meet на тот же или соседний узбекский сервер и
задайте `CALLS_PROVIDER=jitsi`, `JITSI_URL=https://meet.ваш-домен`. Расписание,
ответы «приду» и напоминания не меняются.

## Что с Railway

Пока живых пользователей нет, начните на новом сервере с чистой базы. Railway
можно оставить как тестовую площадку: там всё работает, кроме фото.
