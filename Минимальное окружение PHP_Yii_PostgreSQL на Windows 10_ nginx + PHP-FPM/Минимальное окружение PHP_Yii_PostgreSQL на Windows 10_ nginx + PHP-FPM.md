# Минимальное окружение PHP/Yii/PostgreSQL на Windows 10: nginx + PHP-FPM

> Это самостоятельная инструкция для отдельного минимального Compose-примера, не инструкция по запуску текущего репозитория TRVL. В частности, порты из неё отличаются от корневого Compose. Для TRVL используйте корневой [README.md](../README.md) и [TECHNOLOGY_STACK.md](../TECHNOLOGY_STACK.md).

**Целевой состав текущего этапа:** WSL 2, Ubuntu, Docker Desktop, Docker Compose, nginx, PHP 8.4-FPM, Composer, Yii 2 и PostgreSQL 16.

**Пока не устанавливаются:** Redis, Memcached, Jenkins, Selenium и Phing.

## 1. Итоговая архитектура

В текущей версии используются три Docker-сервиса. Nginx является единственной точкой входа для браузера. Он раздаёт статические файлы Yii из каталога `app/web`, а PHP-запросы передаёт по FastCGI в контейнер PHP-FPM. PHP-FPM не публикуется на Windows-порт и доступен только внутри Compose-сети. PostgreSQL также работает отдельным контейнером и сохраняет данные в named volume.

| Сервис | Назначение | Адрес на Windows | Адрес внутри Compose-сети |
|---|---|---|---|
| `nginx` | HTTP-вход, статика Yii, FastCGI proxy | `http://localhost:8080` | `nginx:80` |
| `app` | PHP 8.4-FPM, Composer, Yii console | Не публикуется | `app:9000` |
| `postgres` | PostgreSQL 16 | `localhost:5432` | `postgres:5432` |

Nginx и PHP-FPM используют один и тот же путь `/var/www/html`: для nginx он монтируется только для чтения, для PHP-FPM — с правом записи. Document root — `/var/www/html/web`, как в Yii basic application. Nginx передаёт PHP-запросы на `app:9000`; `localhost:9000` использовать нельзя, поскольку внутри контейнера это сам контейнер nginx.

> Встроенный PHP development-сервер в этой версии больше не используется. Связка nginx + PHP-FPM лучше соответствует типовой Linux-конфигурации и сразу позволяет проверить реальную маршрутизацию FastCGI. Для production всё равно потребуются дополнительные настройки безопасности, TLS, лимитов и управления процессами.

> **Примечание о Windows 10.** Обычная поддержка Windows 10 завершилась 14 октября 2025 года. Для рабочей или интернет-доступной машины предпочтительно перейти на поддерживаемую Windows или Linux. Данная инструкция предназначена для локальной разработки на существующем Windows 10-хосте.

## 2. Требования к компьютеру

Для WSL 2 нужен 64-битный процессор с поддержкой аппаратной виртуализации. В BIOS/UEFI должна быть включена Intel VT-x или AMD-V. В диспетчере задач откройте **Производительность → ЦП** и убедитесь, что в строке **Виртуализация** указано **Включено**.

Для команды `wsl --install` Microsoft указывает Windows 10 версии 2004, build 19041 или новее. Для Docker Desktop с WSL 2 backend актуальная документация указывает Windows 10 22H2/build 19045, 64-битный CPU с SLAT, минимум 8 GB RAM и включённую аппаратную виртуализацию [1] [2]. Для nginx, PHP-FPM и PostgreSQL комфортнее иметь 16 GB RAM, но минимальная конфигурация может работать на 8 GB при небольшом проекте.

Проверьте версию Windows командой `winver`. Держите свободными минимум 30–40 GB диска под Docker Desktop, Ubuntu, образы и PostgreSQL volume.

## 3. Установка WSL 2 и Ubuntu

Откройте PowerShell **от имени администратора** и выполните:

```powershell
wsl --install -d Ubuntu
wsl --set-default-version 2
wsl --update
wsl --status
wsl -l -v
```

После перезагрузки Windows первый запуск Ubuntu попросит создать Linux-пользователя и пароль. В выводе `wsl -l -v` у Ubuntu в колонке `VERSION` должно быть значение `2`.

