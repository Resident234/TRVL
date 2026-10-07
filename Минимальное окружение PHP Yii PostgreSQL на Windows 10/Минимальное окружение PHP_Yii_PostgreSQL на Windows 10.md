# Минимальное окружение PHP/Yii/PostgreSQL на Windows 10

> Это самостоятельная инструкция для отдельного Compose-примера со встроенным PHP-сервером, не инструкция по запуску текущего репозитория TRVL. Корневой проект использует PHP-FPM за nginx и другие порты. Для TRVL используйте корневой [README.md](../README.md) и [TECHNOLOGY_STACK.md](../TECHNOLOGY_STACK.md).

**Целевой состав:** WSL 2, Ubuntu, Docker Desktop, Docker Compose, PHP 8.4, Composer, Yii 2 и PostgreSQL 16.

**Пока не устанавливаются:** nginx, Redis, Memcached, Jenkins, Selenium и Phing.

## 1. Архитектура текущего этапа

На этом этапе достаточно двух контейнеров. Контейнер `app` содержит PHP CLI, Composer, необходимые PHP-расширения и исходный код Yii. Он запускает встроенный development-сервер PHP на порту 8080. Контейнер `postgres` содержит PostgreSQL 16 и хранит данные в Docker volume `pgdata`.

| Сервис | Назначение | Адрес из браузера/хоста | Адрес из контейнера `app` |
|---|---|---|---|
| `app` | PHP, Composer, Yii, встроенный HTTP-сервер | `http://localhost:8080` | — |
| `postgres` | PostgreSQL | `localhost:5432` | `postgres:5432` |

Важное правило Docker Compose: из одного контейнера нельзя подключаться к другому через `localhost`. Для PostgreSQL приложение должно использовать имя сервиса `postgres`. Встроенный сервер PHP выбран только для текущего этапа разработки; перед production его следует заменить на nginx + PHP-FPM или другой полноценный web server.

> **Примечание о Windows 10.** Обычная поддержка Windows 10 завершилась 14 октября 2025 года. Для рабочей или интернет-доступной машины предпочтительно перейти на поддерживаемую Windows или Linux. Описанная схема предназначена для локальной разработки на существующем Windows 10-хосте. [3]

## 2. Требования к компьютеру

Для WSL 2 нужен 64-битный процессор с поддержкой аппаратной виртуализации. В BIOS/UEFI должна быть включена Intel VT-x или AMD-V. Для команды `wsl --install` Microsoft указывает Windows 10 версии 2004, build 19041 или новее; Docker Desktop для Windows 10 с WSL 2 backend указывает Windows 10 22H2/build 19045, 64-битный CPU с SLAT, минимум 8 GB RAM и включённую виртуализацию [1] [2].

Практический минимум для этой урезанной конфигурации — 8 GB RAM, однако комфортнее использовать 16 GB. Диск должен иметь свободное место под Docker Desktop, Linux distribution, Docker images и PostgreSQL volume. Для разработки лучше выделить не менее 30–40 GB свободного места.

Проверьте версию Windows командой `winver`. В диспетчере задач откройте **Производительность → ЦП** и убедитесь, что в строке **Виртуализация** указано **Включено**.

## 3. Установка WSL 2 и Ubuntu

Откройте PowerShell **от имени администратора** и выполните:

```powershell
wsl --install -d Ubuntu
wsl --set-default-version 2
wsl --update
wsl --status
wsl -l -v
```

После перезагрузки Windows первый запуск Ubuntu попросит создать Linux-пользователя и пароль. Этот пользователь будет использоваться для работы с проектом. В выводе `wsl -l -v` у Ubuntu в колонке `VERSION` должно быть значение `2`.

Если Ubuntu отображается с версией `1`, переключите её:

```powershell
wsl --set-version Ubuntu 2
```

Microsoft указывает, что новые Linux-дистрибутивы, установленные через `wsl --install`, используют WSL 2 по умолчанию, а командой `wsl -l -v` можно проверить и версию конкретного дистрибутива [1].

Если команда `wsl --install` недоступна, включите компоненты вручную в PowerShell администратора:

