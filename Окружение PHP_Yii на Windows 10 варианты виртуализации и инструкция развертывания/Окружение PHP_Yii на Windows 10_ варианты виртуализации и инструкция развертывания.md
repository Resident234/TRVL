# Окружение PHP/Yii на Windows 10: варианты виртуализации и инструкция развертывания

> Это отдельное руководство по универсальному шаблону окружения, не инструкция по запуску текущего репозитория TRVL. Оно описывает собственный примерный стек (включая Jenkins, Selenium, Redis и Memcached), которого нет в корневом `docker-compose.yml`. Для TRVL используйте корневой [README.md](../README.md) и [TECHNOLOGY_STACK.md](../TECHNOLOGY_STACK.md).

**Автор:** Manus AI  
**Дата проверки документации:** 25 августа 2026 г.  
**Цель:** получить воспроизводимое окружение для разработки и CI/CD со стеком PHP, Yii 2, Phing, PHPUnit, Jenkins, Selenium, PostgreSQL, Memcached, Redis и nginx.

## 1. Ключевой вывод

Для Windows 10 я рекомендую не собирать этот стек из нативных Windows-инсталляторов. Оптимальная схема для разработки — **WSL 2 + Docker Desktop с Linux-контейнерами + Docker Compose**. В контейнерах запускаются PHP-FPM/Yii, nginx, PostgreSQL, Redis, Memcached и Selenium. Jenkins в однопользовательском варианте удобно поставить как Linux-сервис внутри WSL 2: он будет выполнять `docker compose` через интеграцию Docker Desktop. Для командного или более изолированного CI Jenkins лучше вынести в отдельную Linux-виртуальную машину либо на отдельный сервер.

Такой выбор объясняется тем, что Redis официально описывает запуск Redis Open Source на Windows через Docker, а Windows-версия nginx прямо обозначена как beta с ограниченной масштабируемостью и запуском в виде консольного приложения, а не службы [12] [13]. Phing также предупреждает, что Unix-подобная платформа предпочтительнее, поскольку некоторые операции, например `chmod`, ограничены в Windows [6].

> **Важно о Windows 10.** Поддержка Windows 10 завершилась 14 октября 2025 года. Если компьютер подключён к интернету или на нём будет храниться код/учётные данные, предпочтительно обновить хост до поддерживаемой версии Windows либо использовать отдельный поддерживаемый Linux-хост [3]. Если обновление невозможно, описанная схема годится для локальной разработки, но не должна рассматриваться как безопасная производственная платформа.

## 2. Варианты архитектуры

| Вариант | Состав | Преимущества | Недостатки | Когда выбирать |
|---|---|---|---|---|
| **A. Нативный Windows** | PHP CGI, nginx for Windows, PostgreSQL EDB, Memurai/сторонний Memcached, Jenkins MSI, Selenium на Windows | Нет отдельной VM, простая установка отдельных программ | Несовпадение с Linux production, nginx for Windows beta, Redis/Memcached требуют специальных Windows-вариантов, сложные PHP-расширения, больше ручных служб и PATH | Только если Docker/WSL запрещены политикой и требуется именно Windows-нативность |
| **B. WSL 2 + Docker Desktop + Compose** | Linux-контейнеры для всего прикладного стека; Jenkins как WSL-сервис или отдельный контейнер | Воспроизводимость, близкое к Linux production окружение, единая сеть Compose, простой сброс и перенос, работает на Home/Pro для Linux-контейнеров | Требует аппаратной виртуализации, Docker Desktop использует RAM, Windows 10 уже не получает обычные обновления безопасности | **Рекомендуемый вариант для разработки на одном ПК** |
| **C. Полная Linux VM** | Hyper-V/VMware/VirtualBox, внутри Ubuntu, Docker Engine/Compose, Jenkins и весь стек | Максимальная изоляция от Windows, хороший production parity, можно не использовать Docker Desktop | Дополнительная RAM/CPU/диск, настройка NAT/SSH, Hyper-V доступен не во всех редакциях Windows 10 | Когда нужна отдельная Linux-среда, Docker Desktop запрещён или нужен более строгий границы изоляции |
| **D. Отдельный CI-сервер** | На Windows остаётся только dev-стек, Jenkins запускается на Linux VM/сервере или в облаке | CI не зависит от выключенного ноутбука, меньше конфликтов, проще масштабировать Selenium | Нужен отдельный хост и сеть, требуется настройка credentials и репозитория | Команда, постоянный CI, несколько агентов или параллельные UI-тесты |