Если Ubuntu отображается с версией `1`, переключите её:

```powershell
wsl --set-version Ubuntu 2
```

Microsoft указывает, что новые Linux-дистрибутивы, установленные через `wsl --install`, используют WSL 2 по умолчанию, а `wsl -l -v` показывает версию каждого дистрибутива [1].

Если команда `wsl --install` недоступна, включите компоненты вручную в PowerShell администратора:

```powershell
dism.exe /online /enable-feature /featurename:Microsoft-Windows-Subsystem-Linux /all /norestart
dism.exe /online /enable-feature /featurename:VirtualMachinePlatform /all /norestart
```

Перезагрузите Windows и повторите установку Ubuntu. В Ubuntu обновите базовые пакеты:

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y ca-certificates curl git unzip
```

## 4. Установка Docker Desktop

Скачайте Docker Desktop с [официальной страницы Docker](https://docs.docker.com/desktop/setup/install/windows-install/). В установщике выберите использование WSL 2 backend. Hyper-V для выбранной схемы не нужен.

После установки запустите Docker Desktop и дождитесь, пока он покажет, что Docker запущен. Откройте **Settings → Resources → WSL Integration**, включите интеграцию с установленным дистрибутивом Ubuntu и нажмите **Apply & Restart**.

В Ubuntu проверьте интеграцию:

```bash
docker version
docker compose version
docker run --rm hello-world
```

Ожидаемый результат: `docker version` показывает Client и Server, `docker compose version` выводит версию Compose, а `hello-world` завершается сообщением об успешном запуске.

Если в Ubuntu появляется `docker: command not found`, включите Ubuntu в **Settings → Resources → WSL Integration**. Если появляется ошибка подключения к daemon, запустите Docker Desktop и повторите проверку.

## 5. Размещение проекта в WSL

Храните проект в Linux-файловой системе WSL, а не в `/mnt/c`. Для bind mount и большого количества PHP-файлов это обычно быстрее:

```bash
mkdir -p ~/src/yii-dev
cd ~/src/yii-dev
```

После распаковки комплекта структура должна выглядеть так:

```text
yii-dev/
├── app/                    # исходный код Yii-приложения
├── config/
│   └── db.php.example      # пример подключения Yii к PostgreSQL
├── docker/
│   ├── nginx/
│   │   └── default.conf    # virtual host nginx и FastCGI
│   └── php/
│       ├── Dockerfile      # PHP 8.4-FPM и расширения
│       ├── php.ini         # настройки PHP
│       └── www.conf        # PHP-FPM pool
├── docker-compose.yml
├── .env.example
├── .gitignore
├── ARCHITECTURE.md
└── README.md
```

Если проект уже находится в Git, клонируйте его в `~/src/yii-dev`. Если нужно открыть каталог в Windows Explorer, из Ubuntu выполните:

```bash
explorer.exe .
```

## 6. Распаковка приложенного комплекта

В приложенном архиве находятся готовые файлы `docker-compose.yml`, `docker/nginx/default.conf`, `docker/php/Dockerfile`, `docker/php/php.ini`, `docker/php/www.conf`, `.env.example`, `.gitignore`, `config/db.php.example`, `ARCHITECTURE.md` и эта инструкция.

Если у вас уже есть Yii-репозиторий, копируйте инфраструктурные файлы в его корень, но не заменяйте существующий `composer.json`, `app/config/db.php` или настройки приложения без предварительного сравнения.

## 7. Настройка переменных окружения

В корне Compose-проекта создайте локальный `.env`:

```bash
cp .env.example .env
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
POSTGRES_PASSWORD=local-password-please-change
```

Значения `APP_UID` и `APP_GID` должны соответствовать текущему пользователю Ubuntu:

```bash
id -u
id -g
```

Для стандартного первого пользователя Ubuntu это обычно `1000` и `1000`. Если команды выводят другие значения, внесите их в `.env`. Эти параметры используются при сборке PHP-образа, чтобы Composer и PHP-FPM создавали файлы не от root.

Файл `.env` не добавляйте в Git. Файл `.env.example` можно коммитить как шаблон без реальных секретов.

## 8. Создание нового Yii-приложения

Если Yii-приложение уже существует, перейдите к разделу 9.

Сначала соберите PHP-FPM-образ:

```bash
cd ~/src/yii-dev
docker compose build app
```

Создайте каталог исходного приложения и запустите Composer от имени `appuser`:

```bash
mkdir -p app
docker compose run --rm --user appuser app \
  composer create-project --prefer-dist yiisoft/yii2-app-basic .
