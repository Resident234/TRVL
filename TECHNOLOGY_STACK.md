# Технологический стек TRVL

**Ядро и фреймворки**
- **PHP 8.2+** — язык приложения
- **Yii 2** — фреймворк приложения (`yiisoft/yii2`, `yii2-bootstrap5`, `yii2-symfonymailer`)
- **Composer** — менеджер зависимостей

**Сборка и окружение**
- **Docker / Docker Compose** — основной способ запуска: образы `docker/php` (PHP-FPM), `docker/php-cli` (консольные парсеры и очереди), `nginx:stable-alpine`, `postgres:16-alpine`
- **nginx** — веб-сервер (порт `127.0.0.1:8081` по умолчанию)

**База данных**
- **PostgreSQL 16** — основная СУБД (`yii_dev` / `yii_test`), миграции через `yii migrate`

**Тестирование**
- **Codeception 5** — фреймворк тестов (Unit, Functional, Acceptance); настройки в `app/codeception.yml`, `tests/Unit.suite.yml`, `tests/Functional.suite.yml`, `tests/Acceptance.suite.yml`. Acceptance-тесты (`app/tests/*Cest.php`) формально включены, но в Docker не поднимаются (нет WebDriver/Selenium, нет функционального окружения), поэтому на практике покрыт только Unit-набор. CAPTCHA требует расширение GD/Imagick
- **phpstan** — статический анализ (`composer static`)
- **phpcs (PHP_CodeSniffer)** — проверка стиля (`yiisoft/yii2-coding-standards`, `composer cs`)

**Интеграции и сервисы**
- **nutgram/nutgram ^4.50** — клиент Telegram Bot API
- phpBB-парсеры форума `forum.awd.ru` (CurlHttpClient)
- Memcached / Redis / Phing / Jenkins / Selenium **в проект не входят** и в документе больше не фигурируют.

**Архитектура приложения**
- Слои: **Framework / infrastructure** (Yii, зависимости) → **Shared** (модули `Forum`, `Gallery`, `Publications`, `Settings`, `Telegram`) → **Application** (`app/`: контроллеры, console-команды, конфигурация)
- Периодические задачи выполняются в контейнерах `parser` и `telegram` через встроенный cron (графики — переменные `*_CRON_SCHEDULE` в `.env.example`)