WSL 2 требует Windows 10 версии 2004/build 19041 или новее для команды `wsl --install`; актуальная страница Docker Desktop для Windows указывает Windows 10 22H2/build 19045, 64-битный CPU с SLAT, минимум 8 GB RAM и включённую виртуализацию в BIOS/UEFI для WSL 2 backend [1] [2]. На хосте с 16 GB RAM разумно выделить Docker Desktop около 6–8 GB, а на хосте с 32 GB — 8–12 GB, оставив Windows запас для браузера и IDE.

## 3. Целевой сетевой и файловый дизайн

В рекомендуемой схеме наружу публикуются только nginx, PostgreSQL, Redis и Selenium на loopback-адресе Windows. Приложение подключается к сервисам по именам Compose, а не по `localhost`: `postgres`, `redis`, `memcached`, `selenium` и `nginx`. Это принципиально важно, потому что внутри контейнера `localhost` означает сам контейнер.

| Компонент | Контейнерный адрес | Host-порт | Назначение |
|---|---:|---:|---|
| nginx | `nginx:80` | `127.0.0.1:8080` | Веб-приложение Yii, адрес в браузере `http://localhost:8080` |
| PHP-FPM | `app:9000` | Не публикуется | Выполнение PHP; доступен только nginx и CI |
| PostgreSQL | `postgres:5432` | `127.0.0.1:5432` | База данных и локальные клиенты |
| Redis | `redis:6379` | `127.0.0.1:6379` | Сессии, кэш, очереди при необходимости |
| Memcached | `memcached:11211` | Не публикуется | Эфемерный кэш внутри backend-сети |
| Selenium | `selenium:4444` | `127.0.0.1:4444` | WebDriver для UI-тестов |
| Jenkins | WSL `localhost:8081` | `8081` | CI; порт выбран, чтобы не конфликтовать с nginx на 8080 |

Исходный код нужно хранить в файловой системе Linux, например в `~/src/yii-dev`, а не под `/mnt/c/...`. Так уменьшается задержка файловых операций при bind-mount в Linux-контейнеры. Репозиторий, `.env`, `vendor` и Docker volumes следует считать разными сущностями: `.env` не коммитится, `vendor` строится Composer, а данные PostgreSQL/Redis сохраняются в named volumes.

## 4. Подготовка Windows 10 и WSL 2

### 4.1. Проверка хоста

Проверьте редакцию и сборку командой `winver`. Для Docker Desktop на Windows 10 целевой минимум — 22H2/build 19045. В UEFI/BIOS включите Intel VT-x/AMD-V. В диспетчере задач на вкладке «Производительность → ЦП» поле «Виртуализация» должно иметь значение «Включено».

Откройте PowerShell от имени администратора и выполните:

```powershell
systeminfo
wsl --status
```

Если команда `wsl --status` отсутствует или Windows старой сборки, сначала установите все доступные обновления Windows 10 и перезагрузите компьютер.

### 4.2. Установка WSL 2

Для современной Windows 10 используйте PowerShell от имени администратора:

```powershell
wsl --install -d Ubuntu
wsl --set-default-version 2
wsl --update
wsl --status
wsl -l -v
```

После первого запуска Ubuntu создайте Linux-пользователя и пароль. В выводе `wsl -l -v` у дистрибутива Ubuntu в столбце `VERSION` должно быть `2`. Если там `1`, переключите дистрибутив:

```powershell
wsl --set-version Ubuntu 2
```

Если автоматическая установка не сработала, включите компоненты вручную, перезагрузитесь и повторите установку Ubuntu:

```powershell
dism.exe /online /enable-feature /featurename:Microsoft-Windows-Subsystem-Linux /all /norestart
dism.exe /online /enable-feature /featurename:VirtualMachinePlatform /all /norestart
```

Microsoft указывает, что новые дистрибутивы, установленные через `wsl --install`, по умолчанию используют WSL 2, а проверка режима выполняется через `wsl -l -v` [1].

### 4.3. Включение systemd для Jenkins в WSL

В Ubuntu выполните:

```bash
sudo tee /etc/wsl.conf >/dev/null <<'EOF'
[boot]
systemd=true
EOF
exit
```

Затем в PowerShell выполните:

```powershell
wsl --shutdown
wsl -d Ubuntu
```

Проверьте, что systemd доступен:

```bash
systemctl is-system-running || true
```

Если конкретная сборка WSL не поддерживает systemd, Jenkins можно запустить как обычный процесс Java или перенести Jenkins в контейнер/отдельную VM. Основной Compose-стек от systemd не зависит.

## 5. Установка Docker Desktop и интеграция с WSL

Скачайте Docker Desktop только с официальной страницы Docker. В установщике выберите backend WSL 2. После установки откройте **Settings → Resources → WSL Integration**, включите интеграцию с Ubuntu и перезапустите Docker Desktop. Docker указывает WSL 2 как основной backend для большинства пользователей; для Windows 10 Pro/Enterprise альтернативой является Hyper-V [2].

В Ubuntu проверьте интеграцию:

```bash
docker version
docker compose version
docker run --rm hello-world
```

Если `docker` не найден внутри Ubuntu, включите именно установленный дистрибутив в **WSL Integration**, а не только общий переключатель. Если Docker daemon недоступен, сначала запустите Docker Desktop в Windows.

Не добавляйте проект под `/mnt/c` без необходимости. Создайте каталог в Linux:

```bash
mkdir -p ~/src
cd ~/src
```

При необходимости ограничьте ресурсы Docker Desktop в **Settings → Resources → Advanced**. Для хоста с 16 GB RAM начните с 4 CPU и 6–8 GB RAM. Selenium Chrome может потреблять заметный объём памяти, поэтому при постоянных падениях браузера увеличьте shared memory контейнера или память Docker Desktop.

## 6. Создание или подключение Yii-проекта

Инструкция и файлы в приложенном комплекте являются инфраструктурным шаблоном. Они не заменяют исходный код конкретного приложения и не должны бездумно перезаписывать существующий `composer.json`.

Для нового Yii 2 basic-приложения из Ubuntu можно использовать Composer-образ:

```bash
cd ~/src
mkdir yii-dev
cd yii-dev
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app composer:2 \
  create-project --prefer-dist yiisoft/yii2-app-basic /app
```

Для существующего проекта разместите его Git-клон в `~/src/yii-dev`. Затем скопируйте из приложенного комплекта инфраструктурные файлы `docker-compose.yml`, каталог `infra`, `build.xml`, `phpunit.xml`, `Jenkinsfile`, `tests`, `tools` и `.dockerignore`. Зависимости из приложенного `composer.json` нужно **слить** с существующими зависимостями проекта, а не заменять проектный файл целиком.

Минимальный состав зависимостей для нового проекта должен соответствовать фактическому коду. В шаблоне используется PHP 8.4, Yii 2, `yiisoft/yii2-redis`, `yiisoft/yii2-memcached`, Phing 3.1, PHPUnit 13 и `php-webdriver/webdriver`. Yii 2 требует PHP 7.4 или новее, а PHPUnit 13 требует PHP 8.4 или новее [4] [5]. Если приложение старое и не совместимо с PHP 8.4, сначала зафиксируйте требуемую версию PHP и соответствующую ветку PHPUnit; нельзя механически поднимать версию только ради шаблона.