```

Команда создаёт Yii basic application в каталоге `app/`. После завершения проверьте:

```bash
ls -la app/web/index.php
ls -la app/yii
```

Если Composer сообщает, что каталог не пуст, не удаляйте его автоматически. Это означает, что в `app/` уже находится проект или остатки предыдущей установки.

## 9. Подключение существующего Yii-проекта

Для существующего приложения в `app/` должны находиться как минимум `composer.json`, `web/index.php` и, если используются console-команды, файл `yii`.

Соберите образ и установите зависимости от имени `appuser`:

```bash
cd ~/src/yii-dev
docker compose build app
docker compose run --rm --user appuser app \
  composer install --no-interaction --prefer-dist
```

Если в проекте есть `composer.lock`, команда использует зафиксированные версии. `composer update` не запускайте автоматически: это отдельная операция обновления зависимостей.

Yii 2 требует PHP 7.4 или новее. В данной инструкции используется PHP 8.4, поэтому существующее приложение и его зависимости должны быть совместимы с PHP 8.4 [4].

## 10. Настройка подключения Yii к PostgreSQL

После создания Yii basic application скопируйте пример настройки базы:

```bash
cp config/db.php.example app/config/db.php
```

Файл `app/config/db.php` должен содержать примерно следующее:

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

Compose передаёт в PHP-контейнер такие значения:

```text
DB_DSN=pgsql:host=postgres;port=5432;dbname=yii_dev
DB_USERNAME=yii
DB_PASSWORD=<значение POSTGRES_PASSWORD из .env>
```

Критично использовать host `postgres`, а не `localhost`. В Compose DNS имя `postgres` указывает на контейнер базы данных. Nginx использует отдельное имя `app` для PHP-FPM и передаёт запросы на `app:9000`.

## 11. Запуск nginx, PHP-FPM и PostgreSQL

Запустите все три сервиса в фоне:

```bash
docker compose up -d
```

Проверьте состояние:

```bash
docker compose ps
```

Контейнер `postgres` должен перейти в состояние `healthy`. Сервисы `app` и `nginx` должны иметь статус `running`.

Посмотрите логи при необходимости:

```bash
docker compose logs --tail=200 postgres
docker compose logs --tail=200 app
docker compose logs --tail=200 nginx
```

Откройте в браузере Windows:

[http://localhost:8080](http://localhost:8080)

Путь запроса выглядит так:

```text
браузер Windows
    → 127.0.0.1:8080
    → nginx:80
    → PHP-FPM app:9000
    → Yii app/web/index.php
    → PostgreSQL postgres:5432
```

Конфигурация nginx использует `root /var/www/html/web`, `try_files` для маршрутизации Yii и `fastcgi_pass app:9000`. Модуль nginx FastCGI предназначен именно для передачи HTTP-запросов FastCGI-серверу, которым в данной схеме является PHP-FPM [6].

## 12. Применение Yii-миграций

После запуска контейнеров выполните миграции от имени `appuser`:

```bash
docker compose exec -u appuser app php yii migrate --interactive=0
```

Если приложение ещё не содержит миграций, команда может сообщить, что миграции отсутствуют. Это не является ошибкой окружения.

Проверить подключение к PostgreSQL можно напрямую через PDO:

```bash
docker compose exec -u appuser app php -r \
  '$pdo = new PDO(getenv("DB_DSN"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo $pdo->query("SELECT version()\n")->fetchColumn(), PHP_EOL;'