```powershell
dism.exe /online /enable-feature /featurename:Microsoft-Windows-Subsystem-Linux /all /norestart
dism.exe /online /enable-feature /featurename:VirtualMachinePlatform /all /norestart
```

Перезагрузите Windows, установите Ubuntu из Microsoft Store или командой `wsl --install -d Ubuntu`, затем выполните:

```powershell
wsl --set-default-version 2
wsl --update
```

В Ubuntu обновите базовые пакеты:

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y ca-certificates curl git unzip
```

## 4. Установка Docker Desktop

Скачайте Docker Desktop с [официальной страницы Docker](https://docs.docker.com/desktop/setup/install/windows-install/). В установщике выберите использование WSL 2 backend, если такой выбор отображается. Docker указывает WSL 2 как основной backend для большинства пользователей; Hyper-V является альтернативой преимущественно для Pro/Enterprise и не нужен для выбранной схемы [2].

После установки запустите Docker Desktop и дождитесь, пока он покажет, что Docker запущен. Откройте **Settings → Resources → WSL Integration** и включите интеграцию с установленным дистрибутивом Ubuntu. Нажмите **Apply & Restart**.

В Ubuntu проверьте интеграцию:

```bash
docker version
docker compose version
docker run --rm hello-world
```

Ожидаемый результат: `docker version` показывает Client и Server, `docker compose version` выводит версию Compose, а `hello-world` завершается сообщением об успешной установке.

Если в Ubuntu появляется `docker: command not found`, включите Ubuntu в **Settings → Resources → WSL Integration**. Если появляется ошибка подключения к daemon, запустите Docker Desktop и повторите проверку.

## 5. Где хранить проект в WSL

Создайте каталог проекта в Linux-файловой системе WSL, а не под `/mnt/c`. Для Docker bind mount это обычно быстрее и стабильнее:

```bash
mkdir -p ~/src/yii-dev
cd ~/src/yii-dev
```

Рабочая структура будет такой:

```text
yii-dev/
├── app/                    # исходный код Yii-приложения
├── config/
│   └── db.php.example      # пример настройки Yii DB
├── docker/
│   └── php/
│       ├── Dockerfile
│       └── php.ini
├── docker-compose.yml
├── .env.example
├── .gitignore
└── README.md
```

Если репозиторий уже существует, клонируйте его в `~/src/yii-dev` и используйте его каталог как корень. Если проект будет храниться в Git, подключите его обычными командами `git init`, `git remote add` или `git clone`.

Чтобы открыть проект в Windows Explorer, из Ubuntu можно выполнить:

```bash
explorer.exe .
```

## 6. Получение файлов минимального комплекта

В приложенном архиве уже лежат следующие файлы:

| Файл | Назначение |
|---|---|
| `docker-compose.yml` | Два сервиса: PHP/Yii и PostgreSQL |
| `docker/php/Dockerfile` | PHP 8.4 CLI, Composer и расширение `pdo_pgsql` |
| `docker/php/php.ini` | Настройки локального PHP |
| `config/db.php.example` | Конфигурация Yii для PostgreSQL через `getenv()` |
| `.env.example` | Шаблон переменных Compose |
| `.gitignore` | Исключает `.env`, `vendor`, runtime и assets |
| `ARCHITECTURE.md` | Краткое описание архитектуры |

Распакуйте содержимое архива в `~/src/yii-dev`. Если у вас уже есть Yii-репозиторий, копируйте только инфраструктурные файлы и не заменяйте существующие `composer.json`, `config/db.php` и другие файлы приложения без предварительного сравнения.

## 7. Настройка `.env`

В корне проекта создайте локальный файл переменных:

```bash
cp .env.example .env
```

Откройте его:

```bash
nano .env
```

Минимальное содержимое:

```dotenv
COMPOSE_PROJECT_NAME=yii-dev
APP_UID=1000
APP_GID=1000

YII_ENV=dev
YII_DEBUG=1