Composer следует запускать как `composer install`, используя закоммиченный `composer.lock`. Команда `composer update` предназначена для управляемого обновления зависимостей и не должна использоваться в обычном CI-запуске [7].

## 7. Настройка проекта и запуск сервисов

В корне проекта создайте локальный `.env`:

```bash
cp .env.example .env
export APP_UID="$(id -u)"
export APP_GID="$(id -g)"
```

Замените в `.env` значения `POSTGRES_PASSWORD` и `REDIS_PASSWORD` на свои. Файл `.env` не добавляйте в Git. Для локальной разработки допустимы простые значения только до тех пор, пока сервисы привязаны к `127.0.0.1`; для общего стенда используйте секреты Jenkins или внешний secret store.

Сначала проверьте итоговую конфигурацию и соберите PHP-образ:

```bash
docker compose config
docker compose build app
```

Запустите сервисы:

```bash
docker compose up -d postgres redis memcached selenium app nginx
docker compose ps
```

В проектном каталоге из комплекта уже есть healthcheck для PostgreSQL и Redis, а для Selenium используется проверка `/status`. После старта выполните:

```bash
docker compose exec -u appuser app composer install --no-interaction --prefer-dist
docker compose exec app php tools/healthcheck.php
```

Если приложение использует Yii-миграции, примените их после успешной проверки соединений:

```bash
docker compose exec -u appuser app php yii migrate --interactive=0
```