```

Ожидаемый результат — строка с версией PostgreSQL.

## 13. Проверка PHP-FPM, nginx и расширений

Проверьте PHP и Composer:

```bash
docker compose exec app php -v
docker compose exec app composer --version
```

Проверьте расширения:

```bash
docker compose exec app php -m | grep -E 'intl|mbstring|opcache|PDO|pdo_pgsql|zip'
```

Минимально важны `PDO`, `pdo_pgsql`, `mbstring` и `intl`. Проверить синтаксис FPM-конфигурации можно так:

```bash
docker compose exec app php-fpm -tt
```

Проверить nginx внутри контейнера:

```bash
docker compose exec nginx nginx -t
```

Проверить HTTP-ответ с WSL-хоста:

```bash
curl -I http://localhost:8080
```

Для Yii basic application ожидается HTTP-ответ `200` или иной ответ приложения, но не `502 Bad Gateway`. Если запрос к существующему маршруту возвращает 502, сначала проверьте `app` и FPM-логи.

## 14. Права на runtime и assets

PHP-FPM pool в `docker/php/www.conf` настроен на пользователя `appuser`. Это позволяет Yii записывать runtime-данные и assets в монтируемый каталог проекта. UID/GID `appuser` задаются аргументами сборки из `.env`.

После изменения `APP_UID` или `APP_GID` PHP-образ нужно пересобрать:

```bash
docker compose build --no-cache app
docker compose up -d --force-recreate app nginx
```

Не запускайте Composer и Yii-миграции через `sudo` внутри WSL. Иначе часть файлов будет создана от root и последующие команды могут получить ошибку доступа.

## 15. Остановка, перезапуск и очистка

Остановить контейнеры без удаления данных PostgreSQL:

```bash
docker compose stop
```

Запустить их снова:

```bash
docker compose start
```

Удалить контейнеры и сеть, сохранив PostgreSQL volume:

```bash
docker compose down
```

Удалить контейнеры и данные PostgreSQL:

```bash
docker compose down --volumes
```

Последняя команда необратимо удаляет локальную базу. Перед ней создайте дамп:

```bash
docker compose exec -T postgres pg_dump -U yii yii_dev > backup.sql
```

Восстановить дамп в запущенный PostgreSQL можно так:

```bash
cat backup.sql | docker compose exec -T postgres psql -U yii -d yii_dev
```

## 16. Повседневный рабочий цикл

После первоначальной настройки типичный цикл выглядит так:

```bash
cd ~/src/yii-dev
docker compose up -d
docker compose exec -u appuser app composer install --no-interaction --prefer-dist
# редактирование кода в app/
docker compose exec -u appuser app php yii migrate --interactive=0
# проверка в браузере: http://localhost:8080
docker compose logs --tail=100 app nginx postgres
```

После изменения PHP-кода пересборка образа обычно не нужна: каталог `./app` монтируется в контейнер. После изменения `Dockerfile`, `php.ini` или `www.conf` выполните:

```bash
docker compose build app
docker compose up -d --force-recreate app nginx
```

После изменения `docker-compose.yml` выполните:

```bash
docker compose up -d --force-recreate
```

## 17. Диагностика типовых проблем

| Симптом | Что проверить | Решение |
|---|---|---|
| `wsl --install` не найден | Версию Windows и build | Обновить Windows 10 до 22H2; при необходимости включить WSL-компоненты вручную |
| В WSL нет `docker` | WSL Integration | Docker Desktop → Settings → Resources → WSL Integration → включить Ubuntu |
| `Cannot connect to the Docker daemon` | Запущен ли Docker Desktop | Запустить Docker Desktop и дождаться готовности |
| `bind: address already in use` на 8080 | Порт занят Windows-процессом | Проверить `netstat -ano | findstr :8080`; изменить левую часть mapping в Compose, например на `8090:80` |
| Nginx возвращает `502 Bad Gateway` | Состояние `app`, FPM и upstream | Выполнить `docker compose ps`, `docker compose logs app nginx`; upstream должен быть `app:9000` |
| `Primary script unknown` | Совпадают ли пути в nginx и PHP-контейнере | В обоих контейнерах код должен находиться в `/var/www/html`, document root — `/var/www/html/web` |
| Nginx возвращает `403` или `404` | Есть ли `app/web/index.php` | Проверить `ls -la app/web`; убедиться, что проект смонтирован в `./app` |
| `could not find driver` | Наличие `pdo_pgsql` | Выполнить `docker compose build --no-cache app` и проверить `php -m` |
| Ошибка Yii подключения к БД | `app/config/db.php`, host и пароль | Использовать host `postgres`, проверить `docker compose ps postgres` и `.env` |
| Файлы создаются от root | `APP_UID`, `APP_GID` и способ запуска | Сверить `id -u`, `id -g`, пересобрать образ; использовать `--user appuser`/`-u appuser` |
| Composer не может записать `vendor` | Права каталога `app` | Не использовать `sudo`; проверить UID/GID и владельца файлов |
| База потерялась после очистки | Выполнялась `docker compose down --volumes` | Восстановить из `backup.sql`; volume удаляется намеренно |
| Медленная работа проекта | Проект находится в `/mnt/c` | Перенести исходники в `~/src` внутри WSL |
| Nginx не стартует после изменения конфигурации | Синтаксис nginx | Выполнить `docker compose exec nginx nginx -t`, затем проверить `docker compose logs nginx` |

## 18. Безопасность текущего варианта

В Compose nginx и PostgreSQL публикуются только на `127.0.0.1`: nginx — на `127.0.0.1:8080`, PostgreSQL — на `127.0.0.1:5432`. PHP-FPM не публикуется наружу. Это ограничивает доступ локальным компьютером, но не заменяет firewall и не является production-защитой.

Не коммитьте `.env`, не записывайте реальные пароли в `docker-compose.yml` или PHP-код и не открывайте PostgreSQL на `0.0.0.0` без необходимости. Для общего стенда потребуются secrets management, отдельные учётные записи, TLS и сетевые правила.

В development включены `display_errors=On`, `YII_DEBUG=1` и `YII_ENV=dev`. Перед production эти параметры нужно отключить или заменить production-настройками.

## 19. Что добавить следующим этапом

Когда текущая схема стабильно запускается, компоненты можно добавлять по одному:

| Следующий компонент | Что изменится |
|---|---|
| Phing | Добавится `phing/phing` и `build.xml` в приложение |
| PHPUnit | Добавятся `phpunit/phpunit`, `phpunit.xml` и unit-тесты |
| Redis | Добавится Compose-сервис Redis и Yii Redis component |
| Memcached | Добавится Compose-сервис Memcached, PHP extension и Yii cache component |
| Selenium | Добавится browser-test container и acceptance tests |
| Jenkins | CI будет запускать Compose, Phing/PHPUnit и Selenium на отдельном агенте |

На следующем этапе nginx и PHP-FPM уже не потребуется переделывать: они являются базовой схемой для последующего добавления PHPUnit, Phing, Jenkins и Selenium.

## 20. Минимальный критерий готовности

Окружение считается корректно развернутым, если выполняются команды:

```bash
cd ~/src/yii-dev
docker compose config
docker compose build app
docker compose up -d
docker compose ps
docker compose exec nginx nginx -t
docker compose exec app php-fpm -tt
docker compose exec app composer --version
docker compose exec app php -m | grep pdo_pgsql
docker compose exec -u appuser app php yii migrate --interactive=0
curl -I http://localhost:8080
```

После этого в браузере открывается [http://localhost:8080](http://localhost:8080), Yii выполняет PHP-запрос через PHP-FPM, а повторный `docker compose down` и `docker compose up -d` сохраняет данные PostgreSQL в named volume `pgdata`.

## References

[1]: https://learn.microsoft.com/en-us/windows/wsl/install "Microsoft Learn: Install WSL"
[2]: https://docs.docker.com/desktop/setup/install/windows-install/ "Docker Docs: Install Docker Desktop on Windows"
[3]: https://support.microsoft.com/en-us/windows/deployment/updates-lifecycle/windows-10-support-has-ended-10-14-2025 "Microsoft Support: Windows 10 support has ended"
[4]: https://www.yiiframework.com/doc/guide/2.0/en/intro-yii "Yii 2 Guide: About Yii"
[5]: https://www.postgresql.org/download/windows/ "PostgreSQL: Windows installers"
[6]: https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html "nginx Documentation: FastCGI module"