POSTGRES_DB=yii_dev
POSTGRES_USER=yii
POSTGRES_PASSWORD=change-me-now
```

Замените `POSTGRES_PASSWORD` на собственный пароль, например:

```dotenv
POSTGRES_PASSWORD=local-password-please-change
```

Не коммитьте `.env` в Git. Файл `.env.example` можно хранить в репозитории, поскольку он содержит только примерные значения.

Проверьте UID и GID текущего Linux-пользователя:

```bash
id -u
id -g
```

Для стандартного первого пользователя Ubuntu это обычно `1000` и `1000`. Если значения отличаются, укажите их в `.env`:

```dotenv
APP_UID=1001
APP_GID=1001
```

Это помогает избежать создания файлов от имени root в каталоге проекта.

## 8. Создание нового Yii 2 приложения

Если приложение уже существует, пропустите этот раздел и перейдите к разделу 9.

Выполните сборку PHP-образа:

```bash
cd ~/src/yii-dev
docker compose build app
```

Создайте Yii basic application внутри каталога `app`:

```bash
mkdir -p app
docker compose run --rm app composer create-project --prefer-dist yiisoft/yii2-app-basic .
```

Команда создаёт Yii-приложение в `/var/www/html`, который смонтирован на локальный каталог `./app`. После завершения проверьте наличие входного файла:

```bash
ls -la app/web/index.php
ls -la app/yii
```

Если Composer сообщает, что каталог не пуст, проверьте, не лежит ли там старый проект. Не удаляйте его автоматически: для существующего проекта используйте `composer install`, а не `create-project`.

## 9. Подключение существующего Yii-проекта

Если у вас уже есть Yii 2 application, разместите его содержимое в `app/`. В корне `app/` должны находиться как минимум `composer.json`, `web/index.php` и, для console-команд, файл `yii`.

Выполните:

```bash
cd ~/src/yii-dev
docker compose build app
docker compose run --rm app composer install --no-interaction --prefer-dist
```

Команда `composer install` использует `composer.lock`, если он есть. В CI и на повторных установках следует использовать именно `composer install`; `composer update` предназначен для осознанного обновления зависимостей и не должен выполняться автоматически.

Yii 2 требует PHP 7.4 или новее. В этой инструкции выбран PHP 8.4, поэтому новый проект должен быть совместим с ним. Если существующее приложение старое и рассчитано на PHP 7.x, сначала проверьте его `composer.json` и зависимости. [4]

## 10. Настройка Yii для PostgreSQL

После создания Yii basic application файл настройки базы обычно находится в `app/config/db.php`. Скопируйте пример:

```bash
cp config/db.php.example app/config/db.php
```

Содержимое должно быть примерно таким:

```php
<?php

return [
    'class' => yii\db\Connection::class,
    'dsn' => getenv('DB_DSN') ?: 'pgsql:host=postgres;port=5432;dbname=yii_dev',
    'username' => getenv('DB_USERNAME') ?: 'yii',
    'password' => getenv('DB_PASSWORD') ?: 'change-me-now',
    'charset' => 'utf8',
];
```

Внутри контейнера Compose автоматически передаёт приложению следующие переменные:

```text
DB_DSN=pgsql:host=postgres;port=5432;dbname=yii_dev
DB_USERNAME=yii
DB_PASSWORD=<значение POSTGRES_PASSWORD из .env>
```

Здесь `postgres` — имя Compose-сервиса. Не заменяйте его на `localhost`: `localhost` внутри контейнера `app` указывает на сам контейнер `app`, а не на PostgreSQL.

Если проект использует другой формат конфигурации, перенесите те же значения в существующий config-файл. В production пароль не должен быть зашит в PHP-файл или Git.

## 11. Запуск PostgreSQL и приложения

Запустите оба сервиса в фоне:

```bash
docker compose up -d
```

Проверьте состояние:

```bash
docker compose ps
```

PostgreSQL должен перейти в состояние `healthy`. Посмотреть логи можно так:

```bash
docker compose logs -f postgres
```

Для выхода из просмотра логов нажмите `Ctrl+C`; контейнеры при этом не остановятся.

После запуска откройте в браузере Windows:

[http://localhost:8080](http://localhost:8080)

Должна открыться стартовая страница Yii. Встроенный сервер PHP слушает `0.0.0.0:8080` внутри контейнера, а Docker публикует его только на `127.0.0.1:8080` хоста.

Проверить, что порт занят другим процессом Windows, можно в PowerShell:

```powershell
netstat -ano | findstr :8080
```

Если порт 8080 занят, измените только левую часть mapping в `docker-compose.yml`, например:

```yaml
ports:
  - "127.0.0.1:8090:8080"