Веб-приложение должно открываться по адресу [http://localhost:8080](http://localhost:8080). Внутри контейнеров приложение должно использовать следующие значения:

```text
DB_DSN=pgsql:host=postgres;port=5432;dbname=yii_dev
DB_USERNAME=yii
DB_PASSWORD=<значение из .env>
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<значение из .env>
MEMCACHED_HOST=memcached
MEMCACHED_PORT=11211
SELENIUM_URL=http://selenium:4444
APP_URL=http://nginx
```

Пример `config/main.php.example` показывает подключение Yii к PostgreSQL, Redis session component и Memcached cache component. В реальном проекте выберите назначение кэша осознанно: Redis удобно использовать для сессий/очередей и кэша с дополнительными возможностями, Memcached — для эфемерного кэша, который можно полностью потерять без восстановления.

## 8. Установка и настройка Phing и PHPUnit

Phing устанавливается локально в проект через Composer. Официальный сайт Phing указывает стабильную ветку 3.1.2, поддерживает установку через Composer и отмечает, что Windows имеет ограничения для Unix-операций [6]. Поэтому build-команды в шаблоне выполняются внутри Linux PHP-контейнера, а не нативным Windows PHP.

Проверьте unit-тесты вручную:

```bash
docker compose exec -u appuser app vendor/bin/phpunit tests/unit
docker compose exec -u appuser app vendor/bin/phing unit
```

Файл `build.xml` определяет следующие стадии:

| Target | Назначение |
|---|---|
| `prepare` | Создание каталога `build` и очистка старых JUnit-отчётов |
| `dependencies` | `composer install --no-interaction --prefer-dist` |
| `unit` | PHPUnit unit-тесты и `build/junit-unit.xml` |
| `acceptance` | Ожидание Selenium и PHPUnit UI/acceptance-тесты |
| `ci` | Последовательный запуск unit и acceptance стадий |

В шаблоне coverage намеренно не включён в обязательную стадию: для покрытия нужно отдельно добавить Xdebug или PCOV и настроить лимиты CI. Это предотвращает ложные падения на машине, где тесты работают, но coverage-драйвер ещё не установлен.

## 9. Selenium и функциональное/UI-тестирование

Selenium 4 включает Selenium Manager, который умеет находить браузер, разрешать совместимый driver, скачивать его и кэшировать в `~/.cache/selenium`, поэтому в обычном тестовом коде не нужно вручную прописывать путь к `chromedriver.exe` [10]. В рекомендуемой схеме браузер запускается в официальном Docker-образе Selenium, а PHP-код подключается к нему по имени сервиса `selenium`.

Официальный проект Docker Selenium публикует standalone- и Grid-образы. Для одного CI-воркера достаточно `selenium/standalone-chrome`; Grid нужен при параллельном запуске или для нескольких браузеров [15]. В шаблоне используется `shm_size: 2gb`, поскольку Chrome в контейнере чувствителен к слишком маленькому shared memory.

UI-тесты запускаются явно:

```bash
docker compose exec -u appuser -e RUN_ACCEPTANCE=1 app vendor/bin/phing acceptance
```

Тест `tests/acceptance/SmokeTest.php` проверяет, что Chrome через WebDriver открывает `http://nginx` и не получает ответ `502 Bad Gateway`. До включения `RUN_ACCEPTANCE=1` тест помечается как skipped, чтобы локальный unit-запуск не требовал браузера. Для CI переменная включена в `Jenkinsfile`.

Не используйте `http://localhost:8080` как `APP_URL` внутри Selenium-контейнера: это будет localhost Selenium-контейнера. Для Compose-сети используйте `APP_URL=http://nginx`. Если тесты запускаются нативно на Windows, тогда можно использовать `http://localhost:8080`, но это уже другой топологический сценарий.

## 10. Jenkins в WSL 2

Вариант ниже предполагает Jenkins как Linux-сервис внутри Ubuntu WSL. Это проще, чем давать Jenkins-контроллеру Docker socket из контейнера. Jenkins должен иметь доступ к Docker CLI и Compose; заданиям, полученным от недоверенных пользователей, такой доступ давать нельзя, поскольку Docker socket фактически предоставляет очень широкие права на хост.

В Ubuntu установите Java 21 и Jenkins LTS по официальной инструкции:

```bash
sudo apt update
sudo apt install -y fontconfig openjdk-21-jre wget ca-certificates
sudo mkdir -p /etc/apt/keyrings
sudo wget -O /etc/apt/keyrings/jenkins-keyring.asc \
  https://pkg.jenkins.io/debian-stable/jenkins.io-2026.key
echo "deb [signed-by=/etc/apt/keyrings/jenkins-keyring.asc] \
https://pkg.jenkins.io/debian-stable binary/" | \
  sudo tee /etc/apt/sources.list.d/jenkins.list >/dev/null
sudo apt update
sudo apt install -y jenkins
```

Текущая документация Jenkins указывает Java 21 или новее и рекомендует запускать Jenkins от отдельной учётной записи, а не от LocalSystem; для Linux-пакета создаётся пользователь `jenkins` [8] [16]. После установки дайте сервисному пользователю доступ к Docker и перезапустите сервис:

```bash
sudo usermod -aG docker jenkins
sudo systemctl restart jenkins
sudo systemctl status jenkins --no-pager
sudo -u jenkins -H docker version
sudo -u jenkins -H docker compose version
```

Если Docker Desktop доступен в интерактивной оболочке, но не виден Jenkins, проверьте `PATH` службы и наличие сокета. Не запускайте Jenkins от root. Если порт 8080 занят nginx, установите Jenkins на 8081:

```bash
sudo systemctl edit jenkins
```

В открывшемся override-файле добавьте:

```ini
[Service]
Environment="JENKINS_PORT=8081"
```

Затем примените настройку:

```bash
sudo systemctl daemon-reload
sudo systemctl restart jenkins
```

Откройте [http://localhost:8081](http://localhost:8081). Первоначальный пароль можно получить так:

```bash
sudo cat /var/lib/jenkins/secrets/initialAdminPassword
```

Установите suggested plugins, убедитесь в наличии Git, Pipeline и JUnit, создайте администратора и подключите pipeline из Git-репозитория. Jenkins официально описывает MSI-вариант для Windows и Docker-вариант, но для данной схемы Linux-сервис WSL удобнее, потому что pipeline выполняет `docker compose` напрямую в том же Linux-окружении [8] [9].

### 10.1. Что делает приложенный Jenkinsfile

Приложенный `Jenkinsfile` отключает параллельные сборки, создаёт уникальный Compose project name для каждого билда, запускает PostgreSQL/Redis/Memcached/Selenium/PHP/nginx, устанавливает зависимости, выполняет Phing unit и acceptance targets и архивирует `build/**/*`.

Из-за уникального project name и команды `docker compose down --volumes` данные конкретного CI-билда удаляются после завершения. Это правильно для изолированного CI-теста, но не используйте этот Jenkinsfile для постоянной базы данных. Для долговременного стенда уберите `--volumes`, вынесите PostgreSQL в отдельный Compose-проект и организуйте резервное копирование.

## 11. Проверка интеграции

После первого запуска выполните проверки в следующем порядке:

```bash
# Состояние контейнеров
docker compose ps

# PHP и расширения
docker compose exec app php -v
docker compose exec app php -m | grep -E 'PDO|pdo_pgsql|redis|memcached|intl|zip'

# Проверка базовой инфраструктуры
docker compose exec app php tools/healthcheck.php

# Unit-тесты
docker compose exec -u appuser app vendor/bin/phing unit

# UI-тесты
docker compose exec -u appuser -e RUN_ACCEPTANCE=1 app vendor/bin/phing acceptance
```

Ожидаемый результат — healthy для PostgreSQL, Redis и Selenium, успешная проверка Memcached, доступный HTTP-ответ nginx и завершение PHPUnit с кодом 0. При диагностике смотрите логи только нужного слоя:

```bash
docker compose logs --tail=200 app

docker compose logs --tail=200 nginx

docker compose logs --tail=200 postgres

docker compose logs --tail=200 redis

docker compose logs --tail=200 selenium
```

## 12. Жизненный цикл, данные и безопасность

Обычная остановка без удаления данных:

```bash
docker compose stop
docker compose start
```

Остановка с удалением контейнеров, но сохранением named volumes:

```bash
docker compose down
```

Полный сброс локальной базы и Redis:

```bash
docker compose down --volumes
```

Перед последней командой сохраните данные, если они нужны. Например, дамп PostgreSQL можно сделать так:

```bash
docker compose exec -T postgres pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" > backup.sql
```

В PowerShell или в Jenkins не храните реальные пароли в `docker-compose.yml`, `Jenkinsfile`, Git или логах. Для командного CI передавайте их через Jenkins Credentials и environment injection. Не публикуйте PostgreSQL, Redis, Memcached или Selenium на `0.0.0.0` без необходимости. В приложенном Compose PostgreSQL, Redis и Selenium привязаны к `127.0.0.1`, а Memcached вообще не опубликован на host.

Memcached не является хранилищем данных: его содержимое может быть удалено в любой момент. Redis с AOF в шаблоне сохраняется в volume, но это не отменяет резервного копирования, контроля доступа и тестирования восстановления. Для production необходимо также добавить TLS/HTTPS, firewall, ротацию секретов, отдельные учётные записи и мониторинг.

## 13. Типовые проблемы

| Симптом | Причина | Исправление |
|---|---|---|
| `VERSION 1` в `wsl -l -v` | Дистрибутив работает под WSL 1 | Выполнить `wsl --set-version Ubuntu 2` |
| `docker: command not found` в Ubuntu | Не включена WSL Integration | Docker Desktop → Resources → WSL Integration → Ubuntu |
| Docker daemon недоступен | Docker Desktop выключен или не завершил запуск | Запустить Docker Desktop и повторить `docker version` |
| `bind: address already in use` на 8080/5432/6379/4444 | Порт занят Windows-процессом | Проверить `netstat -ano | findstr :8080` и изменить host-порт в Compose |
| nginx возвращает 502 | PHP-FPM не запущен или неверен upstream | Выполнить `docker compose ps`, проверить `docker compose logs app`; upstream должен быть `app:9000` |
| `could not find driver` | Не установлено `pdo_pgsql` в PHP-образе | Пересобрать `docker compose build --no-cache app`; проверить `php -m` |
| PHP-контейнер создаёт файлы root | UID/GID не переданы при build | Выполнить `export APP_UID=$(id -u); export APP_GID=$(id -g); docker compose build app` |
| Chrome падает или Selenium не создаёт session | Мало shared memory или Selenium ещё не готов | Увеличить `shm_size`, дождаться healthcheck, запускать `wait_for_selenium.php` |
| Selenium не видит приложение | В тесте указан `localhost` | В Compose использовать `APP_URL=http://nginx` |
| Jenkins не выполняет `docker compose` | Пользователь `jenkins` не имеет доступа к Docker или сервис не перезапущен | `sudo usermod -aG docker jenkins`, перезапустить Jenkins, проверить командой от имени `jenkins` |
| У Jenkins не открывается UI | Порт 8080 занят nginx | Настроить `JENKINS_PORT=8081` |
| Медленная сборка и тесты | Репозиторий хранится под `/mnt/c` | Перенести его в `~/src` внутри WSL |
| Нативный nginx Windows не стартует | Относительные пути и prefix зависят от каталога запуска | Использовать Linux-контейнер; если остаётся native nginx, применять forward slash и смотреть `logs/error.log` |

## 14. Полная Linux VM как запасной вариант

Если Docker Desktop запрещён или нужна более сильная изоляция, создайте Ubuntu 24.04 LTS VM в Hyper-V на Windows 10 Pro/Enterprise либо в VMware Workstation/VirtualBox. Выделите примерно 4 vCPU, 8–12 GB RAM, 60–80 GB динамического диска и включите виртуальную сеть NAT. Внутри VM установите Docker Engine и Compose по официальной инструкции Docker для Ubuntu, после чего используйте тот же `docker-compose.yml`.

Hyper-V имеет смысл использовать, если уже включён Hyper-V и редакция Windows его поддерживает. При выборе VirtualBox/VMware не включайте одновременно несколько конкурирующих гипервизоров без понимания последствий: WSL 2, Hyper-V и Windows Hypervisor Platform могут менять режим работы стороннего гипервизора и снижать производительность. В VM Jenkins можно поставить обычным Linux-пакетом с Java 21, а доступ к приложению организовать через NAT port forwarding или SSH-туннель.

Для этой схемы в Compose можно слушать порты внутри VM на `0.0.0.0`, но в firewall VM разрешить только нужные адреса. В отличие от локального варианта, не открывайте PostgreSQL/Redis/Memcached наружу сети без аутентификации и правил firewall.

## 15. Нативный Windows-вариант, если виртуализация невозможна

Нативная схема технически возможна, но её следует считать планом C. PHP устанавливается из официальных Windows-сборок, Composer — через `Composer-Setup.exe`, PostgreSQL — через EDB installer, Jenkins — через Windows MSI с Java 21, а Selenium — с браузером Chrome/Edge и Selenium Manager. Однако PHP под nginx на Windows обычно требует запускать `php-cgi.exe -b 127.0.0.1:9000` и самостоятельно организовывать перезапуск процесса; официальный nginx описывает Windows-версию как beta и не как Windows-службу [2] [7] [8] [13].

Redis на Windows рекомендуется запускать через Docker; если Docker невозможен, официальный Redis-документ упоминает Memurai как партнёрский Windows-вариант [12]. Для Memcached потребуется совместимый Windows-сервер и PHP-расширение той же версии/архитектуры PHP, что часто создаёт больше проблем, чем контейнер. В таком варианте особенно важно фиксировать версии PHP, расширений, драйверов и браузера и отдельно проверять каждый перезапуск Windows.

## 16. Что менять для production

Описанный комплект предназначен для development и локального CI. Для production вынесите Jenkins из application host, используйте поддерживаемый Linux-хост, не монтируйте исходный код в runtime-контейнер, соберите immutable PHP-образ, отключите `display_errors`, используйте secrets manager, TLS, firewall, резервное копирование PostgreSQL и контролируемую политику обновления образов. Selenium и Memcached не должны быть доступны из публичной сети.

Также замените широкие development-права Docker socket на отдельного ephemeral build agent либо rootless/удалённый builder. Контроллер Jenkins не должен выполнять недоверенные pull request scripts с правами хостовой Docker-системы.

## 17. Состав приложенного комплекта

| Файл | Назначение |
|---|---|
| `docker-compose.yml` | Сервисы app, nginx, PostgreSQL, Redis, Memcached и Selenium |
| `infra/php/Dockerfile` | PHP 8.4-FPM с PDO PostgreSQL, Redis, Memcached, Composer |
| `infra/php/php.ini` | Настройки development PHP и OPcache |
| `infra/nginx/default.conf` | Yii document root `/var/www/html/web` и FastCGI к `app:9000` |
| `composer.json` | Пример зависимостей Yii/Phing/PHPUnit/Selenium |
| `build.xml` | Phing targets `unit`, `acceptance`, `ci` |
| `phpunit.xml` | PHPUnit suites и autoload |
| `Jenkinsfile` | Declarative pipeline для WSL Jenkins |
| `tests/acceptance/SmokeTest.php` | Selenium smoke test через PHPUnit |
| `tests/acceptance/wait_for_selenium.php` | Ожидание готовности Selenium |
| `tools/healthcheck.php` | Проверка PostgreSQL, Redis, Memcached и Selenium |
| `config/main.php.example` | Пример Yii-компонентов |
| `.env.example` | Шаблон локальных переменных |

## References

[1]: https://learn.microsoft.com/en-us/windows/wsl/install "Microsoft Learn: Install WSL"
[2]: https://docs.docker.com/desktop/setup/install/windows-install/ "Docker Docs: Install Docker Desktop on Windows"
[3]: https://support.microsoft.com/en-us/windows/deployment/updates-lifecycle/windows-10-support-has-ended-10-14-2025 "Microsoft Support: Windows 10 support has ended"
[4]: https://www.yiiframework.com/doc/guide/2.0/en/intro-yii "Yii 2 Guide: About Yii"
[5]: https://phpunit.de/supported-versions.html "PHPUnit: Supported Versions"
[6]: https://www.phing.info/guide/chunkhtml/ch.settingup.html "Phing User Guide: Setting-up Phing"
[7]: https://getcomposer.org/doc/00-intro.md "Composer Documentation: Introduction"
[8]: https://www.jenkins.io/doc/book/installing/windows/ "Jenkins User Handbook: Windows installation"
[9]: https://www.jenkins.io/doc/book/installing/docker/ "Jenkins User Handbook: Docker installation"
[10]: https://www.selenium.dev/documentation/selenium_manager/ "Selenium Documentation: Selenium Manager"
[11]: https://www.postgresql.org/download/windows/ "PostgreSQL: Windows installers"
[12]: https://redis.io/docs/latest/operate/oss_and_stack/install/install-stack/windows/ "Redis Docs: Run Redis Open Source on Windows using Docker"
[13]: https://nginx.org/en/docs/windows.html "nginx: nginx for Windows"
[14]: https://hub.docker.com/_/memcached "Docker Hub: memcached Official Image"
[15]: https://github.com/SeleniumHQ/docker-selenium "SeleniumHQ: docker-selenium"
[16]: https://www.jenkins.io/doc/book/installing/linux/ "Jenkins User Handbook: Linux installation"