```

После этого приложение будет доступно по адресу [http://localhost:8090](http://localhost:8090). Правая часть `8080` должна остаться прежней, поскольку это порт PHP-сервера внутри контейнера.

## 12. Применение Yii-миграций

После запуска сервисов выполните миграции:

```bash
docker compose exec app php yii migrate --interactive=0
```

Если команда выполняется до готовности PostgreSQL, Compose обычно ждёт `healthcheck` перед запуском `app`, но при первом старте лучше проверить состояние:

```bash
docker compose ps postgres
```

Можно проверить подключение непосредственно через PHP:

```bash
docker compose exec app php -r '$pdo = new PDO(getenv("DB_DSN"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo $pdo->query("SELECT version()\n")->fetchColumn(), PHP_EOL;'
```

Ожидаемый результат — строка с версией PostgreSQL.

## 13. Проверка PHP и расширений

Проверьте версию PHP:

```bash
docker compose exec app php -v
```

Проверьте обязательные расширения:

```bash
docker compose exec app php -m | grep -E 'intl|mbstring|opcache|PDO|pdo_pgsql|zip'
```

Минимально важны `PDO`, `pdo_pgsql`, `mbstring` и `intl`. Composer должен быть доступен:

```bash
docker compose exec app composer --version
```

Проверка Yii console:

```bash
docker compose exec app php yii
```

Если выводит список доступных команд Yii, приложение и PHP CLI настроены правильно.

## 14. Остановка, перезапуск и очистка

Остановить контейнеры без удаления данных PostgreSQL:

```bash
docker compose stop
```

Запустить их снова:

```bash
docker compose start
```

Удалить контейнеры и сеть, но сохранить volume с PostgreSQL:

```bash
docker compose down
```

Полностью удалить контейнеры и данные PostgreSQL:

```bash
docker compose down --volumes
```

Последняя команда необратимо удаляет локальную базу. Перед ней сделайте дамп:

```bash
docker compose exec -T postgres pg_dump -U "$(grep '^POSTGRES_USER=' .env | cut -d= -f2-)" \
  "$(grep '^POSTGRES_DB=' .env | cut -d= -f2-)" > backup.sql
```

Более простой вариант — выполнить `pg_dump` с фактическими значениями пользователя и базы из `.env`:

```bash
docker compose exec -T postgres pg_dump -U yii yii_dev > backup.sql
```

Восстановление в работающий контейнер:

```bash
cat backup.sql | docker compose exec -T postgres psql -U yii -d yii_dev
```

## 15. Повседневный рабочий цикл

Обычная работа выглядит так:

```bash
cd ~/src/yii-dev
docker compose up -d
docker compose exec app composer install --no-interaction --prefer-dist
# редактирование кода в app/
docker compose exec app php yii migrate --interactive=0
# проверка в браузере: http://localhost:8080
docker compose logs --tail=100 app postgres
```

После изменения только PHP-кода пересборка образа обычно не нужна, потому что `./app` смонтирован внутрь контейнера. После изменения `Dockerfile` или `php.ini` нужно пересобрать PHP-образ:

```bash
docker compose build app
docker compose up -d
```

После изменения `docker-compose.yml` пересоздайте сервисы:

```bash
docker compose up -d --force-recreate
```

## 16. Диагностика типовых проблем

| Симптом | Что проверить | Решение |
|---|---|---|
| `wsl --install` не найден | Версию Windows и build | Обновить Windows 10 до 22H2; при необходимости включить WSL-компоненты вручную |
| В WSL нет `docker` | WSL Integration | Docker Desktop → Settings → Resources → WSL Integration → включить Ubuntu |
| `Cannot connect to the Docker daemon` | Запущен ли Docker Desktop | Запустить Docker Desktop и дождаться готовности |
| `bind: address already in use` | Порты 8080 или 5432 | Изменить левый host-порт в Compose или остановить конфликтующий процесс |
| Yii показывает ошибку подключения к БД | `app/config/db.php` и переменные | Использовать host `postgres`, проверить `docker compose ps postgres` и пароль в `.env` |
| `could not find driver` | `pdo_pgsql` в PHP-образе | Выполнить `docker compose build --no-cache app` и проверить `php -m` |
| Файлы создаются от root | UID/GID в `.env` | Сверить `id -u`, `id -g`, изменить `APP_UID`/`APP_GID`, пересобрать образ |
| Composer не может записать `vendor` | Права каталога `app` | Проверить владельца каталога и UID/GID; не запускать Composer через `sudo` внутри WSL |
| База потерялась после очистки | Выполнялась `docker compose down --volumes` | Восстановить из `backup.sql`; volume удаляется намеренно |
| Страница не открывается после изменения PHP | Контейнер и логи | `docker compose ps`, `docker compose logs app`; при изменении Dockerfile выполнить rebuild |
| Медленная работа проекта | Проект находится в `/mnt/c` | Перенести исходники в `~/src` внутри WSL |

## 17. Безопасность текущего варианта

В Compose PostgreSQL публикуется только на `127.0.0.1:5432`, поэтому он не должен быть доступен из локальной сети через внешний интерфейс Windows. Это удобно для разработки, но не заменяет firewall и не является production-защитой.

Не публикуйте `.env`, не отправляйте его в Git и не вставляйте пароль PostgreSQL в `docker-compose.yml`. Для локальной разработки пароль хранится в `.env`; позднее, когда появится Jenkins, пароль нужно будет передавать через Jenkins Credentials или другой secret store.

Встроенный сервер PHP предназначен для development. Он не рассчитан на production-нагрузку, TLS, полноценное управление worker-процессами и production-безопасность. Когда проект будет готов к следующему этапу, добавьте nginx и PHP-FPM отдельным сервисом, не меняя PostgreSQL-сервис и структуру переменных подключения.

## 18. Добавление компонентов позже

Текущая структура специально оставляет место для дальнейшего расширения. Позже можно добавить:

| Следующий компонент | Как добавить |
|---|---|
| nginx + PHP-FPM | Перейти с `php:8.4-cli` на `php:8.4-fpm`, добавить сервис nginx и FastCGI-конфигурацию |
| Phing | Добавить `phing/phing` в `app/composer.json` и `build.xml` |
| PHPUnit | Добавить `phpunit/phpunit` в `require-dev` и `phpunit.xml` |
| Redis | Добавить сервис Redis и `yiisoft/yii2-redis` |
| Memcached | Добавить сервис Memcached, PHP PECL extension и Yii cache component |
| Selenium | Добавить Selenium container и acceptance-test stage |
| Jenkins | Добавить CI после того, как локальный `docker compose up`, Yii и миграции стабильно работают |

Не добавляйте все компоненты заранее: сначала добейтесь воспроизводимого запуска `http://localhost:8080`, успешного `php yii migrate` и корректного сохранения данных в PostgreSQL.

## 19. Минимальный критерий готовности

Окружение считается корректно развернутым, если последовательно выполняются команды:

```bash
cd ~/src/yii-dev
docker compose config
docker compose build app
docker compose up -d
docker compose ps
docker compose exec app composer --version
docker compose exec app php -m | grep pdo_pgsql
docker compose exec app php yii migrate --interactive=0
```

После этого в браузере открывается [http://localhost:8080](http://localhost:8080), а повторный запуск `docker compose down` и `docker compose up -d` сохраняет данные PostgreSQL в named volume `pgdata`.

## References

[1]: https://learn.microsoft.com/en-us/windows/wsl/install "Microsoft Learn: Install WSL"
[2]: https://docs.docker.com/desktop/setup/install/windows-install/ "Docker Docs: Install Docker Desktop on Windows"
[3]: https://support.microsoft.com/en-us/windows/deployment/updates-lifecycle/windows-10-support-has-ended-10-14-2025 "Microsoft Support: Windows 10 support has ended"
[4]: https://www.yiiframework.com/doc/guide/2.0/en/intro-yii "Yii 2 Guide: About Yii"
[5]: https://www.postgresql.org/download/windows/ "PostgreSQL: Windows installers"
