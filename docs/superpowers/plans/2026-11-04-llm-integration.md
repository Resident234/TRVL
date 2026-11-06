# Интеграция с LLM: план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать странице настроек публикаций подключение к OpenAI-совместимому прокси и два
редактируемых промта, а форме публикации — ручки «Проверить орфографию» и «Стилизовать» и блок
свободного диалога, правящий текст части.

**Architecture:** Новое пространство `app/shared/Llm/` с той же этажностью, что `Forum` и `Telegram`
(`Contract/`, `Dto/`, `Infrastructure/`, `Service/`). Короткие операции (орфография, диалог) идут
синхронным AJAX через `SiteController`; стилизация становится в таблицу-очередь, которую дренирует
cron-роль нового контейнера `llm`. Образец стиля читается с диска приложением и едет в теле запроса
блоком; контракт ответа собирает код, а не писатель, и полуразмеченный текст в форму не попадает.

**Tech Stack:** PHP 8.4 / Yii2, PostgreSQL 16, Codeception (`Unit`), PHPStan level 5, phpcs PSR12,
Docker Compose + supercronic, jQuery + OverlayScrollbars (уже в `DashboardAsset`), встроенный в
PHP-новдок JS страницы публикаций и офлайн-харнесс `app/runtime/pubjs/`.

**Spec:** [docs/superpowers/specs/2026-11-04-llm-integration-design.md](../specs/2026-11-04-llm-integration-design.md)
— согласован с заказчиком. План спорит с спеком, а не заменяет его: 3-й раздел про типы полей,
4-й про читатель образца, 5-й про контракт, 6-й про три операции, 7-й про форму, 8-й про DI,
9-й про контейнер, 10-й про таблицы, 11-й про ошибки, 12-й про тесты, 13-й про доки. 1-й и 2-й
разделы — постановка и развилки — планом исполняются, а не пересказываются; 14-й (вне объёма
ревизии) план не трогает ни одним шагом, и ровно поэтому 15-е приложение спека («измеренные числа»)
переснимается живым прогоном Task 17 и оседает в доке цифрами этого прогона, а не цифрами спека.

## Global Constraints

- Пути внутри контейнера `app` — без префикса `app/`: репозиторный `app/` есть `/var/www/html`.
  Хостовый `E:\SERVER\Work_awd\app\…` контейнеру не виден.
- PHP на хосте нет. Все четыре PHP-гейта снимаются только внутри поднятого контейнера `app`;
  `node --check` и офлайн-харнесс работают на хосте.
- `docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc '…'`
  — флаг `-e` принадлежит `docker compose exec`, не `codecept`. Нутрь кавычек `sh -lc` его уносить
  нельзя: прогон падает на «Extension DB_DSN=… can't be loaded» и выглядит сломанным, а не красным.
- Unit-набор гонится **только** на `yii_test`. `app/config/test_db.php` берёт `yii_test`, лишь когда
  `DB_DSN` пуст, а контейнер `app` выдаёт `yii_dev` — без флага тесты пишущие затирают рабочую базу.
- Поднимаются `postgres`, `app`, `nginx` и (с 12-го задания) `llm`. `telegram` не поднимается никогда:
  его тики `*/5` реально пишут, правят и удаляют в `@gsu_travels`.
- Ключ прокси — словом заказчика, попадает только в строку `llmApiKey` базы. В git, в `.env.example`,
  в коммит, в логи и в отчёт он не попадает ни при каком условии.
- `AWD.md` не трогается. `JOURNAL.md` и `app/runtime/` не индексируются. `git add -A`/`git add .`
  запрещены: в stage только те пути, что названы в задании, и только отслеживаемые.
- Дерево worktree чужое наполовину: до начала этой ревизии в нём живут правки другой задачи, и четыре
  общих файла — `.env.example`, `app/config/console.php`, `docker/php-cli/entrypoint.sh`,
  `docker-compose.yml` — грязны уже сейчас. Путь, грязный до ревизии, ни один её шаг не стейджит
  `git add`: такой blob пересобирается предполётом шага 0 Task 12 (у `README.md` в Task 18 свой
  предполёт, механизм тот же), а список чужих маркеров оттуда же — по нему стейдж проверяется, что
  чужая работа в коммит не вошла.
- Коммит — `git commit -F <файл>` (кириллица в `-m` через консоль неразбираема). Тема коммита —
  `type(scope): русское предложение` по образцу существующих.
- JS живёт в новдоке `<<<'JS'` внутри `app/views/site/publications.php`: тело — с отступом в 8
  пробелов, терминатор `JS` — в нулевой колонке. Функции харнесс вырезает по `^        function имя(`
  и закрывающей `        }`.
- Ни один контрол блока диалога не имеет атрибута `name`, все его кнопки — `type="button"`: иначе они
  сдвигают массивы `publicationText[]` и `publicationFormatting[]`.
- Ручки гаснут (`disabled` + причина в `title`), а не снимаются (`d-none`); `form-check form-switch`
  разметку не трогать — `pubjs/formswitches.js` красный именно на них и красноту расширять нельзя.
- Цвета держат смысл: зелёный — ручки разбивки, фиолетовый — блок кнопок-ссылок, pink — рамка даты,
  синий — ряды перемещения альбома. Новые ручки — `btn-outline-info`.
- Базовая линия офлайн-песочницы красная по праву: `PROBLEMS: 5` — `partsgroup.js`, `partmove.js` и
  три драйвера мутантов с устаревшими якорями. Чинить их попутно нельзя; свою правку нельзя им и
  приписать.
- Числа гейтов переснимаются живым прогоном этой ревизии, а не берутся из памяти: unit, phpstan,
  PSR12 по view и счётки `run-all.txt` пишутся в доку только из захвата этого же прогона.
- Мажорная секция `CHANGELOG.md` — `## [2.0.0]` (слово заказчика). Строки README о последнем релизе
  и теге синхронизируются только когда тег поставлен, и только словом заказчика. Пуш и тег — по
  отдельному слову.
- Номера строк в ссылках на файлы репозитория (`save()` — `:448-490`, `$options = …` — `:629`)
  сняты с этих файлов до начала ревизии и стоят рядом с уникальным куском кода: искать надо по
  куску, а по числу — только сверяться. Задания 2, 3 и 8 вставляют в `SCHEMA` и в тот же файл
  свои строки, и всё, что лежит ниже, сдвигается сам.

## Карта файлов

Создаётся:

| Файл | Ответственность |
|---|---|
| `app/migrations/m261104_000000_widen_publications_setting_value.php` | `value` из `string(255)` в `text` |
| `app/migrations/m261104_000001_create_llm_job.php` | таблица `llm_job` — очередь фоновых задач |
| `app/migrations/m261104_000002_create_llm_chat_message.php` | таблица `llm_chat_message` — история диалога |
| `app/shared/Llm/Contract/LlmClientInterface.php` | один метод `chat(array $messages, int $timeout): LlmReply` |
| `app/shared/Llm/Contract/LlmJobQueueInterface.php` | постановка, claim, завершение, возврат устаревшего, чистка, lock |
| `app/shared/Llm/Contract/LlmChatRepositoryInterface.php` | `append()` и `latest()` истории |
| `app/shared/Llm/Dto/LlmReply.php` | текст ответа, причина остановки, usage |
| `app/shared/Llm/Dto/LlmAnswer.php` | текст + метки, готовые лечь в поле |
| `app/shared/Llm/Dto/StyleSampleData.php` | имя папки, знак, сам блок образца |
| `app/shared/Llm/Dto/LlmJobData.php` | ряд очереди во время работы |
| `app/shared/Llm/Dto/LlmChatMessageData.php` | строка истории |
| `app/shared/Llm/Infrastructure/LlmRequestException.php` | отказ транспорта с понятной причиной |
| `app/shared/Llm/Infrastructure/StyleSampleUnavailableException.php` | отказ образца с именем папки |
| `app/shared/Llm/Infrastructure/OpenAiCompatibleClient.php` | cURL к `POST {base}/chat/completions` |
| `app/shared/Llm/Infrastructure/LlmJobRepository.php` | `llm_job` + advisory lock |
| `app/shared/Llm/Infrastructure/LlmChatRepository.php` | `llm_chat_message` |
| `app/shared/Llm/Service/TextStyleSampleReader.php` | `result.json` → блок образца |
| `app/shared/Llm/Service/LlmTextService.php` | промт + контракт + разбор, три операции |
| `app/shared/Llm/Service/LlmJobProcessor.php` | один дрейн очереди под lock |
| `app/commands/LlmController.php` | `yii llm/process-jobs` |
| `app/tests/Unit/shared/Llm/Service/LlmTextServiceTest.php` | сборка запроса, разбор, отказы |
| `app/tests/Unit/shared/Llm/Service/LlmTextServiceStylizeTest.php` | два вызова стилизации |
| `app/tests/Unit/shared/Llm/Service/LlmTextServiceChatTest.php` | окно истории из 12 |
| `app/tests/Unit/shared/Llm/Service/LlmJobProcessorTest.php` | дрейн под заглушкой очереди |
| `app/tests/Unit/shared/Llm/Service/TextStyleSampleReaderTest.php` | обе формы `text`, потолок, порог |
| `app/tests/Unit/shared/Llm/Service/LlmClientStub.php` | фикстура клиента, считает вызовы и таймауты |
| `app/tests/Unit/shared/Llm/Service/StyleSampleFolder.php` | временный каталог стилей для тестов сервиса |
| `app/tests/Unit/shared/Llm/Service/FakeJobQueue.php` | фикстура очереди для теста дрейнера |
| `app/tests/Unit/shared/Llm/Service/FakeChatHistory.php` | фикстура истории для теста сервиса |
| `app/tests/Unit/shared/Llm/Infrastructure/LlmJobRepositoryTest.php` | `llm_job` на `yii_test` |
| `app/tests/Unit/shared/Llm/Infrastructure/LlmChatRepositoryTest.php` | `llm_chat_message` на `yii_test` |
| `app/tests/Unit/shared/Llm/Infrastructure/OpenAiCompatibleClientUrlTest.php` | склейка адреса из URL |
| `app/tests/Unit/shared/Llm/Dto/LlmReplyTest.php` | `fromApi()` на разобранный массив ответа |
| `app/tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php` | семь новых кодов, две ветки, `secret` |
| `app/tests/Unit/SiteControllerLlmPanelTest.php` | пять глаголов панели и состояние её ручки |
| `app/runtime/pubjs/llmpanel.js` | проверки блока диалога и ручек (вне git) |
| `app/runtime/pubjs/llmpanel-mutants.js` | мутанты по тем же якорям (вне git) |

Модифицируется: `app/shared/Settings/Service/PublicationSettingsService.php`,
`app/views/site/settings.php`, `app/controllers/SiteController.php`,
`app/views/site/publications.php`, `app/config/web.php`, `app/config/console.php`,
`docker-compose.yml`, `docker/php-cli/entrypoint.sh`, `.env.example`, `README.md`,
`PUBLICATIONS.md`, `CHANGELOG.md`, `app/runtime/pubjs/run-all.js` (вне git).

Удалить в конце: временные `app/runtime/_tmp_*`, которые завели прогоны.

---

### Task 1: Колонка `value` становится `text`

Дефолтный промт стилизации (3.3 спека) длиннее 255 байт, поэтому без релаксации Postgres отверг бы
первую же сохранённую строку. Эта миграция идёт **первой** — до двух новых таблиц и до любого кода,
который начнёт писать длинные значения.

**Files:**
- Create: `app/migrations/m261104_000000_widen_publications_setting_value.php`
- Reference: `app/migrations/m260925_000001_create_publications_setting.php:20` — `'value' => $this->string(255)->notNull()`

**Interfaces:**
- Consumes: таблица `{{%publications_setting}}` с колонкой `value character varying(255)`.
- Produces: колонка `value text` — все семь новых кодов и существующие восемнадцать пишутся в неё
  тем же `PublicationSettingsRepository`, чей интерфейс не меняется.

- [ ] **Step 1: Поднять стек без `telegram`**

```sh
docker compose up -d --build postgres app nginx
```

Ожидаем `postgres` healthy. `telegram` не поднимаем: его тики `*/5` пишут в `@gsu_travels`.

- [ ] **Step 2: Написать миграцию целиком**

`app/migrations/m261104_000000_widen_publications_setting_value.php`:

```php
<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The value column of the settings table grows to `text`. The default prompt of the
 * style transfer is longer than the 255 characters the table was born with, so the
 * first save of the LLM block would have been refused by the database.
 */
final class m261104_000000_widen_publications_setting_value extends Migration
{
    public function safeUp(): void
    {
        $this->alterColumn('{{%publications_setting}}', 'value', $this->text()->notNull());
    }

    public function safeDown(): void
    {
        $this->alterColumn('{{%publications_setting}}', 'value', $this->string(255)->notNull());
    }
}
```

`alterColumn`, не `changeColumn`: у `yii\db\Migration` метода `changeColumn` нет, а `alterColumn`
переставляет тип той же колонки. Префикса таблицы в проекте нет, поэтому `{{%publications_setting}}`
читается как `publications_setting`.

- [ ] **Step 3: Проверить синтаксис**

```sh
docker compose exec -T app php -l migrations/m261104_000000_widen_publications_setting_value.php
```

Ожидаем `No syntax errors detected`. Файл в `app/` — путь внутри контейнера без `app/`.

- [ ] **Step 4: Прогнать миграцию по обеим базам**

```sh
docker compose exec -T -u appuser app php yii migrate --interactive=0
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app php yii migrate --interactive=0
```

Ожидаем в обоих прогонах строку `m261104_000000_widen_publications_setting_value` и `Applied.` —
ни одна прежняя миграция не перевыпускается.

- [ ] **Step 5: Доказать тип колонки и круговой прогон**

```sh
docker compose exec -T postgres psql -U yii -d yii_dev -c '\d publications_setting'
docker compose exec -T -u appuser app php yii migrate/down 1 --interactive=0
docker compose exec -T -u appuser app php yii migrate --interactive=0
```

Ожидаем в `\d` строку `value | text | not null`, затем `Applied m261104_000000… (time: …)` на
обратном ходу и на повторном. Откат не трогает данные: `text` → `varchar(255)` сжимает тип, и в
`yii_dev` длинных значений ещё нет, потому что код, который их пишет, появится только в Task 2.

- [ ] **Step 6: PSR12 по новому файлу**

```sh
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 migrations/m261104_000000_widen_publications_setting_value.php
```

Ожидаем `A TOTAL OF 0 ERRORS`. Если снифф ругнулся на длину строки — разбиваем док-комментарий,
число предупреждений базовой линии этого файла не меняется.

- [ ] **Step 7: Закоммитить**

```sh
git add app/migrations/m261104_000000_widen_publications_setting_value.php
git commit -F .git/COMMIT_MSG
```

Текст `.git/COMMIT_MSG` (пишется файлом, `git add -A` запрещён):

```
feat(settings): колонка value настроек разращена до text — дефолтный промт стилизации длиннее 255 байт, и первая же строка LLM-блока не прошла бы базой
```

---

### Task 2: Два новых типа поля, признак `secret` и пять текстовых кодов

Сегодня `save()` знает `int`, `style` и списочный `else`
([PublicationSettingsService.php:448-490](file:///E:/SERVER/Work_awd/app/shared/Settings/Service/PublicationSettingsService.php#L448)),
`normalize()` — те же три (:602-635), вьюха рисует `int`/`date`/`style`/список
([settings.php:97-130](file:///E:/SERVER/Work_awd/app/views/site/settings.php#L97)).
В списочной ветке любое текстовое значение отвергается как «надо выбрать из списка», поэтому новым
типам нужна своя ветка во всех трёх местах — иначе не пройдёт первая же сохранённая строка промта.

**Files:**
- Modify: `app/shared/Settings/Service/PublicationSettingsService.php` (константы над `SCHEMA`, пять записей в `SCHEMA` после `activeTextStyle` (:242), ветка в `save()` перед `else` (:476), ветка в `normalize()` перед :629)
- Modify: `app/views/site/settings.php:106` (две ветки в цепочку `if`/`elseif`)
- Test: `app/tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php`
- Reference: `app/tests/Unit/shared/Settings/Service/PublicationSettingsServiceTextStyleTest.php` (шаблон), `InMemorySettingsRepository.php`, `ListedStylesStub.php` (фикстуры рядом, не пересоздавать)

**Interfaces:**
- Consumes: `SCHEMA` как единственное описание тюнингов; `defaults()` и `schema()` её читают, поэтому
  новые коды попадают в POST-форму и в `save()`-«все коды одним запросом» бесплатно.
- Produces: коды `llmBaseUrl`, `llmApiKey`, `llmModel`, `llmPromptSpelling`, `llmPromptStyle`;
  значения `'text'`/`'textarea'` в ключе `type`; признак `secret` у `llmApiKey`; ключ `pattern`
  со значением `'url'` у `llmBaseUrl`. Дальше их читают `LlmTextService` (Task 7) и `settings.php`.
  `PublicationSettingsService::defaults()` теперь отдаёт 23 кода, и тесты, которые строили
  `submitted()` как слияние с `defaults()`, продолжают проходить без правки.

- [ ] **Step 1: Написать красные тесты**

`app/tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Settings\Service;

use app\shared\Settings\Service\PublicationSettingsService;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class PublicationSettingsServiceLlmTest extends Unit
{
    /**
     * @param string[] $folders
     */
    private function service(
        InMemorySettingsRepository $repository,
        array $folders = ['Ватник в Америке'],
    ): PublicationSettingsService {
        return new PublicationSettingsService($repository, new ListedStylesStub($folders), '*/5 * * * *');
    }

    /**
     * The whole tunables form, with the defaults of the schema and whatever the test
     * cares about written over them.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function submitted(array $overrides): array
    {
        return array_merge(
            PublicationSettingsService::defaults(),
            ['partsOffsetMinutes' => '5'],
            $overrides,
        );
    }

    public function testSchemaHoldsTheFiveTextCodes(): void
    {
        $schema = PublicationSettingsService::schema();

        // Пять кодов этого задания; два числовых придут в Task 3 и расширят проверку до семи.
        $codes = [
            'llmBaseUrl',
            'llmApiKey',
            'llmModel',
            'llmPromptSpelling',
            'llmPromptStyle',
        ];

        foreach ($codes as $code) {
            $this->assertArrayHasKey($code, $schema, "Код «{$code}» не стоит в схеме.");
        }

        $this->assertSame('text', $schema['llmBaseUrl']['type']);
        $this->assertSame('text', $schema['llmModel']['type']);
        $this->assertSame('textarea', $schema['llmPromptSpelling']['type']);
        $this->assertSame('textarea', $schema['llmPromptStyle']['type']);
        $this->assertTrue($schema['llmApiKey']['secret']);
    }

    public function testDefaultsOfTheConnection(): void
    {
        $defaults = PublicationSettingsService::defaults();

        $this->assertSame('http://host.docker.internal:31415/v1', $defaults['llmBaseUrl']);
        $this->assertSame('', $defaults['llmApiKey']);
        $this->assertSame('auto', $defaults['llmModel']);
    }

    public function testPromptDefaultsCarryTheStylePlaceholder(): void
    {
        $defaults = PublicationSettingsService::defaults();

        $this->assertStringContainsString('{style}', $defaults['llmPromptStyle']);
        $this->assertStringNotContainsString('{style}', $defaults['llmPromptSpelling']);
        $this->assertGreaterThanOrEqual(20, mb_strlen($defaults['llmPromptSpelling']));
        $this->assertGreaterThanOrEqual(20, mb_strlen($defaults['llmPromptStyle']));
        $this->assertLessThanOrEqual(4000, mb_strlen($defaults['llmPromptStyle']));
    }

    public function testFormAcceptsATextAndATextareaValue(): void
    {
        $repository = new InMemorySettingsRepository();

        $this->service($repository)->save($this->submitted([
            'llmBaseUrl' => 'http://host.docker.internal:31415/v1',
            'llmPromptSpelling' => "Проверь текст.\nИ ничего не добавляй.",
        ]));

        $this->assertSame('http://host.docker.internal:31415/v1', $repository->values['llmBaseUrl']);
        $this->assertSame(
            "Проверь текст.\nИ ничего не добавляй.",
            $repository->values['llmPromptSpelling'],
            'Перенос строки внутри промта обязан дожить до базы.',
        );
    }

    public function testFormRefusesAnEmptyPrompt(): void
    {
        $repository = new InMemorySettingsRepository();

        try {
            $this->service($repository)->save($this->submitted(['llmPromptStyle' => '   ']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('«Промт стилизации» нельзя оставить пустым.', $e->getMessage());
        }

        $this->assertSame(0, $repository->saves);
    }

    public function testFormRefusesAPromptShorterThanTheBound(): void
    {
        $repository = new InMemorySettingsRepository();

        try {
            $this->service($repository)->save($this->submitted(['llmPromptSpelling' => 'Проверь.']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                '«Промт проверки орфографии» принимает от 20 до 4000 знаков, передано 8.',
                $e->getMessage(),
            );
        }
    }

    public function testFormRefusesAUrlThatIsNotAnAddress(): void
    {
        $repository = new InMemorySettingsRepository();

        try {
            $this->service($repository)->save($this->submitted(['llmBaseUrl' => 'host.docker.internal:31415']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                '«Адрес LLM» надо задать адресом вида http://хост[:порт]/путь, без пробелов.',
                $e->getMessage(),
            );
        }
    }

    public function testEmptyKeyInputKeepsTheStoredKey(): void
    {
        $repository = new InMemorySettingsRepository(['llmApiKey' => 'sk-секрет-из-базы']);

        $this->service($repository)->save($this->submitted(['llmApiKey' => '']));

        $this->assertSame('sk-секрет-из-базы', $repository->values['llmApiKey']);
        $this->assertSame(1, $repository->saves);
    }

    public function testAKeyOfItsOwnReplacesTheStoredOne(): void
    {
        $repository = new InMemorySettingsRepository(['llmApiKey' => 'старый']);

        $this->service($repository)->save($this->submitted(['llmApiKey' => 'новый']));

        $this->assertSame('новый', $repository->values['llmApiKey']);
    }

    public function testValueOfAnUnreadablePromptFallsBackToTheDefault(): void
    {
        $repository = new InMemorySettingsRepository(['llmPromptSpelling' => '   ']);

        $service = $this->service($repository);

        $this->assertSame(
            PublicationSettingsService::defaults()['llmPromptSpelling'],
            $service->value('llmPromptSpelling'),
            'Пустое хранимое значение не должно дойти до формы.',
        );
    }
}
```

- [ ] **Step 2: Прогнать и убедиться, что красно**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php'
```

Ожидаем падение на `testSchemaHoldsTheFiveTextCodes`: «Код «llmBaseUrl» не стоит в схеме.» — кодов
ещё нет. Остальные методы валятся по той же причине.

- [ ] **Step 3: Две константы с текстами промтов**

В `app/shared/Settings/Service/PublicationSettingsService.php`, непосредственно перед
`private const SCHEMA = [` (:88). `SCHEMA` — константа класса, многострочный текст в неё входит
склейкой:

```php
    /**
     * The work of the spell checker, written out so the settings page has something to
     * edit on the first run. The response contract is not in here: the code appends it,
     * so the format of the answer cannot be edited away.
     */
    private const DEFAULT_PROMPT_SPELLING =
        "Отредактируй присланный текст как корректор: исправь опечатки, орфографические\n"
        . "и пунктуационные ошибки, лишние и пропущенные пробелы, неверный регистр буквы\n"
        . "после точки.\n"
        . "Не меняй смысл, порядок слов, лексику, тон и длину: после правки должен читаться\n"
        . "тот же текст, только без ошибок.\n"
        . "Ничего не добавляй от себя: ни вступлений, ни пояснений, ни эмодзи, ни хештегов.\n"
        . "Выделение сохрани там, где оно стоит, и пересчитай его границы по исправленному тексту.\n"
        . "Формат ответа возьми из блока, приложенного к этому тексту.";

    /**
     * The work of the style transfer. `{style}` is replaced by the code with the name of
     * the folder the reader took from `activeTextStyle`; the sample itself travels in the
     * block the application appends after this prompt, and this text is what points the
     * model at that block.
     */
    private const DEFAULT_PROMPT_STYLE =
        "Ты пишешь публикацию за автора канала «{style}». К запросу приложен образец его\n"
        . "текстов: сначала прочитай его целиком и внимательно разбери — как построены\n"
        . "предложения и какой у них ритм, какие слова, жаргон, фразеологизмы и обращения\n"
        . "он использует, какая у него тональность и настроение, как он начинает и заканчивает\n"
        . "пост, как расставляет выделение и ссылки.\n"
        . "Потом перепиши присланный текст так, будто его написал этот автор: та же манера,\n"
        . "те же обороты, та же интонация.\n"
        . "Факты, имена, числа, адреса и порядок событий присланного текста сохраняй без\n"
        . "изменений. Длину держи близкой к исходной — не длиннее полутора исходных абзацев.\n"
        . "Выделение разбери дважды: оставь то, что стоит в присланном тексте и подходит\n"
        . "переписанному, и добавь своё там, где автор образца на таком же месте выделил бы, —\n"
        . "по закономерности, которую ты нашёл в образце.\n"
        . "Задача — стиль, а не оценка: даже скучный текст перепиши в манере автора.\n"
        . "Формат ответа возьми из блока, приложенного к этому тексту.";
```

- [ ] **Step 4: Четыре записи в `SCHEMA`**

Последней записью сейчас стоит `activeTextStyle` (:236-242, скобка `];` на :243). Вставить после её
закрывающей `],`:

```php
        'llmBaseUrl' => [
            'type' => 'text',
            'default' => 'http://host.docker.internal:31415/v1',
            'min' => 10,
            'max' => 255,
            'pattern' => 'url',
            'label' => 'Адрес LLM',
            'hint' => 'Базовый адрес OpenAI-совместимого прокси; запрос идёт на {адрес}/chat/completions. '
                . 'Из контейнера приложение видит хост именем host.docker.internal.',
        ],
        'llmApiKey' => [
            'type' => 'text',
            'default' => '',
            'min' => 0,
            'max' => 255,
            'secret' => true,
            'label' => 'Ключ LLM',
            'hint' => 'Ключ прокси в открытом виде хранится только в базе и никогда не выводится на страницу. '
                . 'Пустой ввод сохраняет то, что уже лежит.',
        ],
        'llmModel' => [
            'type' => 'text',
            'default' => 'auto',
            'min' => 1,
            'max' => 120,
            'label' => 'Модель LLM',
            'hint' => 'Алиас роутера прокси, а не имя модели: какое окно у «auto» и на что он меняется — '
                . 'дело роутера.',
        ],
        'llmPromptSpelling' => [
            'type' => 'textarea',
            'default' => self::DEFAULT_PROMPT_SPELLING,
            'min' => 20,
            'max' => 4000,
            'label' => 'Промт проверки орфографии',
            'hint' => 'Работа корректора. Формат ответа дописывает код: его здесь нельзя ни испортить, '
                . 'ни убрать.',
        ],
        'llmPromptStyle' => [
            'type' => 'textarea',
            'default' => self::DEFAULT_PROMPT_STYLE,
            'min' => 20,
            'max' => 4000,
            'label' => 'Промт стилизации',
            'hint' => 'Работа автора. {style} подставляется именем папки из «Активного стиля текста»; '
                . 'сам образец прикладывается к запросу приложением.',
        ],
```

Два `int`-кода добавляются в Task 3 — здесь их нет нарочно, чтобы тест шага 1 краснел ровно на
отсутствии кодов.

- [ ] **Step 5: Ветка `text`/`textarea` в `save()`**

В цепочке `if ($definition['type'] === 'int') { … } elseif ($definition['type'] === 'style') { … } else { … }`
(:459-483) вставить **перед** `} else {` (:476) два новых ответвления и признак `secret` — перед
`$values[$code] = $raw;` (:485):

```php
            } elseif ($definition['type'] === 'text' || $definition['type'] === 'textarea') {
                $min = (int)$definition['min'];
                $max = (int)$definition['max'];
                $length = mb_strlen($raw);
                if ($raw === '' && array_key_exists('secret', $definition)) {
                    // The form posts an empty key when nobody typed one: the value that
                    // is already stored is what the page means to keep.
                    $values[$code] = $this->value($code);
                    continue;
                }

                if ($raw === '') {
                    throw new InvalidArgumentException("«{$label}» нельзя оставить пустым.");
                }

                if ($length < $min || $length > $max) {
                    throw new InvalidArgumentException(
                        "«{$label}» принимает от {$min} до {$max} знаков, передано {$length}.",
                    );
                }

                if (array_key_exists('pattern', $definition) && !self::isUsableUrl($raw)) {
                    throw new InvalidArgumentException(
                        "«{$label}» надо задать адресом вида http://хост[:порт]/путь, без пробелов.",
                    );
                }
            } elseif ($definition['type'] === 'style') {
```

`$raw` ужеtrimmed на :457, поэтому `textarea` сохраняет `\n` внутри и режет только по краям.
`continue` идёт в начало `foreach`, который и так собирает `$values` сам.

- [ ] **Step 6: Приватный помощник адреса и ветка чтения**

После `isStyleListed()` (:594-597) вставить:

```php
    /**
     * Whether the text is an address the client could actually call: a scheme this
     * project speaks, a host, no space anywhere, no path tricks. The client glues
     * `/chat/completions` behind it, so a trailing slash is allowed and a query is not.
     */
    private static function isUsableUrl(string $raw): bool
    {
        if (preg_match('#^https?://[^\s/?\#]+(:\d{1,5})?(/[^\s]*)?$#', $raw) !== 1) {
            return false;
        }

        return strlen($raw) <= 255;
    }
```

Разделитель шаблона — `#`, и он же стоит внутри класса `[^…]`, поэтому там он экранирован (`\#`): без
экранирования PHP обрывает шаблон на первом `#` внутри класса и выдаёт
`preg_match(): Unknown modifier ']'`, а `preg_match` возвращает `false` — то есть каждый адрес, даже
правильный, кажется неверным. Проверено на этом же контейнере.

В `normalize()` (:602-635) вставить **перед** строкой `$options = $definition['type'] === 'date' ? …`
(:629), иначе каждое текстовое значение упрётся в списочную ветку и вернёт дефолт:

```php
        if ($definition['type'] === 'text' || $definition['type'] === 'textarea') {
            $raw = trim($raw);
            if ($raw === '') {
                // An empty prompt would send a request with no task in it, and an empty
                // base url would make every button of the form useless.
                return array_key_exists('secret', $definition) ? '' : $definition['default'];
            }

            if (array_key_exists('pattern', $definition) && !self::isUsableUrl($raw)) {
                return $definition['default'];
            }

            $max = (int)$definition['max'];
            if (mb_strlen($raw) > $max) {
                return mb_substr($raw, 0, $max);
            }

            return $raw;
        }

```

Значение, урезанное на чтении, при следующем `save()` придёт уже урезанным и пройдёт проверку
длин — так и задумано: `normalize()` бережёт форму, `save()` бережёт базу.

- [ ] **Step 7: Две ветки вьюхи**

В `app/views/site/settings.php` после закрывающего `</div>` int-ветки (:105), перед
`<?php elseif ($definition['type'] === 'date'): ?>` (:106):

```php
                            <?php elseif ($definition['type'] === 'text'): ?>
                                <input type="text" class="form-control" id="setting-<?= $code ?>"
                                       name="settings[<?= $code ?>]"
                                       <?php if (array_key_exists('secret', $definition)): ?>
                                           value="" placeholder="Оставлено пустым = не менять"
                                       <?php else: ?>
                                           value="<?= Html::encode($settings[$code]) ?>" required
                                       <?php endif ?>
                                       maxlength="<?= (int)$definition['max'] ?>">
                            <?php elseif ($definition['type'] === 'textarea'): ?>
                                <textarea class="form-control" id="setting-<?= $code ?>"
                                          name="settings[<?= $code ?>]"
                                          rows="8" maxlength="<?= (int)$definition['max'] ?>"
                                          required><?= Html::encode($settings[$code]) ?></textarea>
```

Хранимый ключ в разметку не попадает: ветка `secret` печатает `value=""` всегда. `required` стоит
только в ветке без `secret` — пустой ключ это штатный ввод («не менять»), и вешанное на обе ветки
правило не давало бы сохранить форму, пока поле ключа пусто. `$bounds` (:25-36)
строится только для `int` и не меняется.

- [ ] **Step 8: Прогнать тесты зелёными**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php'
```

Ожидаем `OK (10 tests, …)`. Затем весь набор и сравнить итог с базовой линией, снятой в этом же
прогоне до правки (`372 tests, 1228 assertions` на 2026-11-04) — дельта обязана равняться ровно
числу новых тестов.

- [ ] **Step 9: Гейты файла**

```sh
docker compose exec -T app php -l shared/Settings/Service/PublicationSettingsService.php
docker compose exec -T app php -l views/site/settings.php
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 --report=source shared/Settings/Service/PublicationSettingsService.php views/site/settings.php
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем: `php -l` — без ошибок; phpcs по сервису — 0 ошибок (число предупреждений `Generic.Files.LineLength`
записать и сверить с копией `git show HEAD:…`: дельта равна числу добавленных строк длиннее 120
знаков); phpstan — ровно `5` ошибок прежних четырёх имён, новых не появилось (`views/` в путях
анализа нет, поэтому вьюха phpstan не касается вовсе).

- [ ] **Step 10: Живой показ страницы настроек**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app php yii migrate --interactive=0
```

Затем в браузере `http://localhost:8081/settings`: пять новых строк видны, в поле «Ключ LLM»
стоит пустой `input` с placeholder «Оставлено пустым = не менять», промты — textarea с текстом из
дефолта, переносы строк читается. Сохранить форму с пустым ключом и убедиться, что `psql`
показывает `llmApiKey` = `` (пусто), а с набранным ключом — что строка заполнилась.

- [ ] **Step 11: Закоммитить**

```sh
git add app/shared/Settings/Service/PublicationSettingsService.php \
        app/views/site/settings.php \
        app/tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(settings): два текстовых типа поля и признак ключа — адрес, ключ, модель и два промта в схеме, свои ветки в save(), normalize() и вьюхе
```

---

### Task 3: Два числовых кода — потолок образца и таймаут запроса

**Files:**
- Modify: `app/shared/Settings/Service/PublicationSettingsService.php` (две записи в конец `SCHEMA`, сразу после `llmPromptStyle` из Task 2)
- Test: `app/tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php` (три новых метода в тот же класс и расширение существующей проверки схемы до семи кодов)

**Interfaces:**
- Consumes: int-ветку `save()` (:459-471) и `normalize()` (:606-619) — обе уже умеют границы, `unit`
  и сообщение «принимает значения от … до …».
- Produces: `llmStyleSampleChars` (40000, 2000–200000, «знаков») и `llmTimeoutSeconds`
  (90, 10–110, «секунд»); их читают `TextStyleSampleReader` через `LlmTextService` (Task 7-8) и
  `OpenAiCompatibleClient` (Task 6) как `$settings->intValue(...)`. Верхняя граница 110 выбрана не
  для порядка: `fastcgi_read_timeout 120s`
  ([docker/nginx/default.conf:22](file:///E:/SERVER/Work_awd/docker/nginx/default.conf#L22)) и
  `max_execution_time=120` ([docker/php/php.ini:4](file:///E:/SERVER/Work_awd/docker/php/php.ini#L4)):
  синхронный вызов обязан закончиться до них.

- [ ] **Step 1: Дописать красные тесты в тот же класс**

```php
    public function testTheTwoNumberCodesStandAtTheirDefaults(): void
    {
        $defaults = PublicationSettingsService::defaults();

        $this->assertSame('40000', $defaults['llmStyleSampleChars']);
        $this->assertSame('90', $defaults['llmTimeoutSeconds']);
    }

    public function testSampleCeilingIsClampedOnARead(): void
    {
        $low = $this->service(new InMemorySettingsRepository(['llmStyleSampleChars' => '100']));
        $high = $this->service(new InMemorySettingsRepository(['llmStyleSampleChars' => '999999']));

        $this->assertSame(2000, $low->intValue('llmStyleSampleChars'));
        $this->assertSame(200000, $high->intValue('llmStyleSampleChars'));
    }

    public function testFormRefusesATimeoutOverTheWebBudget(): void
    {
        $repository = new InMemorySettingsRepository();

        try {
            $this->service($repository)->save($this->submitted(['llmTimeoutSeconds' => '180']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                '«Таймаут запроса к LLM» принимает значения от 10 до 110, передано 180.',
                $e->getMessage(),
            );
        }

        $this->assertSame(0, $repository->saves);
    }
```

Тем же шагом Step 1 расширяет существующую проверку схемы: в список `$codes` метода
`testSchemaHoldsTheFiveTextCodes` дописать два числовых кода (`'llmStyleSampleChars'`,
`'llmTimeoutSeconds'`), переименовать сам метод в `testSchemaHoldsTheSevenCodes` и поправить его
комментарий — кодов стало семь. Строки про `type` и `secret` остаются как есть: оба новых кода `int`,
и их границы проверяют тесты выше, а не эта sweep-проверка.

- [ ] **Step 2: Прогнать — ожидаем `13 tests`, провалов на четырёх**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php'
```

Ожидаем 4 падения: `Undefined array key "llmStyleSampleChars"` в трёх новых методах и «Код
«llmStyleSampleChars» не стоит в схеме.» в расширенной проверке схемы.

- [ ] **Step 3: Две записи в `SCHEMA`**

```php
        'llmStyleSampleChars' => [
            'type' => 'int',
            'default' => '40000',
            'min' => 2000,
            'max' => 200000,
            'unit' => 'знаков',
            'label' => 'Размер образца стиля',
            'hint' => 'Сколько знаков текста образца улезает в запрос. Выборка рвётся по границе '
                . 'сообщения, поэтому фактическое число всегда чуть меньше.',
        ],
        'llmTimeoutSeconds' => [
            'type' => 'int',
            'default' => '90',
            'min' => 10,
            'max' => 110,
            'unit' => 'секунд',
            'label' => 'Таймаут запроса к LLM',
            'hint' => 'Один HTTP-вызов к прокси, считая его ретраи. Дольше 110 секунд задать нельзя: '
                . 'веб-сервер обрывает запрос на 120-й, и страница потеряла бы ответ вместе с текстом части.',
        ],
```

`min`/`max`/`unit` — те же ключи, что у 15 существующих `int`-кодов (из 18 кодов до этой ревизии
пятнадцать были `int`, остальные — `date`, `order` и `style`); `$bounds` вьюхи
(:25-36) строит нижнюю границу из `minimumOf()`, и для этих двух кодов она равна числу из схемы.

- [ ] **Step 4: Прогнать зелёными и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php'
docker compose exec -T app php -l shared/Settings/Service/PublicationSettingsService.php
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 --report=source shared/Settings/Service/PublicationSettingsService.php
```

Ожидаем `OK (13 tests, …)`; две hint-строки разорваны склейкой, и в этом прогоне ни одна добавленная
строка не перешагнула 120 знаков (самая длинная — 110), поэтому число предупреждений
`Generic.Files.LineLength` по файлу остаётся базовым — 7. Если живой прогон даст больше, разорвать
длинную строку и записать замер в отчёт.

- [ ] **Step 5: Страница настроек показывает два числа**

Открыть `http://localhost:8081/settings`: два `input type=number` с `min`/`max`/`step=1` и
`input-group-text` «знаков» / «секунд». Сохранить 40000 и 90 — форма проходит.

- [ ] **Step 6: Закоммитить**

```sh
git add app/shared/Settings/Service/PublicationSettingsService.php \
        app/tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(settings): потолок образца и таймаут LLM двумя числовыми кодами — 2000..200000 знаков и 10..110 секунд под бюджет веб-сервера
```

---

### Task 4: Три DTO и интерфейс клиента

Здесь появляется граница, на которой потом тесты подменяют сеть фикстурой: `LlmClientInterface` с
одним методом. Каталог `Domain/` в проекте не заводится — у `Forum`, `Gallery`, `Publications`,
`Settings`, `Telegram` только `Contract/`, `Dto/`, `Infrastructure/`, `Service/`.

**Files:**
- Create: `app/shared/Llm/Contract/LlmClientInterface.php`
- Create: `app/shared/Llm/Dto/LlmReply.php`
- Create: `app/shared/Llm/Dto/LlmAnswer.php`
- Create: `app/shared/Llm/Dto/StyleSampleData.php`
- Test: `app/tests/Unit/shared/Llm/Dto/LlmReplyTest.php`
- Reference: `app/shared/Telegram/Contract/TelegramChannelClientInterface.php` (форма интерфейса),
  `app/shared/Publications/Dto/PublicationDataTest.php` (форма теста DTO)

**Interfaces:**
- Consumes: `MessageEntities` из `app/shared/Telegram/Dto` — его уже используют DTO и сервисы
  `Publications`, так что кросс-модульное использование DTO в этом проекте норма.
- Produces:
  - `LlmClientInterface::chat(array $messages, int $timeout): LlmReply` — `$messages` это
    `array<int, array{role: string, content: string}>`; бросает `LlmRequestException` (Task 6).
  - `LlmReply::fromApi(array $decoded): ?self` — поля `text`, `finishReason`, `promptTokens`,
    `completionTokens`; `null`, когда в payload нет сообщения.
  - `LlmAnswer` — поля `text` (string) и `entities` (`MessageEntities`), метод `formattingJson(): string`.
  - `StyleSampleData` — поля `styleName` (string), `chars` (int), `block` (string).
  - `LlmRequestException extends RuntimeException` появится в Task 6, в `Infrastructure/` — там,
    где лежат три существующих исключения проекта.

- [ ] **Step 1: Написать красный тест разбора payload**

`app/tests/Unit/shared/Llm/Dto/LlmReplyTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Dto;

use app\shared\Llm\Dto\LlmReply;
use Codeception\Test\Unit;

final class LlmReplyTest extends Unit
{
    /**
     * @param array<mixed> $decoded
     */
    private function reply(array $decoded): ?LlmReply
    {
        return LlmReply::fromApi($decoded);
    }

    public function testReadsTheFirstChoiceAndTheUsage(): void
    {
        $reply = $this->reply([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => "Готово.\nТекст."], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1234, 'completion_tokens' => 56],
        ]);

        $this->assertNotNull($reply, 'Ответ с сообщением обязан разобраться.');
        $this->assertSame("Готово.\nТекст.", $reply->text);
        $this->assertSame('stop', $reply->finishReason);
        $this->assertSame(1234, $reply->promptTokens);
        $this->assertSame(56, $reply->completionTokens);
    }

    public function testAnEmptyContentIsStillAnAnswerOfItsOwn(): void
    {
        $reply = $this->reply(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']]]);

        $this->assertNotNull($reply);
        $this->assertSame('', $reply->text);
        $this->assertSame('length', $reply->finishReason);
        $this->assertSame(0, $reply->promptTokens);
        $this->assertSame(0, $reply->completionTokens);
    }

    public function testGivesUpQuietlyOnAPayloadWithoutAChoice(): void
    {
        $this->assertNull($this->reply([]));
        $this->assertNull($this->reply(['choices' => []]));
        $this->assertNull($this->reply(['choices' => [['message' => []]]]));
        $this->assertNull($this->reply(['choices' => [['message' => ['content' => null]]]]));
        $this->assertNull($this->reply(['error' => ['message' => 'All 3 routed attempt(s) failed']]));
    }

    public function testTreatsANonStringContentAsNoAnswer(): void
    {
        $this->assertNull($this->reply(['choices' => [['message' => ['content' => 42]]]]));
        $this->assertNull($this->reply([
            'choices' => [['message' => ['content' => ['text' => 'массив вместо строки']]]],
        ]));
    }

    public function testANonScalarFinishReasonLeavesAnEmptyReason(): void
    {
        // `finish_reason` иногда приезжает объектом. Голый `(string)` споткнулся бы на нём
        // об `Object of class … could not be converted to string`, хотя текст части цел —
        // отказ из-за причины дороже самой причины.
        $reply = $this->reply([
            'choices' => [['message' => ['content' => 'Ответ.'], 'finish_reason' => ['a' => 'b']]],
        ]);

        $this->assertNotNull($reply, 'Причина не решает, есть ли ответ.');
        $this->assertSame('Ответ.', $reply->text);
        $this->assertSame('', $reply->finishReason);
    }

    public function testKeepsAStreamAnswerOfTheReasoningModel(): void
    {
        // The proxy answers a `reasoning` model with its thinking in a separate field;
        // only `content` may reach the form, and the reason it stopped has to survive.
        $reply = $this->reply([
            'choices' => [
                ['message' => ['content' => 'Ответ.'], 'finish_reason' => 'stop'],
                'reasoning' => 'Длинные рассуждения…',
            ],
        ]);

        $this->assertNotNull($reply);
        $this->assertSame('Ответ.', $reply->text);
    }
}
```

- [ ] **Step 2: Убедиться, что прогон падает на отсутствующем классе**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Dto/LlmReplyTest.php'
```

Ожидаем `Class "app\shared\Llm\Dto\LlmReply" not found`.

- [ ] **Step 3: Написать `LlmReply`**

`app/shared/Llm/Dto/LlmReply.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * One answer of the chat endpoint: the message, the reason the model stopped, and
 * the usage the proxy reported. The reading of the payload lives here because the
 * shape is the API's, not this project's, and a caller that gets a reply never
 * has to look inside a JSON tree again.
 */
final readonly class LlmReply
{
    public function __construct(
        public string $text,
        public string $finishReason,
        public int $promptTokens,
        public int $completionTokens,
    ) {
    }

    /**
     * The first choice of a decoded response, or nothing at all when the payload
     * carries no message: an error object, an empty `choices`, a null content. The
     * caller turns that into a refusal with the reason of its own.
     *
     * @param array<mixed> $decoded
     */
    public static function fromApi(array $decoded): ?self
    {
        $choice = isset($decoded['choices'][0]) ? $decoded['choices'][0] : null;
        if (!is_array($choice)) {
            return null;
        }

        $message = isset($choice['message']) ? $choice['message'] : null;
        if (!is_array($message) || !isset($message['content']) || !is_string($message['content'])) {
            return null;
        }

        $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : [];
        $finishReason = isset($choice['finish_reason']) ? $choice['finish_reason'] : null;

        return new self(
            $message['content'],
            is_scalar($finishReason) ? (string)$finishReason : '',
            isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : 0,
            isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0,
        );
    }
}
```

- [ ] **Step 4: Написать `LlmAnswer` и `StyleSampleData`**

`app/shared/Llm/Dto/LlmAnswer.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

use app\shared\Telegram\Dto\MessageEntities;

/**
 * What one operation hands back to the form: the text and the marks that belong to
 * it. The entities are already checked against this text — an answer whose marks
 * stick out of it never becomes an `LlmAnswer` at all.
 */
final readonly class LlmAnswer
{
    public function __construct(
        public string $text,
        public MessageEntities $entities,
    ) {
    }

    /**
     * The marks in the shape the hidden field of a part holds, so the answer is
     * written into the very field the form already serialises.
     */
    public function formattingJson(): string
    {
        return $this->entities->toJson();
    }
}
```

`app/shared/Llm/Dto/StyleSampleData.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * The block of the sample, already assembled: whose style it is, how many
 * characters it carries, and the text with its own heading. The service pastes
 * `block` behind the prompt; the heading is part of it, so the model is told
 * what it is looking at even when the writer removed `{style}` from the prompt.
 */
final readonly class StyleSampleData
{
    public function __construct(
        public string $styleName,
        public int $chars,
        public string $block,
    ) {
    }
}
```

- [ ] **Step 5: Написать интерфейс клиента**

`app/shared/Llm/Contract/LlmClientInterface.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Contract;

use app\shared\Llm\Dto\LlmReply;
use app\shared\Llm\Infrastructure\LlmRequestException;

/**
 * The only thing this project asks of a model: send these messages, give me the
 * answer. A unit test replaces it with a fixture, so no unit ever reaches the
 * network, and the timeout of every call is the setting the page edits rather
 * than a number the transport chose.
 */
interface LlmClientInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @throws LlmRequestException when the proxy refused, timed out or answered nonsense
     */
    public function chat(array $messages, int $timeout): LlmReply;
}
```

`LlmRequestException` в этом же коммите, чтобы `@throws` вёл в класс, который существует.
`app/shared/Llm/Infrastructure/LlmRequestException.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use RuntimeException;

/**
 * Why a call to the proxy did not bring an answer: the key was refused, the
 * address did not answer, the budget ran out, or the payload had no message in
 * it. The sentence is written for the person standing at the form, and the form
 * shows it unchanged.
 */
final class LlmRequestException extends RuntimeException
{
}
```

- [ ] **Step 6: Прогнать зелёными и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Dto/LlmReplyTest.php'
docker compose exec -T app sh -lc 'for f in shared/Llm/Dto/LlmReply.php shared/Llm/Dto/LlmAnswer.php shared/Llm/Dto/StyleSampleData.php shared/Llm/Contract/LlmClientInterface.php shared/Llm/Infrastructure/LlmRequestException.php; do php -l "$f"; done'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (6 tests, 22 assertions)`; пять `No syntax errors detected`; phpcs — 0 ошибок на каталог;
phpstan — прежние 5 ошибок, ни одной новой.

- [ ] **Step 7: Закоммитить**

```sh
git add app/shared/Llm/Dto/LlmReply.php app/shared/Llm/Dto/LlmAnswer.php \
        app/shared/Llm/Dto/StyleSampleData.php app/shared/Llm/Contract/LlmClientInterface.php \
        app/shared/Llm/Infrastructure/LlmRequestException.php \
        app/tests/Unit/shared/Llm/Dto/LlmReplyTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): три DTO и интерфейс клиента — разбор ответа прокси, ответ с метками, блок образца и одна граница, на которой юниты подменяют сеть фикстурой
```

---

### Task 5: Читатель образца стиля

Образец читает приложение: у прокси нет файлового API, а модель не видит диск
(4-й раздел спека). Этот класс — единственное место, которое знает форму `result.json`,
обе разнотипные формы `text` и то, что файл упорядочен от старых сообщений к новым.

**Files:**
- Create: `app/shared/Llm/Service/TextStyleSampleReader.php`
- Create: `app/shared/Llm/Infrastructure/StyleSampleUnavailableException.php`
- Test: `app/tests/Unit/shared/Llm/Service/TextStyleSampleReaderTest.php`
- Reference: `app/shared/Settings/Infrastructure/DirectoryTextStyleCatalog.php:19-43` — та же форма
  конструктора с путём и `defaultPath()`/`fromEnvironment()`
- Reference: `app/tests/Unit/shared/Settings/Infrastructure/DirectoryTextStyleCatalogTest.php:20-56` —
  временный каталог в `_before`, `purgeTree()` в `_after`
- Reference: `styles/Ватник в Америке/result.json` — живой корпус: 1707 сообщений, `text` строкой в
  539 и массивом в 1168

**Interfaces:**
- Consumes: `StyleSampleData` из Task 4.
- Produces:
  - `TextStyleSampleReader::__construct(string $path)` — корень каталога, тот же, что держит
    `DirectoryTextStyleCatalog`; в контейнере `/var/www/styles`.
  - `TextStyleSampleReader::has(string $styleName): bool` — есть ли у папки читаемый `result.json`;
    его спрашивает гашение ручки «Стилизовать» (7-й раздел спека).
  - `TextStyleSampleReader::read(string $styleName, int $ceiling): StyleSampleData` — бросает
    `StyleSampleUnavailableException` с именем папки, когда папки нет, файл не читается, в нём нет
    массива `messages`, или выборка вышла короче `MIN_SAMPLE_CHARS`.
  - `TextStyleSampleReader::MIN_SAMPLE_CHARS = 200` — публичная константа: на неё ссылается тест
    отказа и на неё же ссылается сообщение в форме.

- [ ] **Step 1: Написать красный тест**

`app/tests/Unit/shared/Llm/Service/TextStyleSampleReaderTest.php` — каркас временного каталога
повторяет `DirectoryTextStyleCatalogTest.php` дословно (имя папки шаблона меняется на `llm-sample-`):

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use app\shared\Llm\Service\TextStyleSampleReader;
use Codeception\Test\Unit;

final class TextStyleSampleReaderTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private string $_root = '';

    protected function _before(): void
    {
        parent::_before();

        $root = sys_get_temp_dir() . '/llm-sample-' . getmypid() . '-' . uniqid('', false);
        $this->_root = $root;
        mkdir($root, 0777, true);
    }

    protected function _after(): void
    {
        parent::_after();

        $this->purgeTree($this->_root);
    }

    private function purgeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->purgeTree($full);
                continue;
            }

            unlink($full);
        }

        rmdir($path);
    }

    /**
     * marker + ':' + 250 знаков 'а' — сообщение гарантированно проходит порог
     * образца по одиночке (MIN_SAMPLE_CHARS = 200) и остаётся различимым в
     * склеенном блоке.
     */
    private function long(string $marker): string
    {
        return $marker . ':' . str_repeat('а', 250);
    }

    private function writeFixture(array $messages): TextStyleSampleReader
    {
        mkdir($this->_root . '/' . self::STYLE, 0777, true);
        file_put_contents(
            $this->_root . '/' . self::STYLE . '/result.json',
            (string) json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE),
        );

        return new TextStyleSampleReader($this->_root);
    }
```

Дальше тринадцать методов того же класса:

```php
    public function testBothShapesOfTextReachTheBlock(): void
    {
        $reader = $this->writeFixture([
            ['text' => $this->long('старое')],
            ['text' => [['type' => 'bold', 'text' => $this->long('жирное')], ' ', $this->long('новое')]],
        ]);

        $sample = $reader->read(self::STYLE, 40000);

        $this->assertStringContainsString($this->long('старое'), $sample->block);
        $this->assertStringContainsString($this->long('жирное') . ' ' . $this->long('новое'), $sample->block);
        $this->assertStringContainsString(
            "\n\n---\n\n",
            $sample->block,
            'Заголовок обещает модели разделитель; склейка обязана его печатать, а не склеивать подряд.',
        );
    }

    public function testAnArrayGluesItsPartsAndSkipsWhatCarriesNoText(): void
    {
        $reader = $this->writeFixture([
            [
                'text' => [
                    'строкой',
                    ['type' => 'bold', 'text' => $this->long('меткой')],
                    ['type' => 'unknown'],
                    7,
                    ['type' => 'italic', 'text' => 42],
                    null,
                ],
            ],
        ]);

        $sample = $reader->read(self::STYLE, 40000);

        $this->assertStringContainsString(
            'строкой' . $this->long('меткой'),
            $sample->block,
            'Части склеиваются без разделителя, как они склеены в самом сообщении.',
        );
        $this->assertStringNotContainsString(
            'unknown',
            $sample->block,
            'Часть без текста пропущена, а не подставлена пустой или своим типом.',
        );
        // Число знаков — единственный страж для частей-чисел: каст `(string)` дописал бы
        // «7» и «42» в склейку, и проверка длины поймала бы это, а проверка на вхождение
        // цифры могла бы столкнуться с самой цифрой в заголовке блока.
        $this->assertSame(mb_strlen('строкой' . $this->long('меткой')), $sample->chars);
    }

    public function testTheNewestMessageComesFirst(): void
    {
        $reader = $this->writeFixture([
            ['text' => $this->long('первое')],
            ['text' => $this->long('второе')],
            ['text' => $this->long('третье')],
        ]);

        $block = $reader->read(self::STYLE, 40000)->block;
        $oldest = mb_strpos($block, $this->long('первое'));
        $middle = mb_strpos($block, $this->long('второе'));
        $newest = mb_strpos($block, $this->long('третье'));

        // Без этих трёх `mb_strpos() === false` превращался бы в `0` после каста, и
        // пропущенное сообщение выглядело бы самым свежим.
        $this->assertNotFalse($oldest, 'Самое старое сообщение обязано влезть в выборку.');
        $this->assertNotFalse($middle);
        $this->assertNotFalse($newest);
        $this->assertLessThan($middle, $newest, 'Файл идёт от старых к новым, значит свежее в блоке стоит раньше.');
        $this->assertLessThan($oldest, $middle);
    }

    public function testTheCeilingCutsOnAMessageBoundary(): void
    {
        $one = mb_strlen($this->long('первое'));
        $reader = $this->writeFixture([
            ['text' => $this->long('первое')],
            ['text' => $this->long('второе')],
            ['text' => $this->long('третье')],
        ]);

        // Потолок не кратен сообщению: два сообщения влезают, а остаток (100 знаков) —
        // нет. Реализация, которая добивает потолок серединой третьего, здесь отдала бы
        // `$one * 2 + 100` и кусок «первое:…»; граница по сообщению отдаёт `$one * 2`.
        $sample = $reader->read(self::STYLE, $one * 2 + 100);

        $this->assertSame($one * 2, $sample->chars);
        $this->assertStringContainsString($this->long('третье'), $sample->block);
        $this->assertStringContainsString($this->long('второе'), $sample->block);
        $this->assertStringNotContainsString($this->long('первое'), $sample->block);
        $this->assertStringNotContainsString(
            'первое',
            $sample->block,
            'Сообщение не переносится в выборку серединой — ни хвоста, ни начала.',
        );
    }

    public function testAMessageThatDoesNotFitEndsTheSampleRightThere(): void
    {
        $one = mb_strlen($this->long('новое'));
        $reader = $this->writeFixture([
            ['text' => $this->long('старое')],
            ['text' => str_repeat('д', 1000)],
            ['text' => $this->long('новое')],
        ]);

        // По спецификации выборка «обрывается на границе сообщения». Значит сообщение,
        // которое не влезает в остаток потолка, обрывает выборку, а не перескакивается:
        // самое старое «старое» в блок не попадает. Реализация на `continue`
        // отдала бы `$one * 2`.
        $sample = $reader->read(self::STYLE, $one * 2 + 100);

        $this->assertSame($one, $sample->chars);
        $this->assertStringContainsString($this->long('новое'), $sample->block);
        $this->assertStringNotContainsString('старое', $sample->block);
        $this->assertStringNotContainsString('ддд', $sample->block);
    }

    public function testWhitespaceInsideAMessageIsCompressed(): void
    {
        $reader = $this->writeFixture([
            ['text' => "первое\n\n   \t" . $this->long('хвост')],
            ['text' => $this->long('второе')],
        ]);

        $this->assertStringContainsString(
            'первое ' . $this->long('хвост'),
            $reader->read(self::STYLE, 40000)->block,
        );
    }

    public function testTheBlockNamesTheFolderAndCountsTheCharacters(): void
    {
        $reader = $this->writeFixture([['text' => $this->long('единственное')]]);

        $sample = $reader->read(self::STYLE, 40000);
        $expected = mb_strlen($this->long('единственное'));

        $this->assertSame(self::STYLE, $sample->styleName);
        $this->assertSame($expected, $sample->chars);
        $this->assertStringContainsString('Ватник в Америке', $sample->block);
        $this->assertStringContainsString((string) $expected, $sample->block);
        $this->assertStringContainsString('конец образца', $sample->block);
    }

    public function testHasAnswersForAFolderThatIsThereAndOneThatIsNot(): void
    {
        $reader = $this->writeFixture([['text' => $this->long('единственное')]]);

        $this->assertTrue($reader->has(self::STYLE));
        $this->assertFalse($reader->has('g_jobbot'));
        $this->assertFalse($reader->has('ТакойПапкиНет'));
    }

    public function testAnAbsentFolderIsRefusedByName(): void
    {
        $reader = new TextStyleSampleReader($this->_root);

        try {
            $reader->read('ТакойПапкиНет', 40000);
            $this->fail('Отказ на отсутствующую папку не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString('ТакойПапкиНет', $e->getMessage());
        }
    }

    public function testAFileThatIsNotJsonIsRefused(): void
    {
        mkdir($this->_root . '/' . self::STYLE, 0777, true);
        file_put_contents($this->_root . '/' . self::STYLE . '/result.json', 'не json');

        $reader = new TextStyleSampleReader($this->_root);

        try {
            $reader->read(self::STYLE, 40000);
            $this->fail('Отказ на неразбираемый файл не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString(self::STYLE, $e->getMessage());
        }
    }

    public function testAFileWithoutMessagesIsRefused(): void
    {
        mkdir($this->_root . '/' . self::STYLE, 0777, true);
        file_put_contents(
            $this->_root . '/' . self::STYLE . '/result.json',
            (string) json_encode(['version' => 1], JSON_UNESCAPED_UNICODE),
        );

        $reader = new TextStyleSampleReader($this->_root);

        try {
            $reader->read(self::STYLE, 40000);
            $this->fail('Отказ на файл без messages не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString('messages', $e->getMessage());
        }
    }

    public function testASampleShorterThanTheThresholdIsRefused(): void
    {
        $reader = $this->writeFixture([['text' => 'совсем коротко']]);

        $this->assertLessThan(TextStyleSampleReader::MIN_SAMPLE_CHARS, mb_strlen('совсем коротко'));
        try {
            $reader->read(self::STYLE, 40000);
            $this->fail('Отказ на короткий образец не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString(self::STYLE, $e->getMessage());
            $this->assertStringContainsString(
                (string) TextStyleSampleReader::MIN_SAMPLE_CHARS,
                $e->getMessage(),
                'Отказ называет порог, иначе человек не поймёт, до скольких знаков доращивать папку.',
            );
        }
    }

    public function testASampleOfExactlyTheThresholdIsAccepted(): void
    {
        $reader = $this->writeFixture([['text' => str_repeat('а', TextStyleSampleReader::MIN_SAMPLE_CHARS)]]);

        // Порог входит в выборку: реализация на `<=` отказала бы ровно на 200 знаках.
        $sample = $reader->read(self::STYLE, 40000);

        $this->assertSame(TextStyleSampleReader::MIN_SAMPLE_CHARS, $sample->chars);
        $this->assertStringContainsString(str_repeat('а', 200), $sample->block);
    }
}
```

- [ ] **Step 2: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/TextStyleSampleReaderTest.php'
```

Ожидаем `Failed to load class ... TextStyleSampleReaderTest` (нет классов) — красный до того, как
написан реализатор.

- [ ] **Step 3: Написать исключение**

`app/shared/Llm/Infrastructure/StyleSampleUnavailableException.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use RuntimeException;

/**
 * The sample could not be assembled: the folder of the chosen style is not
 * there, its export does not read, or what reads is too short to call a style.
 * The sentence names the folder, because that is what the person at the form
 * has to go and look at.
 */
final class StyleSampleUnavailableException extends RuntimeException
{
}
```

- [ ] **Step 4: Написать читатель**

`app/shared/Llm/Service/TextStyleSampleReader.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Dto\StyleSampleData;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;

/**
 * The sample of a writing style, taken from the folder of exported Telegram
 * messages that stands under the catalog.
 *
 * The proxy has no file API and the model sees no disk, so the application
 * reads the folder itself and sends the chosen slice as a block behind the
 * prompt.
 *
 * An export keeps `text` in two shapes: a plain string, and the array of parts
 * a marked message carries. Both are glued here, and a part the reader has no
 * text for is skipped rather than replaced by an empty one. Whitespace inside a
 * message collapses, so the block costs the request only the characters that
 * carry sense.
 *
 * The file runs from the oldest message to the newest — the first post of
 * `Ватник в Америке` stands in December 2017 — so the sample is taken from the
 * end backwards: what the author writes now weighs more than what they wrote
 * nine years ago. The ceiling counts characters with `mb_strlen` and stops on a
 * message boundary; a message taken by halves would break both its sense and
 * the marks of an array-shaped `text`.
 *
 * The style name arrives from the settings, where `save()` has already matched
 * it against the catalog, so it is a folder that exists rather than a path a
 * caller invented.
 */
final class TextStyleSampleReader
{
    public const MIN_SAMPLE_CHARS = 200;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Whether this style has a sample that reads. `read()` asks the stricter
     * question, because its refusal has to name what exactly is missing.
     */
    public function has(string $styleName): bool
    {
        return is_readable($this->sampleFile($styleName));
    }

    /**
     * @throws StyleSampleUnavailableException when there is nothing to sample
     */
    public function read(string $styleName, int $ceiling): StyleSampleData
    {
        $file = $this->sampleFile($styleName);
        if (!is_file($file)) {
            throw new StyleSampleUnavailableException(sprintf(
                'Папки с образцом «%s» в каталоге стилей нет.',
                $styleName,
            ));
        }

        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            throw new StyleSampleUnavailableException(sprintf(
                'Файл образца папки «%s» не читается.',
                $styleName,
            ));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['messages']) || !is_array($decoded['messages'])) {
            throw new StyleSampleUnavailableException(sprintf(
                'Файл образца папки «%s» не разбирается: в нём нет массива messages.',
                $styleName,
            ));
        }

        $taken = [];
        $chars = 0;
        foreach (array_reverse($decoded['messages'], true) as $message) {
            if (!is_array($message) || !isset($message['text'])) {
                continue;
            }

            $text = $this->textOf($message['text']);
            if ($text === '') {
                continue;
            }

            $length = mb_strlen($text);
            if ($chars + $length > $ceiling) {
                break;
            }

            $chars += $length;
            $taken[] = $text;
        }

        if ($chars < self::MIN_SAMPLE_CHARS) {
            throw new StyleSampleUnavailableException(sprintf(
                'В образце папки «%s» всего %d знаков — нужно не меньше %d.',
                $styleName,
                $chars,
                self::MIN_SAMPLE_CHARS,
            ));
        }

        return new StyleSampleData($styleName, $chars, $this->block($styleName, $chars, $taken));
    }

    /**
     * One message, whichever shape its text arrived in.
     */
    private function textOf(mixed $value): string
    {
        if (is_string($value)) {
            return $this->tidy($value);
        }

        if (!is_array($value)) {
            return '';
        }

        $pieces = [];
        foreach ($value as $part) {
            if (is_string($part)) {
                $pieces[] = $part;
                continue;
            }

            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $pieces[] = $part['text'];
            }
        }

        return $this->tidy(implode('', $pieces));
    }

    private function tidy(string $text): string
    {
        $compressed = preg_replace('~\s+~u', ' ', $text);

        return trim((string) $compressed);
    }

    /**
     * @param string[] $taken newest first
     */
    private function block(string $styleName, int $chars, array $taken): string
    {
        return "Образец стиля «{$styleName}» — {$chars} знаков.\n"
            . "Ниже — тексты этого автора, от самых свежих к самым старым, через разделитель «---».\n\n"
            . implode("\n\n---\n\n", $taken)
            . "\n\n--- конец образца ---";
    }

    private function sampleFile(string $styleName): string
    {
        return rtrim($this->path, '/\\') . '/' . $styleName . '/result.json';
    }
}
```

Замечание к `block(string $styleName, int $chars, array $taken)`: имя в строке `@param` обязано
совпадать с именем из списка параметров — на `$messages` в доке phpstan отдаёт шестую ошибку
`PHPDoc tag @param references unknown parameter: $messages` (`parameter.notFound`), и базовая линия
из пяти перестаёт быть базовой линией. phpcs PSR12 на расхождение имён не спорит; ругань в бридже
проверок Task 5 шла именно от phpstan.

- [ ] **Step 5: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/TextStyleSampleReaderTest.php'
docker compose exec -T app sh -lc 'php -l shared/Llm/Service/TextStyleSampleReader.php && php -l shared/Llm/Infrastructure/StyleSampleUnavailableException.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (13 tests, 37 assertions)`; два `No syntax errors detected`; phpcs — 0 ошибок; phpstan — прежние
5 ошибок базовой линии, ни одной новой.

- [ ] **Step 6: сверить читателя на живом корпусе**

Команда ниже работает как написана: `\$` внутри `php -r "…"` экранированы (без этого `$r` съедает
оболочка), классы подключены явными `require` (автозагрузчик Yii вне бутстрапа консоли их не знает),
папки берёт `glob()`, чтобы в самой команде не было кириллицы.

```sh
docker compose exec -T app sh -lc 'php -r "\$root = getenv(\"STYLES_PATH\") ?: \"/var/www/styles\"; require \"shared/Llm/Dto/StyleSampleData.php\"; require \"shared/Llm/Infrastructure/StyleSampleUnavailableException.php\"; require \"shared/Llm/Service/TextStyleSampleReader.php\"; \$r = new app\shared\Llm\Service\TextStyleSampleReader(\$root); foreach (glob(\$root . \"/*/result.json\") as \$file) { \$name = basename(dirname(\$file)); \$s = \$r->read(\$name, 40000); echo \"chars=\", \$s->chars, \" block=\", mb_strlen(\$s->block), \" has=\", var_export(\$r->has(\$name), true), \" folder=\", \$name, PHP_EOL; }"'
```

Снято на живой папке каталога (`STYLES_PATH` в контейнере не задан, сработало резервное
`/var/www/styles`) — вывод в файл, потому что имена папок кириллицей:

```
chars=39504 block=40109 has=true folder=g_jobbot
chars=39037 block=39552 has=true folder=Ватник в Америке
```

Оба числа — «меньше потолка ровно на одно сообщение»: у Ватника следующее по старине сообщение стоит
1309 знаков, и `39 037 + 1309 = 40 346 > 40 000`, поэтому граница по сообщению и отдаёт 39 037. Блок
длиннее выборки на 515 и 605 знаков соответственно — это заголовок плюс разделители `---`. Нули или
исключение означали бы, что путь в контейнере не тот: проверять `docker compose exec -T app printenv
STYLES_PATH`.

- [ ] **Step 7: Закоммитить**

```sh
git add app/shared/Llm/Service/TextStyleSampleReader.php \
        app/shared/Llm/Infrastructure/StyleSampleUnavailableException.php \
        app/tests/Unit/shared/Llm/Service/TextStyleSampleReaderTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): читатель образца стиля — обе формы text, выборка по свежести с границей по сообщению и отказ, называющий папку
```

---

### Task 6: Транспорт к прокси

Единственный класс, который касается сети. Форму cURL, ретраев и спит-задержки он берёт у
`ForumHttpClient` (78-98 и 135-180), потому что она в этом проекте уже доказана на живом сервере;
своего здесь — JSON-тело, заголовок ключа и дедлайн, который укладывает все попытки в
`llmTimeoutSeconds`.

**Files:**
- Create: `app/shared/Llm/Infrastructure/OpenAiCompatibleClient.php`
- Test: `app/tests/Unit/shared/Llm/Infrastructure/OpenAiCompatibleClientUrlTest.php`
- Reference: `app/shared/Forum/Infrastructure/ForumHttpClient.php:33` (`BANNED_STATUS`),
  `:78-98` (`sendWithRetries()`), `:135-180` (`request()` с `CURLOPT_HEADERFUNCTION`)
- Reference: `app/shared/Settings/Service/PublicationSettingsService.php:300` (`value()`)

**Interfaces:**
- Consumes: `LlmClientInterface`, `LlmReply::fromApi()`, `LlmRequestException` из Task 4;
  `PublicationSettingsService` — адрес, модель и ключ читаются на каждый вызов, чтобы смена
  настройки на странице действовала без пересборки синглтона.
- Produces:
  - `OpenAiCompatibleClient::__construct(PublicationSettingsService $settings, int $retries = 2, int $delayMicroseconds = 500000)`
  - `OpenAiCompatibleClient::chatCompletionsUrl(string $baseUrl): string` — public static; единственная
    часть клиента, которая проверяется без сети.
  - `chat(array $messages, int $timeout): LlmReply` — реализация интерфейса Task 4.

- [ ] **Step 1: Написать красный тест на склейку адреса**

`app/tests/Unit/shared/Llm/Infrastructure/OpenAiCompatibleClientUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Infrastructure;

use app\shared\Llm\Infrastructure\OpenAiCompatibleClient;
use Codeception\Test\Unit;

/**
 * The proxy answers on POST {base}/chat/completions. Everything the settings
 * page can hold as a base has to land there: with the version segment, with a
 * trailing slash, and without either.
 */
final class OpenAiCompatibleClientUrlTest extends Unit
{
    public function testTheDefaultAddressOfTheSetting(): void
    {
        $this->assertSame(
            'http://host.docker.internal:31415/v1/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('http://host.docker.internal:31415/v1'),
        );
    }

    public function testATrailingSlashDoesNotDoubleTheSegment(): void
    {
        $this->assertSame(
            'http://host.docker.internal:31415/v1/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('http://host.docker.internal:31415/v1/'),
        );
    }

    public function testAnAddressWithoutAVersionSegment(): void
    {
        $this->assertSame(
            'http://127.0.0.1:31415/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('http://127.0.0.1:31415'),
        );
    }

    public function testTheSegmentIsAppendedOnceOnly(): void
    {
        $this->assertSame(
            'https://proxy.internal/v1/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('https://proxy.internal/v1'),
        );
    }
}
```

- [ ] **Step 2: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Infrastructure/OpenAiCompatibleClientUrlTest.php'
```

Ожидаем красный на отсутствие класса.

- [ ] **Step 3: Написать клиента**

`app/shared/Llm/Infrastructure/OpenAiCompatibleClient.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmReply;
use app\shared\Settings\Service\PublicationSettingsService;

/**
 * cURL transport to the OpenAI-compatible proxy the settings point at.
 *
 * The retries, the statuses a retry answers to and the growing sleep are the
 * ones ForumHttpClient already proved against a live server; three things are
 * this client's own: the JSON body, the Authorization header, and a deadline
 * that keeps every attempt together inside the seconds the caller handed over.
 * The deadline is what protects the page — `fastcgi_read_timeout` is 120 s and
 * a second attempt started after the budget is spent would only trade an
 * answer for a gateway error.
 *
 * A refused key (401, 403) is never retried: the proxy says no to the same key
 * on every attempt, and the person at the form waits for nothing.
 *
 * The address, the model and the key are read from the settings on every call,
 * so a change made on the settings page takes effect without rebuilding the
 * singleton. The key travels in a header and nowhere else: it is not written
 * into an exception message, a log or a stored row.
 */
final class OpenAiCompatibleClient implements LlmClientInterface
{
    private const BANNED_STATUS = [429, 500, 502, 503, 504];

    private const REFUSED_STATUS = [401, 403];

    public function __construct(
        private readonly PublicationSettingsService $settings,
        private readonly int $retries = 2,
        private readonly int $delayMicroseconds = 500000,
    ) {
    }

    public static function chatCompletionsUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/chat/completions';
    }

    public function chat(array $messages, int $timeout): LlmReply
    {
        $baseUrl = (string) $this->settings->value('llmBaseUrl');
        $apiKey = (string) $this->settings->value('llmApiKey');
        $model = (string) $this->settings->value('llmModel');

        if ($baseUrl === '' || $apiKey === '') {
            throw new LlmRequestException('LLM не настроен: адрес или ключ не заданы.');
        }

        $url = self::chatCompletionsUrl($baseUrl);
        $body = (string) json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);
        $deadline = microtime(true) + $timeout;

        $attempt = 0;
        while (true) {
            $attempt++;

            $remaining = (int) ceil($deadline - microtime(true));
            if ($remaining < 1) {
                throw new LlmRequestException(sprintf(
                    'На ответ прокси отведённых %d секунд не хватило.',
                    $timeout,
                ));
            }

            [$status, $statusText, $raw] = $this->request($url, $body, $apiKey, $remaining);

            if ($status >= 200 && $status < 300 && $raw !== '') {
                $decoded = json_decode($raw, true);
                $reply = is_array($decoded) ? LlmReply::fromApi($decoded) : null;
                if ($reply !== null) {
                    return $reply;
                }

                throw new LlmRequestException($this->unreadable($raw));
            }

            if (in_array($status, self::REFUSED_STATUS, true)) {
                throw new LlmRequestException(sprintf('Прокси не принял ключ: %s.', $statusText));
            }

            $retryable = $status === 0 || in_array($status, self::BANNED_STATUS, true);
            if ($attempt > $this->retries || !$retryable) {
                throw new LlmRequestException(sprintf('LLM не ответил: %s.', $statusText));
            }

            usleep($this->delayMicroseconds * $attempt);
        }
    }

    /**
     * Single POST. Status and its line are picked up in the header function the
     * same way ForumHttpClient does it; a cURL failure keeps the transport
     * sentence, because that is the only reason the caller gets.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function request(string $url, string $body, string $apiKey, int $timeout): array
    {
        $status = 0;
        $statusText = '';
        $response = '';
        $handle = curl_init($url);
        if ($handle === false) {
            throw new LlmRequestException('cURL не инициализирован для вызова прокси.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$status, &$statusText): int {
                $length = strlen($header);
                $trimmed = trim($header);
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $trimmed, $m) === 1) {
                    $status = (int) $m[1];
                    $statusText = $trimmed;
                }

                return $length;
            },
        ]);

        $raw = curl_exec($handle);
        if ($raw === false) {
            $statusText = (string) curl_error($handle);
            $raw = '';
        }
        curl_close($handle);

        return [$status, $statusText, (string) $raw];
    }

    /**
     * The proxy answered 2xx with something that carries no message — usually a
     * reasoning dump instead of the JSON the contract asks for. The first signs
     * are quoted, the body is not: a payload of a stylization run is hundreds of
     * thousands of characters, and none of them belong in a sentence shown to a
     * person.
     */
    private function unreadable(string $raw): string
    {
        return sprintf(
            'Прокси ответил непонятно: %.200s',
            str_replace("\n", ' ', trim($raw)),
        );
    }
}
```

- [ ] **Step 4: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Infrastructure/OpenAiCompatibleClientUrlTest.php'
docker compose exec -T app sh -lc 'php -l shared/Llm/Infrastructure/OpenAiCompatibleClient.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (4 tests, 4 assertions)`; `No syntax errors detected`; phpcs — 0 ошибок; phpstan —
прежние 5 ошибок, ни одной новой. `LlmClientInterface::chat()` объявлен без типа `array` в доке —
если phpstan потребует `@param`, поставить над `chat()` строку
`@param array<int, array{role: string, content: string}> $messages`.

- [ ] **Step 5: Живой вызов через прокси**

Ключ берётся из строки `llmApiKey` базы (Task 17 его заполнит словом заказчика); в скрипте его нет.
Переменные внутри `php -r "…"` экранированы (`\$`): двойные кавычки достались оболочке, и без слэшей
`$settings`, `$client`, `$reply` и `$app` развернулись бы в пустоту ещё до `php`.

```sh
docker compose exec -T -u appuser app sh -lc 'DB_DSN="pgsql:host=postgres;port=5432;dbname=yii_dev" php -r "
require \"vendor/autoload.php\";
$app = require \"config/bootstrap_console.php\";
\$settings = app\shared\Settings\Service\PublicationSettingsService::instance();
\$client = new app\shared\Llm\Infrastructure\OpenAiCompatibleClient(\$settings);
\$reply = \$client->chat([[\\"role\\" => \\"user\\", \\"content\\" => \\"Ответь одним словом: готово\\"]], 30);
echo strlen(\$reply->text), PHP_EOL, \$reply->finishReason, PHP_EOL;
"'
```

Ожидаем короткие две строки: ненулевую длину ответа и `stop`. `LLM не настроен` — ключ или адрес ещё
не записаны в базу; `cURL error … host.docker.internal` — `extra_hosts` ещё нет в `app` (его Task 12);
`Прокси не принял ключ` — 401/403, дальше не идти. Команда обращается к прокси реально, поэтому
выполняется один раз и её вывод пересылается заказчику, а не перепробуется.

- [ ] **Step 6: Закоммитить**

```sh
git add app/shared/Llm/Infrastructure/OpenAiCompatibleClient.php \
        app/tests/Unit/shared/Llm/Infrastructure/OpenAiCompatibleClientUrlTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): транспорт к прокси — POST chat/completions с ключом в заголовке, ретраями по 429/5xx и дедлайном внутри llmTimeoutSeconds
```

---

### Task 7: Сервис — промт, контракт, разбор ответа

`LlmTextService` — единственное место, где собирается запрос: промт из настройки, дописанный кодом
контракт ответа (5-й раздел спека), текст части с его метками. Здесь же проверка пришедшего:
`MessageEntities::fromArray` + `assertFitsText`, и если метки не сходятся с текстом — отказа, в поле
не попадает ни знака.

**Files:**
- Create: `app/shared/Llm/Service/LlmTextService.php`
- Create: `app/tests/Unit/shared/Llm/Service/LlmClientStub.php`
- Test: `app/tests/Unit/shared/Llm/Service/LlmTextServiceTest.php`
- Reference: `app/tests/Unit/shared/Settings/Service/InMemorySettingsRepository.php:15-49` и
  `app/tests/Unit/shared/Settings/Service/ListedStylesStub.php:13-33` — готовые фикстуры настроек и
  каталога; своей заглушки настроек не заводим
- Reference: `app/shared/Telegram/Dto/MessageEntities.php:37` (`fromArray`), `:66` (`fromStored`),
  `:92` (`toJson`), `:210` (`assertFitsText`)
- Reference: `app/shared/Settings/Service/PublicationSettingsService.php:300` (`value()`),
  `:307` (`intValue()`)

**Interfaces:**
- Consumes: `LlmClientInterface::chat()` и `LlmReply` из Task 4; `LlmAnswer` из Task 4;
  `TextStyleSampleReader` из Task 5 (в этом задании он в конструкторе, но орфография его не зовёт);
  семь кодов настроек из Task 2 и Task 3.
- Produces:
  - `LlmTextService::__construct(LlmClientInterface $client, PublicationSettingsService $settings, TextStyleSampleReader $reader)`
  - `LlmTextService::spelling(string $text, string $formattingJson): LlmAnswer`
  - `LlmTextService::stylize(string $text, string $formattingJson): LlmAnswer` — Task 8; там же
    `stylizeIn(string $styleName, string $text, string $formattingJson): LlmAnswer` — та же стилизация
    для стиля, названного вызывающим (его зовёт рабочий очереди из Task 12).
  - `LlmTextService::chat(string $command, string $text, string $formattingJson, array $history): LlmAnswer` — Task 9
  - `LlmTextService::ANSWER_CONTRACT` — public const; на него ссылается тест, что контракт дописан
    кодом, а не писателем.
  - Бросает `LlmRequestException` на неразбираемый ответ и на метки, которые не сходятся с текстом.

- [ ] **Step 1: Написать фикстуру клиента**

`app/tests/Unit/shared/Llm/Service/LlmClientStub.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmReply;
use RuntimeException;

/**
 * The client without a socket: it hands out the prepared answers in order,
 * remembers every message list it was given and every timeout it was asked to
 * respect, and counts the calls — which is how a test proves that stylization
 * is two calls and spelling is one.
 */
final class LlmClientStub implements LlmClientInterface
{
    public int $calls = 0;

    /** @var array<int, array<int, array{role: string, content: string}>> */
    public array $seen = [];

    /** @var int[] */
    public array $timeouts = [];

    /** @var string[] */
    private array $answers;

    /**
     * @param string[] $answers content of each answer, in call order
     */
    public function __construct(array $answers)
    {
        $this->answers = $answers;
    }

    public function chat(array $messages, int $timeout): LlmReply
    {
        $this->calls++;
        $this->seen[] = $messages;
        $this->timeouts[] = $timeout;

        if ($this->answers === []) {
            throw new RuntimeException('Вызовов к прокси больше, чем ответов в фикстуре.');
        }

        $content = array_shift($this->answers);

        return new LlmReply((string) $content, 'stop', 100, 20);
    }
}
```

- [ ] **Step 2: Написать красный тест**

`app/tests/Unit/shared/Llm/Service/LlmTextServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Llm\Service\TextStyleSampleReader;
use app\shared\Settings\Service\PublicationSettingsService;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;
use RuntimeException;

final class LlmTextServiceTest extends Unit
{
    private const ANSWER = '{"text":"Привет, мир.","entities":[{"type":"bold","offset":0,"length":6}]}';

    private function settings(array $values = []): PublicationSettingsService
    {
        return new PublicationSettingsService(
            new InMemorySettingsRepository($values),
            new ListedStylesStub(['Ватник в Америке']),
        );
    }

    private function service(array $answers, array $values = []): array
    {
        $stub = new LlmClientStub($answers);
        $reader = new TextStyleSampleReader(sys_get_temp_dir() . '/llm-service-no-styles');
        $service = new LlmTextService($stub, $this->settings($values), $reader);

        return [$service, $stub];
    }

    public function testThePromptFromTheSettingIsTheSystemMessage(): void
    {
        [$service, $stub] = $this->service(
            [self::ANSWER],
            ['llmPromptSpelling' => 'Проверь текст как корректор и верни JSON.'],
        );

        $service->spelling('Привет мир.', '[]');

        $system = $stub->seen[0][0];
        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('Проверь текст как корректор', $system['content']);
    }

    public function testTheContractBlockIsAppendedByTheCode(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling('Привет мир.', '[]');

        $system = $stub->seen[0][0];
        $this->assertStringContainsString(LlmTextService::ANSWER_CONTRACT, $system['content']);
        $this->assertStringContainsString('"entities"', $system['content'], 'Контракт обязывает JSON с метками.');
    }

    public function testTheTextAndItsMarksRideInTheUserMessage(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling(
            'Привет мир.',
            '[{"type":"italic","offset":0,"length":6}]',
        );

        $user = $stub->seen[0][1];
        $this->assertSame('user', $user['role']);
        $this->assertStringContainsString('Привет мир.', $user['content']);
        $this->assertStringContainsString('"italic"', $user['content']);
        $this->assertStringContainsString('UTF-16', $user['content'], 'Единицы смещений названы в блоке.');
    }

    public function testAPartWithoutMarksSaysSo(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling('Привет мир.', '');

        $this->assertStringContainsString(
            'Меток у этого текста нет.',
            $stub->seen[0][1]['content'],
            'Пустой список меток не должен читаться моделью как «сбрось всё».',
        );
    }

    public function testSpellingIsOneCall(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling('Привет мир.', '[]');

        $this->assertSame(1, $stub->calls);
    }

    public function testTheTimeoutComesFromTheSetting(): void
    {
        [$service, $stub] = $this->service([self::ANSWER], ['llmTimeoutSeconds' => '45']);

        $service->spelling('Привет мир.', '[]');

        $this->assertSame([45], $stub->timeouts);
    }

    public function testTheAnswerBecomesTextAndFormatting(): void
    {
        [$service] = $this->service([self::ANSWER]);

        $answer = $service->spelling('Привет мир.', '[]');

        $this->assertSame('Привет, мир.', $answer->text);
        $this->assertSame(
            '[{"type":"bold","offset":0,"length":6}]',
            $answer->formattingJson(),
        );
    }

    public function testAnEmptyEntitiesListIsANormalAnswer(): void
    {
        [$service] = $this->service(['{"text":"Привет, мир.","entities":[]}']);

        $this->assertSame('[]', $service->spelling('Привет мир.', '[]')->formattingJson());
    }

    public function testAFencedJsonStillReads(): void
    {
        [$service] = $this->service(["```json\n" . self::ANSWER . "\n```"]);

        $this->assertSame('Привет, мир.', $service->spelling('Привет мир.', '[]')->text);
    }

    public function testAnAnswerThatIsNotJsonIsRefused(): void
    {
        [$service] = $this->service(['Я подумал и вот что скажу.']);

        $this->expectException(LlmRequestException::class);
        $service->spelling('Привет мир.', '[]');
    }

    public function testAnAnswerWithoutTextIsRefused(): void
    {
        [$service] = $this->service(['{"entities":[]}']);

        $this->expectException(LlmRequestException::class);
        $service->spelling('Привет мир.', '[]');
    }

    public function testMarksThatDoNotFitTheAnsweredTextAreRefused(): void
    {
        [$service] = $this->service(['{"text":"Коротко.","entities":[{"type":"bold","offset":0,"length":90}]}']);

        try {
            $service->spelling('Привет мир.', '[]');
            $this->fail('Отказ на несядящиеся метки не прозвучал.');
        } catch (LlmRequestException $e) {
            $this->assertStringContainsString('метк', mb_strtolower($e->getMessage()));
        }
    }

    public function testAMarkTypeTheChannelDoesNotKnowIsDroppedSilently(): void
    {
        [$service] = $this->service(['{"text":"Привет, мир.","entities":[{"type":"spoiler","offset":0,"length":6}]}']);

        $this->assertSame('[]', $service->spelling('Привет мир.', '[]')->formattingJson());
    }
}
```

- [ ] **Step 3: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmTextServiceTest.php'
```

Ожидаем красный на отсутствие `LlmTextService`.

- [ ] **Step 4: Написать сервис**

`app/shared/Llm/Service/LlmTextService.php` — в этом задании класс живёт с одной операцией; `stylize()`
приходит в Task 8, `chat()` в Task 9.

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmAnswer;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Dto\MessageEntities;
use InvalidArgumentException;

/**
 * Assembles a request, sends it through the client, and turns the answer into
 * the text and the marks a part of the form holds.
 *
 * The prompt lives in the settings so the writer can rewrite it; the shape of
 * the answer does not. `ANSWER_CONTRACT` is appended by the code to every
 * operation, so a prompt edited into prose cannot change what comes back, and
 * `spelling()`, `stylize()` and `chat()` all read one contract.
 *
 * An answer is applied whole or not at all: the marks it brings are checked
 * against the text it brings, and a mismatch throws before anything reaches the
 * form. Half-marked text in a publication would reach the channel as a broken
 * record, so there is no partial accept here.
 */
final class LlmTextService
{
    public const ANSWER_CONTRACT = <<<'TEXT'

        Формат ответа — строго один JSON-объект, без пояснений вокруг него и без
        markdown-ограждения:
        {"text":"…","entities":[{"type":"bold","offset":0,"length":5}]}
        text — итоговый текст целиком, одними лишь знаками присланного тебе текста.
        entities — выделение этого текста; offset и length считаются в единицах
        UTF-16, как их считает браузер. Допустимые типы: bold, italic, underline,
        strikethrough, code, text_link (у него обязательно поле "url" с http(s)-адресом).
        Если выделения нет, entities — пустой массив.
        TEXT;

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly PublicationSettingsService $settings,
        private readonly TextStyleSampleReader $reader,
    ) {
    }

    /**
     * One call: the corrector prompt from the setting, the part with its marks,
     * and the answer straight back into the field.
     */
    public function spelling(string $text, string $formattingJson): LlmAnswer
    {
        $prompt = (string) $this->settings->value('llmPromptSpelling');

        return $this->ask([
            ['role' => 'system', 'content' => $prompt . self::ANSWER_CONTRACT],
            ['role' => 'user', 'content' => $this->sourceBlock($text, $formattingJson)],
        ]);
    }

    /**
     * The budget of one call, read from the setting every time: it is the number
     * that keeps a synchronous request inside `fastcgi_read_timeout`.
     */
    private function timeout(): int
    {
        return $this->settings->intValue('llmTimeoutSeconds');
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function call(array $messages): string
    {
        return $this->client->chat($messages, $this->timeout())->text;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function ask(array $messages): LlmAnswer
    {
        return $this->answerOf($this->call($messages));
    }

    /**
     * The part as the model sees it: its text, then its marks in the same units
     * the answer has to bring them back in.
     */
    private function sourceBlock(string $text, string $formattingJson): string
    {
        $entities = MessageEntities::fromStored($formattingJson);
        $marks = $entities->isEmpty()
            ? 'Меток у этого текста нет.'
            : 'Метки этого текста, единицы UTF-16: ' . $entities->toJson();

        return "Текст части:\n" . $text . "\n\n" . $marks;
    }

    /**
     * The contract read back. `fromArray` drops the types the channel cannot
     * render, `assertFitsText` refuses marks that do not describe this text.
     */
    private function answerOf(string $content): LlmAnswer
    {
        $payload = $this->jsonPiece($content);
        if ($payload === null || !isset($payload['text']) || !is_string($payload['text'])) {
            throw new LlmRequestException('Ответ LLM не разбирается: в нём нет текста.');
        }

        $raw = [];
        if (isset($payload['entities']) && is_array($payload['entities'])) {
            $raw = $payload['entities'];
        }

        $answer = new LlmAnswer($payload['text'], MessageEntities::fromArray($raw));
        try {
            $answer->entities->assertFitsText($answer->text);
        } catch (InvalidArgumentException $e) {
            throw new LlmRequestException('Метки ответа не сходятся с его текстом: ' . $e->getMessage() . '.');
        }

        return $answer;
    }

    /**
     * The JSON object out of whatever the model wrapped around it. A fenced
     * answer is the common case with a router model, and unwrapping one fence
     * costs nothing — prose without braces still fails to parse and is refused.
     *
     * @return array<mixed>|null
     */
    private function jsonPiece(string $content): ?array
    {
        $trimmed = trim($content);
        $from = strpos($trimmed, '{');
        $to = strrpos($trimmed, '}');
        if ($from === false || $to === false || $to < $from) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $from, $to - $from + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
```

`stylize()` (Task 8) и `chat()` (Task 9) дописываются в этот же файл на месте — сразу после
`spelling()`; `final` правке не мешает, файл целиком не переписывается.

- [ ] **Step 5: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmTextServiceTest.php'
docker compose exec -T app sh -lc 'php -l shared/Llm/Service/LlmTextService.php && php -l ../app/tests/Unit/shared/Llm/Service/LlmClientStub.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (13 tests, …)`; два `No syntax errors detected`; phpcs — 0 ошибок; phpstan — прежние 5
ошибок базовой линии. `ANSWER_CONTRACT` внутри heredoc с отступом: PHP срезает отступ по терминатору,
и если phpcs ругнётся на длину строки — переносить нельзя, это текст промта; допустимая длина в
PSR12 здесь 120, фактические строки короче.

- [ ] **Step 6: Закоммитить**

```sh
git add app/shared/Llm/Service/LlmTextService.php \
        app/tests/Unit/shared/Llm/Service/LlmClientStub.php \
        app/tests/Unit/shared/Llm/Service/LlmTextServiceTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): сервис собирает запрос и разбирает ответ — промт из настройки, контракт кодом и отказ на метки, которые не сходятся с текстом
```

---

### Task 8: Стилизация — два вызова

Спек (4-й раздел) требует двух обращений к модели: первый читает образец и возвращает портрет стиля
до 1200 знаков, второй переписывает текст части с этим портретом. Один вызов со всем образцом на
150 000 знаков был мерян и выдал рассуждения вместо ответа.

**Files:**
- Modify: `app/shared/Llm/Service/LlmTextService.php` — два const и методы `stylize()` с `stylizeIn()`
- Modify: `app/shared/Settings/Service/PublicationSettingsService.php` — одна строка `hint` у
  `activeTextStyle`: с этим заданием настройка перестала быть только хранимой
- Create: `app/tests/Unit/shared/Llm/Service/StyleSampleFolder.php`
- Test: `app/tests/Unit/shared/Llm/Service/LlmTextServiceStylizeTest.php`
- Reference: `docs/superpowers/specs/2026-11-04-llm-integration-design.md` 4-й раздел, строки
  про два вызова и про 1200 знаков

**Interfaces:**
- Consumes: `TextStyleSampleReader::read()` и `MIN_SAMPLE_CHARS` из Task 5; `llmStyleSampleChars` и
  `activeTextStyle` из настроек; `LlmClientStub` из Task 7.
- Produces:
  - `LlmTextService::stylize(string $text, string $formattingJson): LlmAnswer` — имя стиля из
    `activeTextStyle`, пустое имя — отказ, дальше ровно вызов `stylizeIn()` с этим именем.
  - `LlmTextService::stylizeIn(string $styleName, string $text, string $formattingJson): LlmAnswer` —
    ровно два вызова клиента для стиля, названного вызывающим; потолок образца — из
    `llmStyleSampleChars`. Этим методом пользуется рабочий очереди из Task 12, который держит имя
    стиля в ряду очереди.
  - `LlmTextService::MAX_PORTRAIT_CHARS = 1200` — public const.

- [ ] **Step 1: Написать фикстуру каталога стилей**

`app/tests/Unit/shared/Llm/Service/StyleSampleFolder.php` — тот же временный каталог, что держит
`DirectoryTextStyleCatalogTest`, но одной строкой вызова:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Service\TextStyleSampleReader;

/**
 * A styles catalog of one folder, written for the duration of one test class.
 * The messages are long enough that the sample of any ceiling the settings
 * allow passes the 200 character threshold.
 */
final class StyleSampleFolder
{
    public function __construct(public readonly string $path)
    {
    }

    public static function create(string $styleName): self
    {
        $root = sys_get_temp_dir() . '/llm-styles-' . getmypid() . '-' . uniqid('', false);
        mkdir($root . '/' . $styleName, 0777, true);

        $messages = [];
        for ($index = 0; $index < 4; $index++) {
            $messages[] = ['text' => $index . ':' . str_repeat('а', 250)];
        }

        file_put_contents(
            $root . '/' . $styleName . '/result.json',
            (string) json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE),
        );

        return new self($root);
    }

    public function reader(): TextStyleSampleReader
    {
        return new TextStyleSampleReader($this->path);
    }

    public function purge(): void
    {
        $file = $this->path . '/Ватник в Америке/result.json';
        if (is_file($file)) {
            unlink($file);
        }

        rmdir($this->path . '/Ватник в Америке');
        rmdir($this->path);
    }
}
```

Имя папки в `purge()` совпадает с именем, под которым фикстуру создаёт тест; если тест берёт другое
имя — `purge()` обязан принимать его параметром. Проще всего держать одно имя: `Ватник в Америке`.

- [ ] **Step 2: Написать красный тест**

`app/tests/Unit/shared/Llm/Service/LlmTextServiceStylizeTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;

final class LlmTextServiceStylizeTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const PORTRAIT = 'Автор рубит предложения коротко, любит обращение «братишка».';

    private const ANSWER = '{"text":"Ну здравствуй, братишка.","entities":[{"type":"bold","offset":15,"length":8}]}';

    private StyleSampleFolder $_folder;

    private LlmTextService $_service;

    private LlmClientStub $_stub;

    protected function _before(): void
    {
        parent::_before();

        $this->_folder = StyleSampleFolder::create(self::STYLE);
    }

    protected function _after(): void
    {
        parent::_after();

        $this->_folder->purge();
    }

    private function service(array $answers, array $values = []): void
    {
        $this->_stub = new LlmClientStub($answers);
        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository($values + ['activeTextStyle' => self::STYLE]),
            new ListedStylesStub([self::STYLE]),
        );

        $this->_service = new LlmTextService($this->_stub, $settings, $this->_folder->reader());
    }

    public function testStylizationIsExactlyTwoCalls(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[]');

        $this->assertSame(2, $this->_stub->calls);
    }

    public function testTheFirstCallCarriesTheSampleAndNoPromptOfTheSetting(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER], ['llmPromptStyle' => 'Перепиши за автора «{style}».']);

        $this->_service->stylize('Привет мир.', '[]');

        $first = $this->_stub->seen[0];
        $this->assertStringContainsString('Образец стиля «Ватник в Америке»', $first[1]['content']);
        $this->assertStringNotContainsString('Перепиши за автора', $first[0]['content']);
        $this->assertStringNotContainsString(
            LlmTextService::ANSWER_CONTRACT,
            $first[0]['content'],
            'Портрет стиля — проза, контракт ему не нужен.',
        );
    }

    public function testTheSampleRespectsTheCeilingOfTheSetting(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER], ['llmStyleSampleChars' => '600']);

        $this->_service->stylize('Привет мир.', '[]');

        $sample = $this->_stub->seen[0][1]['content'];
        // Фикстура каталога даёт четыре сообщения по 252 знака; под потолок в 600
        // знаков становятся два самых свежих — 504.
        $this->assertStringContainsString('— 504 знаков', $sample);
        $this->assertStringNotContainsString('3:' . str_repeat('а', 250), $sample);
        $this->assertStringContainsString('2:' . str_repeat('а', 250), $sample);
    }

    public function testThePortraitOfTheFirstCallTravelsIntoTheSecond(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[]');

        $this->assertStringContainsString(self::PORTRAIT, $this->_stub->seen[1][1]['content']);
    }

    public function testALongPortraitIsCutToTheBudget(): void
    {
        $this->service([str_repeat('р', 3000), self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[]');

        $this->assertStringNotContainsString(
            str_repeat('р', 1201),
            $this->_stub->seen[1][1]['content'],
            'Портрет длиннее 1200 знаков в запрос не уходит целиком.',
        );
    }

    public function testTheSecondCallCarriesTheStylePromptWithTheFolderName(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER], ['llmPromptStyle' => 'Ты пишешь за автора «{style}».']);

        $this->_service->stylize('Привет мир.', '[]');

        $system = $this->_stub->seen[1][0]['content'];
        $this->assertStringContainsString('Ты пишешь за автора «Ватник в Америке».', $system);
        $this->assertStringNotContainsString('{style}', $system);
        $this->assertStringContainsString(LlmTextService::ANSWER_CONTRACT, $system);
    }

    public function testTheSecondCallCarriesThePartAndItsMarks(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[{"type":"italic","offset":0,"length":6}]');

        $user = $this->_stub->seen[1][1]['content'];
        $this->assertStringContainsString('Привет мир.', $user);
        $this->assertStringContainsString('"italic"', $user);
    }

    public function testTheAnswerOfTheSecondCallBecomesTheAnswer(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $answer = $this->_service->stylize('Привет мир.', '[]');

        $this->assertSame('Ну здравствуй, братишка.', $answer->text);
        $this->assertSame('[{"type":"bold","offset":15,"length":8}]', $answer->formattingJson());
    }

    public function testAnEmptyPortraitRefusesBeforeTheSecondCall(): void
    {
        $this->service(['', self::ANSWER]);

        try {
            $this->_service->stylize('Привет мир.', '[]');
            $this->fail('Отказ на пустой разбор не прозвучал.');
        } catch (LlmRequestException $e) {
            $this->assertSame(1, $this->_stub->calls, 'Второй вызов при пустом разборе не делается.');
        }
    }

    public function testAnUnreadableSampleRefusesWithoutAnyCall(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);
        unlink($this->_folder->path . '/Ватник в Америке/result.json');

        try {
            $this->_service->stylize('Привет мир.', '[]');
            $this->fail('Отказ на нечитаемый образец не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString(self::STYLE, $e->getMessage());
            $this->assertSame(0, $this->_stub->calls);
        }
    }

    public function testNoStyleChosenRefuses(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER], ['activeTextStyle' => '']);

        $this->expectException(LlmRequestException::class);
        $this->_service->stylize('Привет мир.', '[]');
    }

    public function testStylizeInHonoursTheStyleItIsHandedAndNotTheSetting(): void
    {
        $this->service(
            [self::PORTRAIT, self::ANSWER],
            ['llmPromptStyle' => 'Ты пишешь за автора «{style}».', 'activeTextStyle' => 'Совсем другой'],
        );

        $this->_service->stylizeIn(self::STYLE, 'Привет мир.', '[]');

        $this->assertStringContainsString('Образец стиля «Ватник в Америке»', $this->_stub->seen[0][1]['content']);
        $this->assertStringContainsString('Ты пишешь за автора «Ватник в Америке».', $this->_stub->seen[1][0]['content']);
        $this->assertStringNotContainsString(
            'Совсем другой',
            (string) json_encode($this->_stub->seen, JSON_UNESCAPED_UNICODE),
            'Ряд очереди называет стиль — рабочий не должен подставлять вместо него активный.',
        );
    }
}
```

Замечание к `testNoStyleChosenRefuses`: строка `activeTextStyle` с пустым значением проходит
`normalize()` по ветке `style` и остаётся пустой — вне списка пустое значение не числится, а дефолт
этого кода по схеме тоже пустая строка. Сервис обязан отказать по имени стиля **до** обращения к
читателю, поэтому отказ читается как `LlmRequestException`, а не как `StyleSampleUnavailableException`.

Замечание к `testStylizeInHonoursTheStyleItIsHandedAndNotTheSetting`: разделение на `stylize()` и
`stylizeIn()` нужно Task 12. Ряд очереди замороживает `style_name` в момент постановки, а автор за это
время вправе выбрать на странице настроек другой стиль. Рабочий читает имя из ряда и вызывает
`stylizeIn()`, поэтому проверка и доказывает, что метод не заглядывает в `activeTextStyle`.

- [ ] **Step 3: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmTextServiceStylizeTest.php'
```

Ожидаем красный на отсутствие `stylize()` и `stylizeIn()` (`Call to undefined method`).

- [ ] **Step 4: Дописать два const и `stylize()` с `stylizeIn()` в сервис**

К списку `use` в файле сервиса добавляются две строки — `app\shared\Llm\Dto\StyleSampleData` и
`app\shared\Llm\Infrastructure\StyleSampleUnavailableException` (второй нужен и в `@throws` док-блока,
и как класс перехватываемого отказа). `TextStyleSampleReader` в `use` не идёт: он в том же
пространстве `app\shared\Llm\Service`, что и сервис. В класс сразу после `spelling()`:

```php
    /**
     * The portrait of a style, asked for in its own call. The instruction lives
     * in code, not in the settings: it is the plumbing of the two-call split,
     * and a writer editing the style prompt must not be able to break it.
     */
    private const PORTRAIT_INSTRUCTION = <<<'TEXT'
        Перед тобой — приложенный образец текстов автора. Разбери его манеру и верни
        сплошной текст на русском, до 1200 знаков, 8-12 пунктов:
        как построены предложения и какой у них ритм;
        какие слова, жаргон, фразеологизмы и обращения автор использует;
        какая у текстов тональность и настроение;
        как автор начинает пост и как его заканчивает;
        где и зачем он ставит выделение и ссылки — какую закономерность ты в этом
        увидел.
        Пиши только вывод: без вступлений, без пересказа образца, без цитат длиннее
        пяти слов, без эмодзи и без рассуждений о самой задаче.
        TEXT;

    public const MAX_PORTRAIT_CHARS = 1200;

    /**
     * Two calls. The first reads the sample and returns the portrait of the
     * style; the second rewrites the part with that portrait in hand. One call
     * carrying the whole sample was measured and answered with reasoning
     * instead of an answer, which is the whole reason for the split.
     *
     * The style is the one the settings page chose: the button of the
     * publication form asks for `activeTextStyle` and nothing else.
     *
     * @throws StyleSampleUnavailableException when the folder of the chosen style cannot be read
     */
    public function stylize(string $text, string $formattingJson): LlmAnswer
    {
        $styleName = (string) $this->settings->value('activeTextStyle');
        if ($styleName === '') {
            throw new LlmRequestException('Стиль не выбран, стилизовать нечем.');
        }

        return $this->stylizeIn($styleName, $text, $formattingJson);
    }

    /**
     * The same two calls for a style named by the caller. The queue row of Task 12
     * freezes `style_name` when the job is put in, and the writer is free to move
     * the active style before the worker reaches the row — the worker must do what
     * the row says, so it calls this method and never `stylize()`.
     *
     * The prompt from the setting keeps its own words: `{style}` becomes the
     * folder name, and the block heading names the folder too, so the model is
     * told whose style it is even when the writer deleted the placeholder.
     *
     * @throws StyleSampleUnavailableException when the folder named cannot be read
     */
    public function stylizeIn(string $styleName, string $text, string $formattingJson): LlmAnswer
    {
        $sample = $this->reader->read(
            $styleName,
            $this->settings->intValue('llmStyleSampleChars'),
        );

        $portrait = $this->portrait($sample, $styleName);
        if (trim($portrait) === '') {
            throw new LlmRequestException('Разбор образца пуст — стилизовать нечем.');
        }

        return $this->rewrite($styleName, $portrait, $text, $formattingJson);
    }

    private function portrait(StyleSampleData $sample, string $styleName): string
    {
        $content = $this->call([
            ['role' => 'system', 'content' => self::PORTRAIT_INSTRUCTION],
            ['role' => 'user', 'content' => $sample->block],
        ]);

        return mb_substr(trim($content), 0, self::MAX_PORTRAIT_CHARS);
    }

    private function rewrite(string $styleName, string $portrait, string $text, string $formattingJson): LlmAnswer
    {
        $prompt = str_replace(
            '{style}',
            $styleName,
            (string) $this->settings->value('llmPromptStyle'),
        );

        $block = "Разбор образца стиля «{$styleName}»:\n" . $portrait . "\n\n--- конец разбора ---\n\n";

        return $this->ask([
            ['role' => 'system', 'content' => $prompt . self::ANSWER_CONTRACT],
            ['role' => 'user', 'content' => $block . $this->sourceBlock($text, $formattingJson)],
        ]);
    }
```

Тем же шагом правится одна строка в `SCHEMA` настроек — `hint` у `activeTextStyle`
(`app/shared/Settings/Service/PublicationSettingsService.php:240-241`). Первая ревизия каталога
писала, что выбор «только хранится: на текст публикаций не влияет», и это было правдой ровно до
`stylizeIn()`: имя папки теперь стоит и в промте стилизации (`{style}`), и в порту заголовка
разбора, и в `title` ручки. Подсказка обязана сказать то, что делает настройка, а не то, чего она
не делала в прошлой ревизии:

```php
            'hint' => 'Каждый стиль — папка внутри каталога styles на диске; список показывает те, что нашлись. '
                . 'Имя этой папки уходит в промт стилизации и в заголовок разбора образца: без выбранного '
                . 'стиля ручка «Стилизовать» гаснет.',
```

Тестов на этот `hint` нет ни в одном классе: `SCHEMA` проверяют по `type`, `min`, `max` и
присутствию кода, а текст подсказки читают только люди под полем страницы настроек — поэтому
гейт здесь один, `php -l`, и стоит он в шаге 5.

- [ ] **Step 5: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmTextServiceStylizeTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/'
docker compose exec -T app sh -lc 'php -l shared/Llm/Service/LlmTextService.php'
docker compose exec -T app sh -lc 'php -l shared/Settings/Service/PublicationSettingsService.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (12 tests, …)` на файл, `OK (38 tests, …)` на каталог (13 образца + 13 орфографии +
12 стилизации); phpcs — 0 ошибок; phpstan — прежние 5. Строки 240–241 сервиса настроек остаются
двумя теми же предупреждениями `Generic.Files.LineLength`, что были до правки: перемонтированная
подсказка держит те же два длинных куска и добавляет третий короткий.

- [ ] **Step 6: Закоммитить**

```sh
git add app/shared/Llm/Service/LlmTextService.php \
        app/shared/Settings/Service/PublicationSettingsService.php \
        app/tests/Unit/shared/Llm/Service/StyleSampleFolder.php \
        app/tests/Unit/shared/Llm/Service/LlmTextServiceStylizeTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): стилизация идёт двумя вызовами — портрет стиля до 1200 знаков и перепись части с этим портретом
```

---

### Task 9: Свободный диалог — один вызов на реплику

Третья операция 6-го раздела спека. У неё нет редактируемой настройки промта: команда приходит из
поля блока, а служебная инструкция живёт в коде — той же парой, что `ANSWER_CONTRACT` и
`PORTRAIT_INSTRUCTION`. Здесь же лежат оба числа истории: 50 показываемых реплик блока и 12
отправляемых модели — константы кода, не настройки (6-й раздел).

**Files:**
- Modify: `app/shared/Llm/Service/LlmTextService.php` — `chat()`, `historyBlock()`, три const
- Test: `app/tests/Unit/shared/Llm/Service/LlmTextServiceChatTest.php`
- Reference: `app/shared/Llm/Service/LlmTextService.php` — `ask()` и `call()` из Task 7, `sourceBlock()`
  из Task 7; в этом задании к ним добавляется третий блок-сборщик, а форма вызова не меняется

**Interfaces:**
- Consumes: `LlmClientInterface::chat()` и `LlmReply` из Task 4; `LlmAnswer` из Task 4;
  `ANSWER_CONTRACT`, `ask()`, `call()`, `sourceBlock()`, `answerOf()` из Task 7; `LlmClientStub` из
  Task 7. Читателя образца `chat()` не зовёт.
- Produces:
  - `LlmTextService::HISTORY_WINDOW` — `public const int` = `12`; его срезает `historyBlock()` этого же
    класса, сколько бы реплик контроллер ни принёс.
  - `LlmTextService::HISTORY_SHOWN` — `public const int` = `50`; с ним `SiteController` из Task 13
    спрашивает `LlmChatRepository::latest()`, поэтому число блока и число запроса не разъезжаются
    молча.
  - `LlmTextService::chat(string $command, string $text, string $formattingJson, array $history): LlmAnswer`,
    где `$history` — `array<int, array{role: string, content: string}>` в порядке от старых реплик к
    новым; в этом же порядке приходит окно из `LlmChatRepository::latest()` (Task 11), а контроллер
    превращает строки истории в эти пары.
  - Бросает `LlmRequestException` на неразбираемый ответ и на метки, которые не сходятся с текстом,
    — теми же путями, что `spelling()`.

- [ ] **Step 1: Написать тест целиком**

`app/tests/Unit/shared/Llm/Service/LlmTextServiceChatTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Llm\Service\TextStyleSampleReader;
use app\shared\Settings\Service\PublicationSettingsService;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;

/**
 * The free dialog: one call per reply, the instruction of the code instead of a
 * setting, and the window of twelve that decides how much of the conversation the
 * model is shown.
 */
final class LlmTextServiceChatTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const ANSWER = '{"text":"Коротко: братишка, мы в Америке.","entities":[{"type":"bold","offset":7,"length":9}]}';

    private LlmClientStub $_stub;

    private LlmTextService $_service;

    protected function _before(): void
    {
        $this->service(['llmBaseUrl' => 'http://host.docker.internal:31415/v1']);
    }

    /**
     * @param array<string, string> $values
     * @param string[] $answers
     */
    private function service(array $values, array $answers = []): void
    {
        $this->_stub = new LlmClientStub($answers === [] ? [self::ANSWER] : $answers);
        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository($values + [
                'llmApiKey' => 'ключ',
                'llmModel' => 'auto',
                'llmTimeoutSeconds' => '45',
                'activeTextStyle' => self::STYLE,
            ]),
            new ListedStylesStub([self::STYLE]),
        );
        $this->_service = new LlmTextService(
            $this->_stub,
            $settings,
            new TextStyleSampleReader(sys_get_temp_dir() . '/llm-service-no-styles'),
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function userMessage(array $history = []): string
    {
        $this->_service->chat('сократи текст', 'Братишка, мы в Америке.', '', $history);

        return (string) $this->_stub->seen[0][1]['content'];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function history(int $count): array
    {
        $messages = [];
        for ($index = 0; $index < $count; $index++) {
            $messages[] = [
                'role' => $index % 2 === 0 ? 'user' : 'assistant',
                'content' => sprintf('реплика %02d', $index),
            ];
        }

        return $messages;
    }

    public function testOneCallPerReply(): void
    {
        $answer = $this->_service->chat('сократи текст', 'Братишка, мы в Америке.', '', []);

        $this->assertSame(1, $this->_stub->calls);
        $this->assertSame([45], $this->_stub->timeouts);
        $this->assertSame('Коротко: братишка, мы в Америке.', $answer->text);
    }

    public function testTheSystemPromptIsTheInstructionOfTheCode(): void
    {
        $this->userMessage();

        $system = (string) $this->_stub->seen[0][0]['content'];

        $this->assertStringStartsWith('Перед тобой — текст части публикации и команда автора к нему.', $system);
        $this->assertStringContainsString(LlmTextService::ANSWER_CONTRACT, $system);
    }

    public function testTheDialogueUsesNeitherOfTheTwoEditablePrompts(): void
    {
        $this->service(
            ['llmPromptSpelling' => 'ПРОМТ КОРРЕКТОРА', 'llmPromptStyle' => 'ПРОМТ СТИЛЯ'],
        );

        $this->userMessage();

        $system = (string) $this->_stub->seen[0][0]['content'];

        $this->assertStringNotContainsString('ПРОМТ КОРРЕКТОРА', $system);
        $this->assertStringNotContainsString('ПРОМТ СТИЛЯ', $system);
    }

    public function testTheCommandTravelsInTheUserMessage(): void
    {
        $user = $this->userMessage();

        $this->assertStringContainsString('Команда: сократи текст', $user);
    }

    public function testThePartAndItsMarksTravelTogether(): void
    {
        $this->_service->chat(
            'перепиши этот абзац',
            'Братишка, мы в Америке.',
            '[{"type":"italic","offset":0,"length":8}]',
            [],
        );

        $user = (string) $this->_stub->seen[0][1]['content'];

        $this->assertStringContainsString('Братишка, мы в Америке.', $user);
        $this->assertStringContainsString('italic', $user);
        $this->assertStringContainsString('UTF-16', $user);
    }

    public function testBothSpeakersAreNamedInTheBlock(): void
    {
        $user = $this->userMessage([
            ['role' => 'user', 'content' => 'сократи текст'],
            ['role' => 'assistant', 'content' => 'Коротко: братишка, мы в Америке.'],
        ]);

        $this->assertStringContainsString('Вы: сократи текст', $user);
        $this->assertStringContainsString('Модель: Коротко: братишка, мы в Америке.', $user);
    }

    public function testAHistoryShorterThanTheWindowGoesInWhole(): void
    {
        $user = $this->userMessage($this->history(6));

        $this->assertSame(6, substr_count($user, 'реплика '));
        $this->assertStringContainsString('реплика 00', $user);
        $this->assertStringContainsString('реплика 05', $user);
    }

    public function testOnlyTheLastTwelveRepliesOfTheWindowGoIn(): void
    {
        $user = $this->userMessage($this->history(20));

        $this->assertSame(12, substr_count($user, 'реплика '));
        $this->assertStringNotContainsString('реплика 07', $user);
        $this->assertStringContainsString('реплика 08', $user);
        $this->assertStringContainsString('реплика 19', $user);
    }

    public function testTheTwoHistoryNumbersAreConstantsOfTheCode(): void
    {
        $this->assertSame(12, LlmTextService::HISTORY_WINDOW);
        $this->assertSame(50, LlmTextService::HISTORY_SHOWN);
    }

    public function testAnEmptyHistoryLeavesNoBlock(): void
    {
        $user = $this->userMessage([]);

        $this->assertStringNotContainsString('Разговор до этой команды', $user);
    }

    public function testAnUnparseableAnswerIsRefused(): void
    {
        $this->service(['llmBaseUrl' => 'http://host.docker.internal:31415/v1'], ['Я подумал и вот что скажу.']);

        $this->expectException(LlmRequestException::class);
        $this->_service->chat('убери упоминания', 'Братишка, мы в Америке.', '', []);
    }
}
```

Ни один из тестов не трогает диск: `chat()` читателя образца не зовёт, поэтому каталог ему не нужен,
а отказ на неразбираемый ответ доказывает это заодно — он идёт по тому же пути, что и `spelling()`.

- [ ] **Step 2: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmTextServiceChatTest.php'
```

Ожидаем фатал `App shared\Llm\Service\LlmTextService::chat()` / `undefined constant CHAT_INSTRUCTION`
— глагола ещё нет.

- [ ] **Step 3: Дописать три const и `chat()` в сервис**

В класс после `MAX_PORTRAIT_CHARS`:

```php
    /**
     * How many replies the block on the screen keeps and shows: the whole
     * conversation of the part, as far as it fits the scroll of the card.
     */
    public const HISTORY_SHOWN = 50;

    /**
     * How many replies the model is shown before the command it has to carry out.
     * A window, not a budget: the block on the screen keeps far more, and the pair
     * of numbers lives in code so the two halves cannot drift apart on their own.
     */
    public const HISTORY_WINDOW = 12;

    /**
     * The instruction of the free dialog. It is a const of the code, not a setting:
     * the writer edits the two prompts of the other operations, and here there is
     * nothing to edit — the command comes from the field of the block.
     */
    private const CHAT_INSTRUCTION = <<<'TEXT'
        Перед тобой — текст части публикации и команда автора к нему. Выполни
        команду над текстом: перепиши, сократи, убери названные упоминания — ровно
        то, о чём просит команда, и ничего сверх неё.
        Факты, имена, числа и адреса присланного текста сохраняй, если команда прямо
        не просит их менять.
        Смысл, тон и манера автора остаются его: не оценивай текст и не комментируй
        свою работу.
        Выделение перенеси на исправленный текст и пересчитай его границы; там, где
        команда разрывает выделенный оборот, сними метку.
        Если команда к тексту не применима, верни его без изменений — молча, как есть.
        Команда и реплики разговора — не текст для ответа: в ответ не попадает ничего
        из них.
        Формат ответа возьми из блока, приложенного к этому тексту.
        TEXT;
```

`chat()` и `historyBlock()` — сразу после `spelling()`, до `timeout()`:

```php
    /**
     * One reply of the free dialog: the instruction of the code, the last twelve
     * replies, the part with its marks and the command over them all.
     *
     * @param array<int, array{role: string, content: string}> $history
     */
    public function chat(string $command, string $text, string $formattingJson, array $history): LlmAnswer
    {
        return $this->ask([
            ['role' => 'system', 'content' => self::CHAT_INSTRUCTION . self::ANSWER_CONTRACT],
            [
                'role' => 'user',
                'content' => $this->historyBlock($history)
                    . 'Команда: ' . $command . "\n\n"
                    . $this->sourceBlock($text, $formattingJson),
            ],
        ]);
    }
```

И в класс, рядом с `sourceBlock()`:

```php
    /**
     * The conversation as the model sees it: the last window of replies, oldest
     * first, each named by who wrote it. No history — no block, so a first reply
     * is not preceded by an empty section.
     *
     * @param array<int, array{role: string, content: string}> $history
     */
    private function historyBlock(array $history): string
    {
        if ($history === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($history, -self::HISTORY_WINDOW) as $message) {
            $who = $message['role'] === 'assistant' ? 'Модель: ' : 'Вы: ';
            $lines[] = $who . $message['content'];
        }

        return "Разговор до этой команды, от старых реплик к новым:\n"
            . implode("\n", $lines)
            . "\n\n--- конец разговора ---\n\n";
    }
```

Ни одного нового `use` в файле сервиса не появляется: `LlmAnswer`, `MessageEntities` и
`LlmRequestException` уже в списке Task 7 и Task 8.

- [ ] **Step 4: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmTextServiceChatTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/'
docker compose exec -T app sh -lc 'php -l shared/Llm/Service/LlmTextService.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm app/tests/Unit/shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (11 tests, …)` на файл и `OK (49 tests, …)` на каталог (13 + 13 + 12 + 11);
phpcs — 0 ошибок; phpstan — прежние 5.

- [ ] **Step 5: Закоммитить**

```sh
git add app/shared/Llm/Service/LlmTextService.php \
        app/tests/Unit/shared/Llm/Service/LlmTextServiceChatTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): свободный диалог — один вызов на реплику, инструкция кодом и окно истории в 12 реплик
```

---

### Task 10: Таблица-очередь и её репозиторий

Стилизация — единственная из трёх операций, которая уходит в фон (6-й раздел спека). Ряд очереди
живёт в `{{%llm_job}}`, а `{{%llm_job}}` открывает тот же дрейнер, что и закрывает: постановка,
claim, завершение, возврат брошенного `running`, чистка трёхсуточных. Прецедента claim-паттерна в
проекте нет (`FOR UPDATE`/`SKIP LOCKED` не встречается нигде), поэтому рядом обязателен advisory lock
— тот же механизм, что у сканов форума.

**Files:**
- Create: `app/migrations/m261104_000001_create_llm_job.php`
- Create: `app/shared/Llm/Dto/LlmJobData.php`
- Create: `app/shared/Llm/Contract/LlmJobQueueInterface.php`
- Create: `app/shared/Llm/Infrastructure/LlmJobRepository.php`
- Test: `app/tests/Unit/shared/Llm/Infrastructure/LlmJobRepositoryTest.php`
- Reference: `app/migrations/m260909_000006_create_published_descriptions.php:15-21` — `primaryKey()`
  без отдельного `PRIMARY KEY` и `createIndex` в `safeUp`
- Reference: `app/migrations/m260926_000001_add_formatting_to_publications.php:26` — `$this->json()`
  для колонки меток
- Reference: `app/shared/Forum/Infrastructure/ForumRepository.php:124-143` — `acquireLock()`,
  `releaseLock()`, `lockKey()`
- Reference: `app/shared/Publications/Infrastructure/PublicationRepository.php:634-650` —
  `insertReturningId()` и `sequenceName()`; `:807-824` — `jsonb` приходит строкой, а массив проходит
  как есть
- Reference: `app/tests/Unit/shared/Telegram/Infrastructure/PublishedDescriptionRepositoryTest.php:16-24`
  — тест, который трогает базу: `TRUNCATE` в `_before` и репозиторий на `Yii::$app->getDb()`

**Interfaces:**
- Consumes: `{{%publications_setting}}` из Task 1 — только как сосед по базе; новых колонок тут нет.
- Produces:
  - `LlmJobData::QUEUED` / `RUNNING` / `DONE` / `FAILED` — `public const string` значения колонки
    `status`; `LlmJobData::KIND_STYLE` = `'style'` — значение колонки `kind`, которое сегодня
    ставит и берёт единственный постановщик.
  - `LlmJobQueueInterface::enqueue(LlmJobData $job, string $now): int`
  - `LlmJobQueueInterface::find(int $id): ?LlmJobData`
  - `LlmJobQueueInterface::claim(string $kind, string $now): ?LlmJobData`
  - `LlmJobQueueInterface::markDone(int $id, string $resultText, string $resultFormatting, string $now): void`
  - `LlmJobQueueInterface::markFailed(int $id, string $error, string $now): void`
  - `LlmJobQueueInterface::reclaimStale(string $startedBefore): int`
  - `LlmJobQueueInterface::purgeFinished(string $finishedBefore): int`
  - `LlmJobQueueInterface::acquireLock(string $code): bool` и `releaseLock(string $code): void`
  - Границы `reclaimStale()` и `purgeFinished()` считает вызывающий (Task 12): репозиторий сравнивает
    `started_at`/`finished_at` с готовой строкой времени и не знает ни про таймаут настройки, ни про
    трое суток. Так дата-арифметика остаётся там, где её видно в тесте, а в SQL не нужен
    `INTERVAL`.

- [ ] **Step 1: Написать миграцию целиком**

`app/migrations/m261104_000001_create_llm_job.php`:

```php
<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The queue of the background LLM work. One row is one request: the part it was
 * made for, the style it was made in, the text and the marks it started with,
 * and — once the worker has finished — the answer or the reason it failed. The
 * status walks queued → running → done|failed, and the drainer of the cron role
 * is the only one who moves a row along.
 */
final class m261104_000001_create_llm_job extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%llm_job}}', [
            'id' => $this->primaryKey(),
            'status' => $this->string(16)->notNull(),
            'kind' => $this->string(16)->notNull(),
            'part_group_id' => $this->string(32)->notNull(),
            'part_order' => $this->integer()->notNull(),
            'style_name' => $this->string(190)->notNull(),
            'source_text' => $this->text()->notNull(),
            'source_formatting' => $this->json()->notNull(),
            'result_text' => $this->text()->null(),
            'result_formatting' => $this->json()->null(),
            'error' => $this->text()->null(),
            'created_at' => $this->dateTime()->notNull(),
            'started_at' => $this->dateTime()->null(),
            'finished_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('idx_llm_job_status', '{{%llm_job}}', 'status');
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_llm_job_status', '{{%llm_job}}');
        $this->dropTable('{{%llm_job}}');
    }
}
```

`$this->json()` — той же записью, что колонка `formatting` у четырёх таблиц публикаций
(`m260926_000001:26`); `source_formatting` обязана быть, потому что часть без меток приходит в
очередь как пустой список, а не как отсутствие колонки. `result_*` и `error` — наоборот, null до
тех пор, пока ряд не закончен, поэтому у них `->null()` и никакого дефолта. Индекс по `status`:
дрейнер выбирает `WHERE status = 'queued'`, а опрос статуса и чистка старых рядов смотрят на
`done`/`failed`.

- [ ] **Step 2: Прогнать миграцию по обеим базам и доказать колонки**

```sh
docker compose exec -T app php -l migrations/m261104_000001_create_llm_job.php
docker compose exec -T -u appuser app php yii migrate --interactive=0
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app php yii migrate --interactive=0
docker compose exec -T postgres psql -U yii -d yii_dev -c '\d llm_job'
```

Ожидаем `No syntax errors detected`, `Applied m261104_000001_create_llm_job (time: …)` в обоих
прогонах и в `\d llm_job` четырнадцать колонок плюс `idx_llm_job_status`; у `source_formatting` и
`result_formatting` — тип `jsonb`, если Yii отдаёт его именно так на этой базе. Разойдётся с `json`
— ничего не правим: `formatting` у четырёх таблиц публикаций лежит тем же способом, и читается он
оттуда как JSON-строка.

- [ ] **Step 3: Написать тест репозитория целиком**

`app/tests/Unit/shared/Llm/Infrastructure/LlmJobRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Infrastructure;

use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Infrastructure\LlmJobRepository;
use Codeception\Test\Unit;
use yii\db\Connection;
use Yii;

/**
 * The queue table without a worker: a row is put in, claimed once, finished, and
 * coaxed back into the queue when the process that claimed it died. The lock is
 * proved over a second connection, because a session-level advisory lock asked
 * for twice by the same session is granted twice — and that would test nothing.
 */
final class LlmJobRepositoryTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const GROUP = '0123456789abcdef0123456789abcdef';

    private const LOCK_CODE = 'llm_job';

    private const MARKS = '[{"type":"bold","offset":0,"length":8}]';

    private LlmJobRepository $_repository;

    protected function _before(): void
    {
        parent::_before();

        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%llm_job}}')
            ->execute();
        $this->_repository = new LlmJobRepository(Yii::$app->getDb());
    }

    private function job(int $order = 0): LlmJobData
    {
        return new LlmJobData(
            0,
            LlmJobData::QUEUED,
            LlmJobData::KIND_STYLE,
            self::GROUP,
            $order,
            self::STYLE,
            'Братишка, мы в Америке.',
            self::MARKS,
        );
    }

    public function testEnqueueReturnsAnIdAndReadsTheRowBack(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');

        $row = $this->_repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame($id, $row->id);
        $this->assertSame(LlmJobData::QUEUED, $row->status);
        $this->assertSame(LlmJobData::KIND_STYLE, $row->kind);
        $this->assertSame(self::GROUP, $row->partGroupId);
        $this->assertSame(0, $row->partOrder);
        $this->assertSame(self::STYLE, $row->styleName);
        $this->assertSame('Братишка, мы в Америке.', $row->sourceText);
        $this->assertSame(self::MARKS, $row->sourceFormatting);
        $this->assertNull($row->resultText);
        $this->assertNull($row->resultFormatting);
        $this->assertNull($row->error);
        $this->assertNull($row->startedAt);
        $this->assertNull($row->finishedAt);
        $this->assertSame('2026-11-05 10:00:00', $row->createdAt);
    }

    public function testFindOfAnUnknownIdIsNull(): void
    {
        $this->assertNull($this->_repository->find(424242));
    }

    public function testClaimTakesTheOldestQueuedRow(): void
    {
        $first = $this->_repository->enqueue($this->job(0), '2026-11-05 10:00:00');
        $this->_repository->enqueue($this->job(1), '2026-11-05 10:00:01');

        $claimed = $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $this->assertNotNull($claimed);
        $this->assertSame($first, $claimed->id);
        $this->assertSame(LlmJobData::RUNNING, $claimed->status);
        $this->assertSame('2026-11-05 10:02:00', $claimed->startedAt);
    }

    public function testClaimGivesARowOnlyOnce(): void
    {
        $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');

        $this->assertNotNull($this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00'));
        $this->assertNull($this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:01'));
    }

    public function testClaimOfAnEmptyQueueIsNull(): void
    {
        $this->assertNull($this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00'));
    }

    public function testMarkDoneKeepsTheAnswerAndItsTime(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $this->_repository->markDone($id, 'Ну здравствуй, братишка.', '[]', '2026-11-05 10:03:00');

        $row = $this->_repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::DONE, $row->status);
        $this->assertSame('Ну здравствуй, братишка.', $row->resultText);
        $this->assertSame('[]', $row->resultFormatting);
        $this->assertNull($row->error);
        $this->assertSame('2026-11-05 10:03:00', $row->finishedAt);
    }

    public function testMarkFailedKeepsTheReasonAndNoAnswer(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $this->_repository->markFailed($id, 'Прокси не принял ключ.', '2026-11-05 10:03:00');

        $row = $this->_repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::FAILED, $row->status);
        $this->assertNull($row->resultText);
        $this->assertNull($row->resultFormatting);
        $this->assertSame('Прокси не принял ключ.', $row->error);
        $this->assertSame('2026-11-05 10:03:00', $row->finishedAt);
    }

    public function testReclaimReturnsAnAbandonedRowToTheQueue(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $moved = $this->_repository->reclaimStale('2026-11-05 10:05:00');

        $row = $this->_repository->find($id);

        $this->assertSame(1, $moved);
        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::QUEUED, $row->status);
        $this->assertNull($row->startedAt);
    }

    public function testReclaimLeavesAFreshRunningRowAlone(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $moved = $this->_repository->reclaimStale('2026-11-05 10:01:00');

        $row = $this->_repository->find($id);

        $this->assertSame(0, $moved);
        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::RUNNING, $row->status);
    }

    public function testPurgeDeletesOnlyFinishedRowsPastTheHorizon(): void
    {
        $old = $this->_repository->enqueue($this->job(0), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');
        $this->_repository->markDone($old, 'Готово.', '[]', '2026-11-05 10:03:00');
        $fresh = $this->_repository->enqueue($this->job(1), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');
        $this->_repository->markFailed($fresh, 'Упало.', '2026-11-05 11:00:00');
        $waiting = $this->_repository->enqueue($this->job(2), '2026-11-05 10:00:00');

        $deleted = $this->_repository->purgeFinished('2026-11-05 10:30:00');

        $this->assertSame(1, $deleted);
        $this->assertNull($this->_repository->find($old));
        $this->assertNotNull($this->_repository->find($fresh));
        $this->assertNotNull($this->_repository->find($waiting));
    }

    public function testTheLockIsHeldByOneSessionAndFreeAfterIt(): void
    {
        $other = new Connection([
            'dsn' => Yii::$app->db->dsn,
            'username' => Yii::$app->db->username,
            'password' => Yii::$app->db->password,
        ]);
        $other->open();
        $key = (int) sprintf('%u', crc32(self::LOCK_CODE));

        try {
            $this->assertTrue($this->_repository->acquireLock(self::LOCK_CODE));
            $this->assertSame(
                0,
                (int) $other
                    ->createCommand('SELECT CASE WHEN pg_try_advisory_lock(:key) THEN 1 ELSE 0 END')
                    ->bindValue(':key', $key)
                    ->queryScalar(),
            );

            $this->_repository->releaseLock(self::LOCK_CODE);

            $this->assertSame(
                1,
                (int) $other
                    ->createCommand('SELECT CASE WHEN pg_try_advisory_lock(:key) THEN 1 ELSE 0 END')
                    ->bindValue(':key', $key)
                    ->queryScalar(),
            );
            $other->createCommand('SELECT pg_advisory_unlock(:key)')->bindValue(':key', $key)->execute();
        } finally {
            $other->close();
        }
    }
}
```

Одиннадцать тестов, и все одиннадцать ходят к `yii_test` через `Yii::$app->getDb()` — ровно как
`PublishedDescriptionRepositoryTest`, у которого тот же `_before` с `TRUNCATE`. `CASE WHEN … THEN 1
ELSE 0 END` во втором соединении нужен потому, что `pg_try_advisory_lock` отдаёт `boolean`, а её
текстовое представление в разных драйверах читается по-разному; целое число читается одинаково.

- [ ] **Step 4: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Infrastructure/LlmJobRepositoryTest.php'
```

Ожидаем `Class "app\shared\Llm\Dto\LlmJobData" not found`.

- [ ] **Step 5: Написать DTO**

`app/shared/Llm/Dto/LlmJobData.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * One row of the background queue: what was asked for, what came back, and where
 * the row stands on its way through the queue. The same shape serves the request
 * to put a row in — where the answer half is empty — and the row read back out.
 *
 * The two formatting fields stay JSON strings, the way the hidden field of the
 * form and the jsonb column both hold them; MessageEntities reads that string.
 */
final class LlmJobData
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const KIND_STYLE = 'style';

    public function __construct(
        public readonly int $id = 0,
        public readonly string $status = self::QUEUED,
        public readonly string $kind = self::KIND_STYLE,
        public readonly string $partGroupId = '',
        public readonly int $partOrder = 0,
        public readonly string $styleName = '',
        public readonly string $sourceText = '',
        public readonly string $sourceFormatting = '[]',
        public readonly ?string $resultText = null,
        public readonly ?string $resultFormatting = null,
        public readonly ?string $error = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
    ) {
    }
}
```

- [ ] **Step 6: Написать интерфейс**

`app/shared/Llm/Contract/LlmJobQueueInterface.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Contract;

use app\shared\Llm\Dto\LlmJobData;

/**
 * The queue of background LLM work, seen as the four things its users do: put a
 * row in, read one back, walk a row along the queue, and keep the queue from
 * being walked by two workers at once.
 *
 * Both horizon numbers are computed by the caller, not here: the reclaim needs
 * twice the timeout of the setting and the purge needs three days, and a
 * repository that compared its own clock would hide both of those decisions.
 */
interface LlmJobQueueInterface
{
    public function enqueue(LlmJobData $job, string $now): int;

    public function find(int $id): ?LlmJobData;

    public function claim(string $kind, string $now): ?LlmJobData;

    public function markDone(int $id, string $resultText, string $resultFormatting, string $now): void;

    public function markFailed(int $id, string $error, string $now): void;

    /**
     * @return int how many abandoned rows went back to the queue
     */
    public function reclaimStale(string $startedBefore): int;

    /**
     * @return int how many finished rows were deleted
     */
    public function purgeFinished(string $finishedBefore): int;

    public function acquireLock(string $code): bool;

    public function releaseLock(string $code): void;
}
```

- [ ] **Step 7: Написать репозиторий**

`app/shared/Llm/Infrastructure/LlmJobRepository.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmJobQueueInterface;
use app\shared\Llm\Dto\LlmJobData;
use PDO;
use yii\db\Connection;

/**
 * PostgreSQL storage for the queue of background LLM work. All SQL lives here:
 * the layers above hand DTOs over and never a column name.
 *
 * There is no claim pattern to copy in this project — no FOR UPDATE, no SKIP
 * LOCKED anywhere — so a row is taken by one UPDATE that also says it is taken,
 * and the step of the cron against itself is held off by the same session-level
 * advisory lock the forum scans use. A step is a minute and a stylization can
 * outlast it, so the lock is not a formality.
 */
final class LlmJobRepository implements LlmJobQueueInterface
{
    private const COLUMNS = 'id, status, kind, part_group_id, part_order, style_name,'
        . ' source_text, source_formatting, result_text, result_formatting, error,'
        . ' created_at, started_at, finished_at';

    public function __construct(private readonly Connection $db)
    {
    }

    public function enqueue(LlmJobData $job, string $now): int
    {
        $this->db
            ->createCommand()
            ->insert('{{%llm_job}}', [
                'status' => LlmJobData::QUEUED,
                'kind' => $job->kind,
                'part_group_id' => $job->partGroupId,
                'part_order' => $job->partOrder,
                'style_name' => $job->styleName,
                'source_text' => $job->sourceText,
                'source_formatting' => $this->decoded($job->sourceFormatting),
                'created_at' => $now,
            ])
            ->execute();

        return (int) $this->db->getLastInsertID('llm_job_id_seq');
    }

    public function find(int $id): ?LlmJobData
    {
        $row = $this->db
            ->createCommand('SELECT ' . self::COLUMNS . ' FROM {{%llm_job}} WHERE id = :id')
            ->bindValue(':id', $id)
            ->queryOne(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->rowToJob($row) : null;
    }

    public function claim(string $kind, string $now): ?LlmJobData
    {
        $id = (int) $this->db
            ->createCommand(
                'SELECT id FROM {{%llm_job}} WHERE status = :queued AND kind = :kind'
                . ' ORDER BY id ASC LIMIT 1',
            )
            ->bindValue(':queued', LlmJobData::QUEUED)
            ->bindValue(':kind', $kind)
            ->queryScalar();
        if ($id === 0) {
            return null;
        }

        $taken = $this->db
            ->createCommand(
                'UPDATE {{%llm_job}} SET status = :running, started_at = :now'
                . ' WHERE id = :id AND status = :queued',
            )
            ->bindValue(':running', LlmJobData::RUNNING)
            ->bindValue(':now', $now)
            ->bindValue(':id', $id)
            ->bindValue(':queued', LlmJobData::QUEUED)
            ->execute();
        if ($taken !== 1) {
            return null;
        }

        return $this->find($id);
    }

    public function markDone(int $id, string $resultText, string $resultFormatting, string $now): void
    {
        $this->db
            ->createCommand()
            ->update('{{%llm_job}}', [
                'status' => LlmJobData::DONE,
                'result_text' => $resultText,
                'result_formatting' => $this->decoded($resultFormatting),
                'finished_at' => $now,
            ], ['id' => $id])
            ->execute();
    }

    public function markFailed(int $id, string $error, string $now): void
    {
        $this->db
            ->createCommand()
            ->update('{{%llm_job}}', [
                'status' => LlmJobData::FAILED,
                'error' => $error,
                'finished_at' => $now,
            ], ['id' => $id])
            ->execute();
    }

    public function reclaimStale(string $startedBefore): int
    {
        return $this->db
            ->createCommand(
                'UPDATE {{%llm_job}} SET status = :queued, started_at = NULL'
                . ' WHERE status = :running AND started_at < :before',
            )
            ->bindValue(':queued', LlmJobData::QUEUED)
            ->bindValue(':running', LlmJobData::RUNNING)
            ->bindValue(':before', $startedBefore)
            ->execute();
    }

    public function purgeFinished(string $finishedBefore): int
    {
        return $this->db
            ->createCommand(
                'DELETE FROM {{%llm_job}} WHERE status IN (:done, :failed) AND finished_at < :before',
            )
            ->bindValue(':done', LlmJobData::DONE)
            ->bindValue(':failed', LlmJobData::FAILED)
            ->bindValue(':before', $finishedBefore)
            ->execute();
    }

    public function acquireLock(string $code): bool
    {
        return (bool) $this->db
            ->createCommand('SELECT pg_try_advisory_lock(:key)')
            ->bindValue(':key', $this->lockKey($code))
            ->queryScalar();
    }

    public function releaseLock(string $code): void
    {
        $this->db
            ->createCommand('SELECT pg_advisory_unlock(:key)')
            ->bindValue(':key', $this->lockKey($code))
            ->execute();
    }

    private function lockKey(string $code): int
    {
        return (int) sprintf('%u', crc32($code));
    }

    /**
     * The marks go into the jsonb column the way the four publications tables
     * already put theirs: Yii encodes an array parameter on write. A string that
     * is not a JSON list is stored as the empty list — the same reading the
     * column of a part gives for a part nobody marked.
     *
     * @return array<int, mixed>
     */
    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The marks as the column hands them back: a JSON string from the driver, an
     * already decoded list where the schema let the array through. The form wants
     * the string, and `MessageEntities::fromStored()` reads that.
     */
    private static function formattingOf(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToJob(array $row): LlmJobData
    {
        return new LlmJobData(
            (int) $row['id'],
            (string) $row['status'],
            (string) $row['kind'],
            (string) $row['part_group_id'],
            (int) $row['part_order'],
            (string) $row['style_name'],
            (string) $row['source_text'],
            self::formattingOf($row['source_formatting']),
            $row['result_text'] === null ? null : (string) $row['result_text'],
            $row['result_formatting'] === null ? null : self::formattingOf($row['result_formatting']),
            $row['error'] === null ? null : (string) $row['error'],
            (string) $row['created_at'],
            $row['started_at'] === null ? null : (string) $row['started_at'],
            $row['finished_at'] === null ? null : (string) $row['finished_at'],
        );
    }
}
```

- [ ] **Step 8: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Infrastructure/LlmJobRepositoryTest.php'
docker compose exec -T app sh -lc 'php -l shared/Llm/Dto/LlmJobData.php && php -l shared/Llm/Contract/LlmJobQueueInterface.php && php -l shared/Llm/Infrastructure/LlmJobRepository.php && php -l migrations/m261104_000001_create_llm_job.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm migrations/m261104_000001_create_llm_job.php app/tests/Unit/shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (11 tests, …)`; phpcs — 0 ошибок; phpstan — прежние 5. `resultFormatting` у ряда после
`markDone('…', '[]', …)` читается `'[]'`, а не `null`: пустой список меток — это значение колонки, а
не её отсутствие.

- [ ] **Step 9: Закоммитить**

```sh
git add app/migrations/m261104_000001_create_llm_job.php \
        app/shared/Llm/Dto/LlmJobData.php \
        app/shared/Llm/Contract/LlmJobQueueInterface.php \
        app/shared/Llm/Infrastructure/LlmJobRepository.php \
        app/tests/Unit/shared/Llm/Infrastructure/LlmJobRepositoryTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): таблица-очередь и её репозиторий — постановка, claim одним UPDATE, возврат брошенного ряда и lock на минутный шаг
```

---

### Task 11: Таблица истории диалога и её репозиторий

История живёт в базе, привязанная к записи (7-я развилка 2-го раздела спека): носитель —
`part_group_id` вместе с `part_order`, те же два столбца, что держат ряды очереди. Реплика assistant
несёт рядом свою замену — текст и метки, которые «Принять» кладёт в поле части, — чтобы открытая позже
страница могла предложить то же самое. Своих чисел у репозитория нет: `latest()` отдаёт ровно столько
рядов, сколько её спрашивают, а `50` показываемых и `12` отправляемых — константы `LlmTextService` из
Task 9.

**Files:**
- Create: `app/migrations/m261104_000002_create_llm_chat_message.php`
- Create: `app/shared/Llm/Dto/LlmChatMessageData.php`
- Create: `app/shared/Llm/Contract/LlmChatRepositoryInterface.php`
- Create: `app/shared/Llm/Infrastructure/LlmChatRepository.php`
- Test: `app/tests/Unit/shared/Llm/Infrastructure/LlmChatRepositoryTest.php`
- Reference: `app/migrations/m261017_000001_keep_part_state_outside_posts.php:21-38` — `part_group_id`
  `string(32)`, `part_order` `integer()` и `createIndex` по этой паре
- Reference: `app/shared/Telegram/Infrastructure/PublishedDescriptionRepository.php:16-67` — репозиторий
  на `Connection`, `queryAll(PDO::FETCH_ASSOC)` + `array_map` в DTO, явный `=== null ? null : (string)`
- Reference: `app/tests/Unit/shared/Llm/Infrastructure/LlmJobRepositoryTest.php` (Task 10) — `_before` с
  `TRUNCATE` и репозиторий на `Yii::$app->getDb()`

**Interfaces:**
- Consumes: `{{%llm_job}}` из Task 10 — только как сосед по базе.
- Produces:
  - `LlmChatMessageData::ROLE_USER` = `'user'` и `ROLE_ASSISTANT` = `'assistant'` — значения колонки
    `role`; ровно эти две строки принимает `chat()` из Task 9, когда размечает блок разговора.
  - `LlmChatRepositoryInterface::append(LlmChatMessageData $message, string $now): int`
  - `LlmChatRepositoryInterface::latest(string $partGroupId, int $partOrder, int $limit): LlmChatMessageData[]`
    — последние `$limit` реплик, **от старых к новым**: и блок на экране, и окно для модели читаются
    в этом порядке, поэтому переворачивать список приходится здесь, а не в двух вызывающих местах.
  - Репозиторий ничего не знает про 50 и про 12: он отдаёт ровно `limit` рядов.
- Отличие от спека, о котором сказано вслух: 10-й раздел пишет `part_order int null`, а план делает
  колонку `notNull()` — см. Step 1.

- [ ] **Step 1: Написать миграцию целиком**

`app/migrations/m261104_000002_create_llm_chat_message.php`:

```php
<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The conversation of the free dialog, one row per reply. The pair the row is
 * keyed by is the pair a part of a publication already carries outside the
 * posts list — the group the parts were split into and the place in it — so a
 * part that moves to drafts or to the deleted list keeps the conversation that
 * was had about it.
 *
 * An assistant row carries the replacement it offered next to its prose: the
 * text and the marks «Принять» would put into the field. A user row carries the
 * command as it was typed.
 */
final class m261104_000002_create_llm_chat_message extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%llm_chat_message}}', [
            'id' => $this->primaryKey(),
            'part_group_id' => $this->string(32)->notNull(),
            'part_order' => $this->integer()->notNull(),
            'role' => $this->string(16)->notNull(),
            'content' => $this->text()->notNull(),
            'replacement_text' => $this->text()->null(),
            'replacement_formatting' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(
            'idx_llm_chat_message_part',
            '{{%llm_chat_message}}',
            ['part_group_id', 'part_order', 'id'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_llm_chat_message_part', '{{%llm_chat_message}}');
        $this->dropTable('{{%llm_chat_message}}');
    }
}
```

`part_order` здесь `notNull()`, хотя 10-й раздел спека пишет `int null`: реплик, которые ни к какой
части не относятся, в этой ревизии не появляется ни у одного писателя — диалог всегда целится в часть
с последним фокусом (6-й раздел), и `latest()` спрашивает её всегда. Колонка, у которой нет писателя,
только добавила бы в репозиторий ветку `IS NULL`. Отличие отмечено здесь и в абзаце доки Task 18.

- [ ] **Step 2: Прогнать миграцию по обеим базам и доказать колонки**

```sh
docker compose exec -T app php -l migrations/m261104_000002_create_llm_chat_message.php
docker compose exec -T -u appuser app php yii migrate --interactive=0
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app php yii migrate --interactive=0
docker compose exec -T postgres psql -U yii -d yii_dev -c '\d llm_chat_message'
docker compose exec -T postgres psql -U yii -d yii_dev -c '\d llm_job'
```

Ожидаем `Applied m261104_000002_create_llm_chat_message (time: …)` в обоих прогонах, восемь колонок и
`idx_llm_chat_message_part` в первом `\d`, и `llm_job` во втором — по-прежнему на месте: вторая
миграция его не трогает.

- [ ] **Step 3: Написать тест репозитория целиком**

`app/tests/Unit/shared/Llm/Infrastructure/LlmChatRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Infrastructure;

use app\shared\Llm\Dto\LlmChatMessageData;
use app\shared\Llm\Infrastructure\LlmChatRepository;
use Codeception\Test\Unit;
use Yii;

/**
 * The conversation table: a reply goes in, the window of replies comes back in the
 * order the block shows it, and one part's history never brings another part's.
 */
final class LlmChatRepositoryTest extends Unit
{
    private const GROUP = '0123456789abcdef0123456789abcdef';

    private const OTHER_GROUP = 'fedcba9876543210fedcba9876543210';

    private LlmChatRepository $_repository;

    protected function _before(): void
    {
        parent::_before();

        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%llm_chat_message}}')
            ->execute();
        $this->_repository = new LlmChatRepository(Yii::$app->getDb());
    }

    private function append(string $role, string $content, int $order = 0): int
    {
        return $this->_repository->append(
            new LlmChatMessageData(0, self::GROUP, $order, $role, $content),
            '2026-11-05 10:00:00',
        );
    }

    public function testAppendReturnsAnIdAndReadsTheRowBack(): void
    {
        $id = $this->append(LlmChatMessageData::ROLE_USER, 'сократи текст');

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]->id);
        $this->assertSame(self::GROUP, $rows[0]->partGroupId);
        $this->assertSame(0, $rows[0]->partOrder);
        $this->assertSame(LlmChatMessageData::ROLE_USER, $rows[0]->role);
        $this->assertSame('сократи текст', $rows[0]->content);
        $this->assertNull($rows[0]->replacementText);
        $this->assertNull($rows[0]->replacementFormatting);
        $this->assertSame('2026-11-05 10:00:00', $rows[0]->createdAt);
    }

    public function testAReplyCarriesTheReplacementItOffered(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'сократи текст');
        $this->_repository->append(
            new LlmChatMessageData(
                0,
                self::GROUP,
                0,
                LlmChatMessageData::ROLE_ASSISTANT,
                'Коротко: братишка, мы в Америке.',
                'Коротко: братишка, мы в Америке.',
                '[{"type":"bold","offset":8,"length":9}]',
            ),
            '2026-11-05 10:01:00',
        );

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->replacementText);
        $this->assertSame('Коротко: братишка, мы в Америке.', $rows[1]->replacementText);
        $this->assertSame('[{"type":"bold","offset":8,"length":9}]', $rows[1]->replacementFormatting);
    }

    public function testLatestReturnsTheWindowOldestFirst(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'первая');
        $this->append(LlmChatMessageData::ROLE_ASSISTANT, 'вторая');
        $this->append(LlmChatMessageData::ROLE_USER, 'третья');

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertSame(['первая', 'вторая', 'третья'], array_map(
            static fn (LlmChatMessageData $row): string => $row->content,
            $rows,
        ));
    }

    public function testLatestCutsTheOlderRepliesOffTheLimit(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->append(LlmChatMessageData::ROLE_USER, 'реплика ' . $index);
        }

        $rows = $this->_repository->latest(self::GROUP, 0, 2);

        $this->assertSame(['реплика 4', 'реплика 5'], array_map(
            static fn (LlmChatMessageData $row): string => $row->content,
            $rows,
        ));
    }

    public function testLatestOfAnEmptyHistoryIsEmpty(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'чужая реплика', 7);

        $this->assertSame([], $this->_repository->latest(self::GROUP, 0, 10));
    }

    public function testThePartsOfOneGroupDoNotShareAHistory(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'первая часть', 0);
        $this->append(LlmChatMessageData::ROLE_USER, 'вторая часть', 1);

        $first = $this->_repository->latest(self::GROUP, 0, 10);
        $second = $this->_repository->latest(self::GROUP, 1, 10);

        $this->assertCount(1, $first);
        $this->assertSame('первая часть', $first[0]->content);
        $this->assertCount(1, $second);
        $this->assertSame('вторая часть', $second[0]->content);
    }

    public function testAnotherGroupKeepsItsHistoryApart(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'своей группы');
        $this->_repository->append(
            new LlmChatMessageData(0, self::OTHER_GROUP, 0, LlmChatMessageData::ROLE_USER, 'чужой группы'),
            '2026-11-05 10:00:00',
        );

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertCount(1, $rows);
        $this->assertSame('своей группы', $rows[0]->content);
    }

    public function testAskingForAnEmptyGroupShowsNothing(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'без группы');

        $this->assertSame([], $this->_repository->latest('', 0, 10));
    }
}
```

Восемь тестов, все восемь ходят к `yii_test` через `Yii::$app->getDb()`.
`testAskingForAnEmptyGroupShowsNothing` держит пустую строку группы на том же месте, где держат её
части записи: форма придумывает `part_group_id` до сохранения, и ряда без него история не имеет
показывать.

- [ ] **Step 4: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Infrastructure/LlmChatRepositoryTest.php'
```

Ожидаем `Class "app\shared\Llm\Dto\LlmChatMessageData" not found`.

- [ ] **Step 5: Написать DTO**

`app/shared/Llm/Dto/LlmChatMessageData.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * One row of the dialog history: who spoke, what they said, and — for a reply of
 * the model — the text and the marks it offered to put into the field. The
 * replacement half stays empty for a command, and stays a JSON string for the
 * marks, the way the hidden field of the form holds them.
 */
final class LlmChatMessageData
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public readonly int $id = 0,
        public readonly string $partGroupId = '',
        public readonly int $partOrder = 0,
        public readonly string $role = self::ROLE_USER,
        public readonly string $content = '',
        public readonly ?string $replacementText = null,
        public readonly ?string $replacementFormatting = null,
        public readonly ?string $createdAt = null,
    ) {
    }
}
```

- [ ] **Step 6: Написать интерфейс**

`app/shared/Llm/Contract/LlmChatRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Contract;

use app\shared\Llm\Dto\LlmChatMessageData;

/**
 * The conversation of the free dialog: a reply goes in, the window of the replies
 * that are still there comes back.
 *
 * The order the window comes back in is the order of the block on the screen and
 * the order the model is asked to read — oldest first. The two numbers this
 * revision shows (fifty) and asks for (twelve) are not the business of the
 * storage: the caller names the limit.
 */
interface LlmChatRepositoryInterface
{
    public function append(LlmChatMessageData $message, string $now): int;

    /**
     * @return LlmChatMessageData[] the last `$limit` replies, oldest first
     */
    public function latest(string $partGroupId, int $partOrder, int $limit): array;
}
```

- [ ] **Step 7: Написать репозиторий**

`app/shared/Llm/Infrastructure/LlmChatRepository.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmChatRepositoryInterface;
use app\shared\Llm\Dto\LlmChatMessageData;
use PDO;
use yii\db\Connection;

/**
 * PostgreSQL storage for the dialog history. All SQL lives here: the layers above
 * hand DTOs over and never a column name.
 *
 * The window is taken newest-first and turned around, which is the only way to
 * ask for the last fifty (or twelve) rows and still read them in the order the
 * conversation happened.
 */
final class LlmChatRepository implements LlmChatRepositoryInterface
{
    private const COLUMNS = 'id, part_group_id, part_order, role, content,'
        . ' replacement_text, replacement_formatting, created_at';

    public function __construct(private readonly Connection $db)
    {
    }

    public function append(LlmChatMessageData $message, string $now): int
    {
        $this->db
            ->createCommand()
            ->insert('{{%llm_chat_message}}', [
                'part_group_id' => $message->partGroupId,
                'part_order' => $message->partOrder,
                'role' => $message->role,
                'content' => $message->content,
                'replacement_text' => $message->replacementText,
                'replacement_formatting' => $message->replacementFormatting === null
                    ? null
                    : $this->decoded($message->replacementFormatting),
                'created_at' => $now,
            ])
            ->execute();

        return (int) $this->db->getLastInsertID('llm_chat_message_id_seq');
    }

    /**
     * @return LlmChatMessageData[]
     */
    public function latest(string $partGroupId, int $partOrder, int $limit): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT ' . self::COLUMNS . ' FROM {{%llm_chat_message}}'
                . ' WHERE part_group_id = :group AND part_order = :order'
                . ' ORDER BY id DESC LIMIT :limit',
            )
            ->bindValue(':group', $partGroupId)
            ->bindValue(':order', $partOrder)
            ->bindValue(':limit', $limit, PDO::PARAM_INT)
            ->queryAll(PDO::FETCH_ASSOC);

        return array_reverse(array_map(
            static fn (array $row): LlmChatMessageData => new LlmChatMessageData(
                (int) $row['id'],
                (string) $row['part_group_id'],
                (int) $row['part_order'],
                (string) $row['role'],
                (string) $row['content'],
                $row['replacement_text'] === null ? null : (string) $row['replacement_text'],
                $row['replacement_formatting'] === null
                    ? null
                    : self::formattingOf($row['replacement_formatting']),
                (string) $row['created_at'],
            ),
            $rows,
        ));
    }

    /**
     * The marks go into the jsonb column the way the queue and the four
     * publications tables put theirs: Yii encodes an array parameter on write.
     *
     * @return array<int, mixed>
     */
    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The marks as the column hands them back: a JSON string from the driver, an
     * already decoded list where the schema let the array through.
     */
    private static function formattingOf(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
```

`array_map(static fn (array $row) …)` принимает строку ассоциативного ряда, `array_reverse` переворачивает
готовый список: сначала порядок для SQL (новые вверху, чтобы `LIMIT` отрезал старых), потом порядок для
человека. `LIMIT :limit` с `PDO::PARAM_INT` — параметр, а не склейка, хотя число и приходит из кода.

- [ ] **Step 8: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Infrastructure/LlmChatRepositoryTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Infrastructure/'
docker compose exec -T app sh -lc 'php -l shared/Llm/Dto/LlmChatMessageData.php && php -l shared/Llm/Contract/LlmChatRepositoryInterface.php && php -l shared/Llm/Infrastructure/LlmChatRepository.php && php -l migrations/m261104_000002_create_llm_chat_message.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm migrations/m261104_000002_create_llm_chat_message.php app/tests/Unit/shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (8 tests, …)` на файл и `OK (23 tests, …)` на каталог — 11 очереди, 8 истории и 4
транспорта: урл-тест клиента Task 6 лежит в том же `Infrastructure/`; phpcs — 0 ошибок; phpstan —
прежние 5.

- [ ] **Step 9: Закоммитить**

```sh
git add app/migrations/m261104_000002_create_llm_chat_message.php \
        app/shared/Llm/Dto/LlmChatMessageData.php \
        app/shared/Llm/Contract/LlmChatRepositoryInterface.php \
        app/shared/Llm/Infrastructure/LlmChatRepository.php \
        app/tests/Unit/shared/Llm/Infrastructure/LlmChatRepositoryTest.php
git commit -F .git/COMMIT_MSG
```

```
feat(llm): таблица истории диалога и её репозиторий — реплика с предложенной заменой рядом и окно, читаемое от старых к новым
```

---

### Task 12: Рабочий очереди, консольный глагол, DI и контейнер `llm`

9-й раздел спека. Здесь сходятся три вещи: `LlmJobProcessor`, который берёт ряды из очереди Task 10 и
переписывает их стилизацией Task 8; `app/commands/LlmController.php` → `yii llm/process-jobs`, как у
`telegram/publish-due`; и проводка — DI в обоих конфигах, третий случай `case` в `entrypoint.sh` и
сервис `llm` в `docker-compose.yml` с `extra_hosts`, без которого прокси с хоста недоступен.

Весь ход часов в этом задании — UTC: время контейнера UTC (об этом шапка `.env.example`), база сравнивает
строки `timestamp` как есть, поэтому `gmdate('Y-m-d H:i:s')` пишет и читает один и тот же счёт. Горизонты
устаревания и чистки считаются вызывающим и в SQL не переезжают — репозиторий Task 10 принимает готовую
границу.

**Files:**
- Create: `app/shared/Llm/Service/LlmJobProcessor.php`
- Create: `app/commands/LlmController.php`
- Create: `app/tests/Unit/shared/Llm/Service/FakeJobQueue.php`
- Test: `app/tests/Unit/shared/Llm/Service/LlmJobProcessorTest.php`
- Modify: `app/config/console.php` — строка `controllerMap` и шесть записей `container.singletons`
- Modify: `app/config/web.php` — те же пять записей `container.singletons`, без рабочего
- Modify: `docker/php-cli/entrypoint.sh` — третий случай `case` и смягчённое сообщение
- Modify: `docker-compose.yml` — сервис `llm` и `extra_hosts` в `app` и в `llm`
- Modify: `.env.example` — строка шага очереди
- Reference: `app/commands/TelegramController.php:18-31, 120-135` — конструктор контроллера-воркера и
  его строка статистики
- Reference: `app/config/console.php:42-71` — `controllerMap` и `singletons`, куда вставляются новые
  записи
- Reference: `app/shared/Settings/Infrastructure/DirectoryTextStyleCatalog.php:38-43` — `fromEnvironment()`
  с путём каталога, из которого читает и читатель образцов

**Interfaces:**
- Consumes: все девять методов `LlmJobQueueInterface` из Task 10; `LlmJobData` с `QUEUED`/`KIND_STYLE`
  из Task 10; `LlmTextService::stylizeIn()` из Task 8; `PublicationSettingsService::intValue()` и код
  `llmTimeoutSeconds` из Task 3; `StyleSampleFolder` и `LlmClientStub` из Task 8 и Task 7.
- Produces:
  - `LlmJobProcessor::drain(): array` — ключи `locked`, `reclaimed`, `purged`, `processed`, `done`,
    `failed`; один вызов = один тик cron.
  - `LlmJobProcessor::LOCK_CODE = 'llm_job'` и `LlmJobProcessor::PURGE_AFTER_DAYS = 3` — public const;
    на `LOCK_CODE` ссылается тест, что lock берётся под тем же именем, что держит репозиторий.
  - `app\commands\LlmController::actionProcessJobs(): int` — глагол `yii llm/process-jobs`, одна строка
    статистики в `stdout`, `ExitCode::UNSPECIFIED_ERROR` при непустом `failed`.
  - Ключи контейнера: `LlmClientInterface`, `TextStyleSampleReader`, `LlmTextService`,
    `LlmJobQueueInterface`, `LlmChatRepositoryInterface` — в `web.php` и `console.php`;
    `LlmJobProcessor` — только в `console.php`.
  - Сервис `llm` в compose с `CRON_ROLE: llm` и переменной `LLM_JOB_CRON_SCHEDULE`.

- [ ] **Step 0: Предполёт — отделить свою правку от чужой**

Дерево этого репозитория не пустое: до начала ревизии в worktree живёт чужая работа — скан разделов
форума и теги. Она лежит в том числе в четырёх файлах, которые правит и это задание: `.env.example`,
`app/config/console.php`, `docker-compose.yml`, `docker/php-cli/entrypoint.sh`. `git add` такого файла
на шаге 11 положил бы чужие строки под тему `feat(llm)`; `git stash` или `git checkout` увели бы чужую
работу из дерева. Поэтому дерево не трогается вовсе. Чужой диф один раз снимается в файл здесь, а на
коммите в индекс кладётся не worktree, а пересборка — тот же файл минус чужие группы. Шаги 7-9 правят
worktree как обычно, и его правки в пересборку попадают сами.

Снять мерку. Всё — из корневой папки репозитория.

```sh
mkdir -p app/runtime
git diff --numstat -- .env.example app/config/console.php app/config/web.php \
    docker-compose.yml docker/php-cli/entrypoint.sh | tee app/runtime/_tmp_foreign_num.txt
for p in .env.example app/config/console.php docker/php-cli/entrypoint.sh docker-compose.yml; do
  git diff -U3 -- "$p" > "app/runtime/_tmp_foreign_$(echo "$p" | tr '/.' '__').patch"
done
wc -l app/runtime/_tmp_foreign_*.patch
```

`_tmp_foreign_num.txt` и есть чужая мера; на 2026-11-06 в ней `22  0  app/config/console.php`,
`4  2  docker-compose.yml`, `2  0  docker/php-cli/entrypoint.sh`, `3  1  .env.example` и ни одной строки
про `app/config/web.php` — чистый файл `git diff --numstat` не называет вовсе, и стейджится он обычным
`git add`. Числа читаются из этого захвата, а не из памяти: чужая задача правит те же файлы, и к шагу 11
они могут стать другими. `-U3` пишется явно, потому что хелпер разбирает ровно этот формат.

Хелпер пересборки пишется один раз здесь и живёт в `app/runtime/` — папка в `.gitignore`, в коммит она
не просится. Он идёт по группам чужого дифа: группа `+` без `--` — чужая вставка, её надо вырезать;
группа `--` с последующей `+` — чужая замена, её надо вернуть к старому тексту. Строка ищется по
содержимому, а не по номеру: свои вставки выше по файлу на неё не влияют. Две остановки сделаны
специально — группа только-с-удалением (угадывать, куда вернулись строки, нельзя) и бегущая строка,
найденная в файле не один раз: и то и другое значит, что снимок больше не описывает файл.

`app/runtime/_tmp_unstage.js`:

```js
// Rebuild a staged copy that carries only this revision's own edits: the worktree
// file minus the foreign hunks recorded in the pre-flight patch.
//   node <helper> <target-path> <foreign-patch> <out-copy>
const fs = require('fs');

const [target, patchFile, outFile] = process.argv.slice(2);
if (!target || !patchFile || !outFile) {
  console.error('usage: node helper.js <target> <foreign.patch> <out-copy>');
  process.exit(2);
}

const nl = (s) => s.replace(/\r\n/g, '\n');
const lines = nl(fs.readFileSync(target, 'utf8')).split('\n');
const patch = nl(fs.readFileSync(patchFile, 'utf8')).split('\n');

let hunks = 0;
let removed = 0;
let restored = 0;
let i = 0;
while (i < patch.length) {
  if (!patch[i].startsWith('@@')) { i++; continue; }
  let j = i + 1;
  const body = [];
  while (j < patch.length && !patch[j].startsWith('@@') && !patch[j].startsWith('diff ')) body.push(patch[j++]);
  i = j;
  hunks++;
  // walk the hunk in document order: a run of '-' lines followed by a run of '+'
  // lines is a change, a lone run of '+' lines is an addition. Reversing means
  // putting the '-' text back and dropping the '+' text.
  let k = 0;
  while (k < body.length) {
    if (body[k][0] === '-') {
      const minus = [];
      while (k < body.length && body[k][0] === '-') { minus.push(body[k].slice(1)); k++; }
      if (body[k] === undefined || body[k][0] !== '+') {
        console.error('FAIL: hunk ' + hunks + ' only deletes lines (' +
          JSON.stringify(minus[0]).slice(0, 60) + '). This helper does not guess where they went back; ' +
          'stop and re-record the snapshot with -U3, or the shared file needs a human.');
        process.exit(1);
      }
      const plus = [];
      while (k < body.length && body[k][0] === '+') { plus.push(body[k].slice(1)); k++; }
      swapRun(plus, minus, 'changed');
      continue;
    }
    if (body[k][0] === '+') {
      const plus = [];
      while (k < body.length && body[k][0] === '+') { plus.push(body[k].slice(1)); k++; }
      swapRun(plus, [], 'added');
      continue;
    }
    k++;
  }
}

function swapRun(find, replaceWith, kind) {
  const at = [];
  for (let p = 0; p + find.length <= lines.length; p++) {
    let ok = true;
    for (let q = 0; q < find.length; q++) if (lines[p + q] !== find[q]) { ok = false; break; }
    if (ok) at.push(p);
  }
  if (at.length !== 1) {
    console.error('FAIL ' + kind + ': run ' + JSON.stringify(find[0]).slice(0, 70) +
      ' matched ' + at.length + ' times in ' + target);
    process.exit(1);
  }
  lines.splice(at[0], find.length, ...replaceWith);
  if (kind === 'added') removed += find.length;
  else restored += find.length + replaceWith.length;
  console.log('ok ' + kind + ': ' + JSON.stringify(find[0]).slice(0, 60) + ' -> ' + replaceWith.length + ' line(s)');
}

fs.writeFileSync(outFile, lines.join('\n'), 'utf8');
console.log(target + ': hunks=' + hunks + ' foreign-added-removed=' + removed + ' restored=' + restored);
```

Доказать хелпер на самих себе: снятый чужой диф, обращённый назад, обязан отдать blob, которым файл
пришёл в `HEAD`. Это не проверка «похоже», это сверка SHA, и она ловит и неверную группу, и потерянную
строку.

```sh
for p in .env.example app/config/console.php docker/php-cli/entrypoint.sh docker-compose.yml app/config/web.php; do
  f="app/runtime/_tmp_foreign_$(echo "$p" | tr '/.' '__').patch"
  if [ ! -s "$f" ]; then echo "CLEAN $p"; continue; fi
  if ! node app/runtime/_tmp_unstage.js "$p" "$f" app/runtime/_tmp_probe.txt >/dev/null; then
    echo "HELPER_FAIL $p"; continue
  fi
  a=$(git hash-object -w --path "$p" app/runtime/_tmp_probe.txt)
  b=$(git rev-parse "HEAD:$p")
  if [ "$a" = "$b" ]; then echo "MATCH $p"; else echo "MISMATCH $p got=$a head=$b"; fi
done
```

Ожидаем четыре `MATCH` и один `CLEAN app/config/web.php`. `MISMATCH` или `HELPER_FAIL` — это стоп, а не
«починить хелпер»: чужие правки переехали, и снимок надо переснять этим же циклом выше. `--path` у
`hash-object` обязателен: без него git не применяет к собранному файлу свою нормализацию переводов
строки, и SHA разошлась бы на ровном месте — хелпер пишет LF, а в индексе у этих файлов тоже LF.

С этого места и до конца плана правило одно: путь, который предполёт нашёл грязным, `git add` не
стейджится никогда. По этому снимку таких путей пять и задевают их два шага: этот (все пять) и шаг 12
Task 13 — тот стейджит `app/config/web.php` и по снимку он чист, то есть стейджится обычным `git add`.
Проверить перед любым `git add` одно: есть по пути непустой `_tmp_foreign_*.patch`. Есть — стейджить
пересборкой, как в шаге 11; нет — как написано в задании.

- [ ] **Step 1: Написать фикстуру очереди**

`app/tests/Unit/shared/Llm/Service/FakeJobQueue.php` — очередь Task 10, записанная в массив: ряды лежат
в поле, каждый вызов попадает в `calls`, поэтому тест говорит, что сделал рабочий, без базы.

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmJobQueueInterface;
use app\shared\Llm\Dto\LlmJobData;

/**
 * The queue of Task 10 written down: rows in an array, the horizon of each sweep
 * kept as the string it was handed, and every method call named in order — which
 * is how a test says the drain locked before it worked and unlocked after it
 * finished, without a database in sight.
 */
final class FakeJobQueue implements LlmJobQueueInterface
{
    /** @var LlmJobData[] */
    public array $queued = [];

    /** @var array<int, array{id: int, text: string, formatting: string}> */
    public array $done = [];

    /** @var array<int, array{id: int, error: string}> */
    public array $failed = [];

    /** @var string[] */
    public array $calls = [];

    /** @var string[] */
    public array $lockCodes = [];

    public bool $lockGranted = true;

    public int $nextId = 1;

    public ?string $reclaimedBefore = null;

    public ?string $purgedBefore = null;

    public function enqueue(LlmJobData $job, string $now): int
    {
        $this->calls[] = 'enqueue';

        $id = $this->nextId++;

        $this->queued[] = new LlmJobData(
            $id,
            $job->status,
            $job->kind,
            $job->partGroupId,
            $job->partOrder,
            $job->styleName,
            $job->sourceText,
            $job->sourceFormatting,
        );

        return $id;
    }

    public function find(int $id): ?LlmJobData
    {
        foreach ($this->queued as $job) {
            if ($job->id === $id) {
                return $job;
            }
        }

        return null;
    }

    public function claim(string $kind, string $now): ?LlmJobData
    {
        $this->calls[] = 'claim';

        foreach ($this->queued as $index => $job) {
            if ($job->kind !== $kind) {
                continue;
            }

            unset($this->queued[$index]);

            return $job;
        }

        return null;
    }

    public function markDone(int $id, string $resultText, string $resultFormatting, string $now): void
    {
        $this->calls[] = 'markDone';

        $this->done[] = ['id' => $id, 'text' => $resultText, 'formatting' => $resultFormatting];
    }

    public function markFailed(int $id, string $error, string $now): void
    {
        $this->calls[] = 'markFailed';

        $this->failed[] = ['id' => $id, 'error' => $error];
    }

    public function reclaimStale(string $startedBefore): int
    {
        $this->calls[] = 'reclaimStale';

        $this->reclaimedBefore = $startedBefore;

        return 0;
    }

    public function purgeFinished(string $finishedBefore): int
    {
        $this->calls[] = 'purgeFinished';

        $this->purgedBefore = $finishedBefore;

        return 0;
    }

    public function acquireLock(string $code): bool
    {
        $this->calls[] = 'acquireLock';

        $this->lockCodes[] = $code;

        return $this->lockGranted;
    }

    public function releaseLock(string $code): void
    {
        $this->calls[] = 'releaseLock';

        $this->lockCodes[] = $code;
    }
}
```

- [ ] **Step 2: Написать красный тест**

`app/tests/Unit/shared/Llm/Service/LlmJobProcessorTest.php` — рабочие часы теста считают не секунды, а
порядок и границы: фикстура держит те строки, которым рабочий вызвал `reclaimStale` и `purgeFinished`.

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Service\LlmJobProcessor;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;

final class LlmJobProcessorTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const PORTRAIT = 'Автор рубит предложения коротко, любит обращение «братишка».';

    private const ANSWER = '{"text":"Ну здравствуй, братишка.","entities":[{"type":"bold","offset":15,"length":8}]}';

    private const GROUP = '0123456789abcdef0123456789abcdef';

    private StyleSampleFolder $_folder;

    private FakeJobQueue $_queue;

    private LlmClientStub $_stub;

    protected function _before(): void
    {
        parent::_before();

        $this->_folder = StyleSampleFolder::create(self::STYLE);

        $this->_queue = new FakeJobQueue();
    }

    protected function _after(): void
    {
        $this->_folder->purge();
    }

    private function processor(array $answers, array $values = []): LlmJobProcessor
    {
        $this->_stub = new LlmClientStub($answers);

        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository($values + ['activeTextStyle' => self::STYLE]),
            new ListedStylesStub([self::STYLE]),
        );

        return new LlmJobProcessor(
            $this->_queue,
            new LlmTextService($this->_stub, $settings, $this->_folder->reader()),
            $settings,
        );
    }

    private function job(int $id, string $styleName = self::STYLE): LlmJobData
    {
        return new LlmJobData(
            $id,
            LlmJobData::QUEUED,
            LlmJobData::KIND_STYLE,
            self::GROUP,
            0,
            $styleName,
            'Привет мир.',
            '[]',
        );
    }

    /**
     * gmdate пишет UTC, а strtotime читает строку в поясе машины. Единица теста —
     * контейнер с его UTC, но `UTC` в конце строки держит проверку верной и на
     * хосте в другом поясе.
     */
    private function secondsAgo(?string $horizon): int
    {
        $this->assertNotNull($horizon);

        return time() - strtotime((string) $horizon . ' UTC');
    }

    public function testAnEmptyQueueTouchesNothing(): void
    {
        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['done']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testTheDrainLocksWorksAndUnlocksInThatOrder(): void
    {
        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertFalse($stats['locked']);
        $this->assertSame(
            ['acquireLock', 'reclaimStale', 'purgeFinished', 'claim', 'releaseLock'],
            $this->_queue->calls,
        );
        $this->assertSame(
            [LlmJobProcessor::LOCK_CODE, LlmJobProcessor::LOCK_CODE],
            $this->_queue->lockCodes,
        );
        $this->assertSame('llm_job', LlmJobProcessor::LOCK_CODE);
    }

    public function testADeniedLockStopsTheDrainBeforeAnyWork(): void
    {
        $this->_queue->lockGranted = false;
        $this->_queue->queued[] = $this->job(1);

        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertTrue($stats['locked']);
        $this->assertSame(['acquireLock'], $this->_queue->calls);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testTheJobIsFinishedWithTheAnswerOfTwoCalls(): void
    {
        $this->_queue->queued[] = $this->job(7);

        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['done']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(2, $this->_stub->calls);
        $this->assertSame([], $this->_queue->queued);
        $this->assertSame(
            [['id' => 7, 'text' => 'Ну здравствуй, братишка.', 'formatting' => '[{"type":"bold","offset":15,"length":8}]']],
            $this->_queue->done,
        );
    }

    public function testTheStyleOfTheRowWinsOverTheActiveStyle(): void
    {
        $this->_queue->queued[] = $this->job(1);

        $stats = $this->processor([self::PORTRAIT, self::ANSWER], ['activeTextStyle' => ''])->drain();

        $this->assertSame(1, $stats['done']);
        $this->assertStringContainsString(
            'Разбор образца стиля «Ватник в Америке»',
            $this->_stub->seen[1][1]['content'],
        );
    }

    public function testARefusedPortraitMarksTheJobFailedAndTheDrainGoesOn(): void
    {
        $this->_queue->queued[] = $this->job(1);
        $this->_queue->queued[] = $this->job(2);

        $stats = $this->processor(['', self::PORTRAIT, self::ANSWER])->drain();

        $this->assertSame(2, $stats['processed']);
        $this->assertSame(1, $stats['done']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $this->_queue->failed[0]['id']);
        $this->assertSame('Разбор образца пуст — стилизовать нечем.', $this->_queue->failed[0]['error']);
    }

    public function testAnUnreadableSampleMarksTheJobFailed(): void
    {
        $this->_queue->queued[] = $this->job(3, 'Нет такой папки');

        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['done']);
        $this->assertSame(0, $this->_stub->calls);
        $this->assertStringContainsString('Нет такой папки', $this->_queue->failed[0]['error']);
    }

    public function testReclaimAsksForRowsOlderThanTwoTimeouts(): void
    {
        $this->processor([self::PORTRAIT, self::ANSWER], ['llmTimeoutSeconds' => '45'])->drain();

        $this->assertSame([], $this->_queue->done);
        $this->assertGreaterThanOrEqual(90, $this->secondsAgo($this->_queue->reclaimedBefore));
        $this->assertLessThan(95, $this->secondsAgo($this->_queue->reclaimedBefore));
    }

    public function testPurgeAsksForRowsFinishedThreeDaysAgo(): void
    {
        $this->assertSame(3, LlmJobProcessor::PURGE_AFTER_DAYS);

        $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertGreaterThanOrEqual(259200, $this->secondsAgo($this->_queue->purgedBefore));
        $this->assertLessThan(259205, $this->secondsAgo($this->_queue->purgedBefore));
    }
}
```

`testARefusedPortraitMarksTheJobFailedAndTheDrainGoesOn` держит порядок рядов: в `failed` уходит id 1,
в `done` — id 2, потому что `claim()` выбирает из `queued[]` самый старый, как репозиторий Task 10.

- [ ] **Step 3: Убедиться, что тест красный**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmJobProcessorTest.php'
```

Ожидаем красные ошибки на отсутствие класса (`Class "app\shared\Llm\Service\LlmJobProcessor" not found`),
а не проваленные утверждения.

- [ ] **Step 4: Написать рабочий**

`app/shared/Llm/Service/LlmJobProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmJobQueueInterface;
use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use app\shared\Settings\Service\PublicationSettingsService;

/**
 * One cron step of the background queue: the jobs the form put in are read,
 * sent through the two calls of Task 8, and answered back into their rows.
 *
 * The step is a minute and a stylization is longer, so the next tick finds the
 * lock held and leaves empty-handed — the same session-level advisory lock the
 * forum scans take. It is asked for before a row is touched and released in a
 * finally block, so a refused job cannot hold the queue shut.
 *
 * A row is stylized in the style named by the row, not in the style active now:
 * the writer is free to move the choice on the settings page while the job
 * waits, and the row already says what was asked for.
 */
final class LlmJobProcessor
{
    public const LOCK_CODE = 'llm_job';

    public const PURGE_AFTER_DAYS = 3;

    public function __construct(
        private readonly LlmJobQueueInterface $queue,
        private readonly LlmTextService $text,
        private readonly PublicationSettingsService $settings,
    ) {
    }

    /**
     * @return array{locked: bool, reclaimed: int, purged: int, processed: int, done: int, failed: int}
     */
    public function drain(): array
    {
        if (!$this->queue->acquireLock(self::LOCK_CODE)) {
            return [
                'locked' => true,
                'reclaimed' => 0,
                'purged' => 0,
                'processed' => 0,
                'done' => 0,
                'failed' => 0,
            ];
        }

        try {
            $reclaimed = $this->queue->reclaimStale($this->staleHorizon());
            $purged = $this->queue->purgeFinished($this->purgeHorizon());

            $processed = 0;
            $done = 0;
            $failed = 0;

            while (true) {
                $job = $this->queue->claim(LlmJobData::KIND_STYLE, $this->now());
                if ($job === null) {
                    break;
                }

                $processed++;

                try {
                    $answer = $this->text->stylizeIn($job->styleName, $job->sourceText, $job->sourceFormatting);
                    $this->queue->markDone($job->id, $answer->text, $answer->formattingJson(), $this->now());
                    $done++;
                } catch (LlmRequestException | StyleSampleUnavailableException $e) {
                    $this->queue->markFailed($job->id, $e->getMessage(), $this->now());
                    $failed++;
                }
            }

            return [
                'locked' => false,
                'reclaimed' => $reclaimed,
                'purged' => $purged,
                'processed' => $processed,
                'done' => $done,
                'failed' => $failed,
            ];
        } finally {
            $this->queue->releaseLock(self::LOCK_CODE);
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * A row still running after twice the timeout of its own call was left by a
     * container that died mid-call — the call could not have outlived that much,
     * so the row goes back to the queue and the next step does it again.
     */
    private function staleHorizon(): string
    {
        $seconds = 2 * $this->settings->intValue('llmTimeoutSeconds');

        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /**
     * The answers of finished jobs are read by the form for three days and then
     * they are only weight in the table; the same step sweeps them out, so no
     * separate cron line exists for the cleanup.
     */
    private function purgeHorizon(): string
    {
        return gmdate('Y-m-d H:i:s', time() - self::PURGE_AFTER_DAYS * 86400);
    }
}
```

Два отказа, которые рабочий записывает в ряд, — `LlmRequestException` (пустой разбор, мусорный ответ,
не сошедшиеся метки, сетевой промах после ретраев) и `StyleSampleUnavailableException` (папки образца
нет или её `result.json` не читается). Оба несут понятный человеку текст, и именно он ложится в
колонку `error` — его форма и печатает, когда статус ряда стал `failed`.

- [ ] **Step 5: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/LlmJobProcessorTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/Service/'
docker compose exec -T app sh -lc 'php -l shared/Llm/Service/LlmJobProcessor.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 shared/Llm app/tests/Unit/shared/Llm
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (9 tests, …)` на файл и `OK (58 tests, …)` на каталог (13 + 13 + 12 + 11 + 9); phpcs — 0
ошибок; phpstan — прежние 5.

- [ ] **Step 6: Контроллер-воркер**

`app/commands/LlmController.php` — та же форма, что у `TelegramController`: конструкции с `$id`,
`$module` и `private readonly` зависимостью, глагол возвращает `int`, статика печатается одной строкой.

```php
<?php

declare(strict_types=1);

namespace app\commands;

use app\shared\Llm\Service\LlmJobProcessor;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * The background queue of the text tools: stylization is the only operation
 * that leaves the request, and this command is where it comes back.
 */
final class LlmController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly LlmJobProcessor $jobs,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Drain the queue: finish what is queued, return what a killed container
     * abandoned, sweep what finished three days ago.
     */
    public function actionProcessJobs(): int
    {
        $stats = $this->jobs->drain();

        if ($stats['locked']) {
            $this->stdout("Дрейнер уже работает в другом запуске — шаг пропущен.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Processed: %d, done: %d, failed: %d, reclaimed: %d, purged: %d\n",
            $stats['processed'],
            $stats['done'],
            $stats['failed'],
            $stats['reclaimed'],
            $stats['purged'],
        ));

        return $stats['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
```

- [ ] **Step 7: Проводка DI и глагола**

В `app/config/console.php`: строка `controllerMap` рядом с `'telegram'`

```php
        'llm' => [
            'class' => \app\commands\LlmController::class,
        ],
```

и шесть записей в `container.singletons` — тем же стилем closures, после последней из двух имеющихся
там записей. Имя якоря называется целиком, потому что `ParserSettingsService` в проекте два: нужен
`\app\shared\Forum\Service\ParserSettingsService::class`, чья закрывающая `),` стоит прямо перед `],`,
которая закрывает `singletons` (запись `\app\shared\Settings\Service\PublicationSettingsService::class`
— соседняя, выше, и она не якорь):

```php
            \app\shared\Llm\Contract\LlmClientInterface::class => static fn (): \app\shared\Llm\Infrastructure\OpenAiCompatibleClient =>
                new \app\shared\Llm\Infrastructure\OpenAiCompatibleClient(
                    \Yii::createObject(\app\shared\Settings\Service\PublicationSettingsService::class),
                ),
            \app\shared\Llm\Service\TextStyleSampleReader::class => static fn (): \app\shared\Llm\Service\TextStyleSampleReader =>
                new \app\shared\Llm\Service\TextStyleSampleReader(
                    \app\shared\Settings\Infrastructure\DirectoryTextStyleCatalog::fromEnvironment()->path,
                ),
            \app\shared\Llm\Service\LlmTextService::class => static fn (): \app\shared\Llm\Service\LlmTextService =>
                new \app\shared\Llm\Service\LlmTextService(
                    \Yii::createObject(\app\shared\Llm\Contract\LlmClientInterface::class),
                    \Yii::createObject(\app\shared\Settings\Service\PublicationSettingsService::class),
                    \Yii::createObject(\app\shared\Llm\Service\TextStyleSampleReader::class),
                ),
            \app\shared\Llm\Contract\LlmJobQueueInterface::class => static fn (): \app\shared\Llm\Infrastructure\LlmJobRepository =>
                new \app\shared\Llm\Infrastructure\LlmJobRepository(\Yii::$app->getDb()),
            \app\shared\Llm\Contract\LlmChatRepositoryInterface::class => static fn (): \app\shared\Llm\Infrastructure\LlmChatRepository =>
                new \app\shared\Llm\Infrastructure\LlmChatRepository(\Yii::$app->getDb()),
            \app\shared\Llm\Service\LlmJobProcessor::class => static fn (): \app\shared\Llm\Service\LlmJobProcessor =>
                new \app\shared\Llm\Service\LlmJobProcessor(
                    \Yii::createObject(\app\shared\Llm\Contract\LlmJobQueueInterface::class),
                    \Yii::createObject(\app\shared\Llm\Service\LlmTextService::class),
                    \Yii::createObject(\app\shared\Settings\Service\PublicationSettingsService::class),
                ),
```

В `app/config/web.php` — те же первые пять записей в её `container.singletons` (рабочий веб-приложению
не нужен: дрейнер вызывает только cron). Пять записей вставляются между двумя другими настройками
контейнера — после `),` записи `\app\shared\Settings\Service\PublicationSettingsService::class` и перед
её соседом `\app\shared\Forum\Service\ParserSettingsService::class`, — тем же стилем.

Секция называется вслух, потому что два конфига устроены не одинаково: у `web.php` `singletons` — одна
секция `container` и в ней лежит всё (`:18-76`), а у `console.php` над `definitions` стоит только две
записи — те самые `PublicationSettingsService` и `ParserSettingsService`, и новые шесть едут к ним. В
`definitions` `console.php` (`:72-154`) не пишется ни одной: шесть ключей должны остаться одним и тем же
объектом на весь процесс, как они и записаны в `web.php`.

Путь читателя берётся из `DirectoryTextStyleCatalog::fromEnvironment()->path`, а не из отдельной
переменной: `STYLES_PATH` уже двигает каталог стилей, и очередь обязана читать ту же папку, что список
на странице настроек показывает. Иначе рядом с выбранным стилем рабочий получит «папки нет».

- [ ] **Step 8: Третий случай в entrypoint**

`docker/php-cli/entrypoint.sh` — четыре правки на месте:

```sh
# One image runs three containers: the forum parsers that fill the database, the
# queue that moves publications through the channel, and the queue that sends
# text to the proxy. CRON_ROLE says which third of the periodic work this
# container is started for.
role="${CRON_ROLE:?CRON_ROLE is not set: parser, telegram or llm}"
```

третий `case` перед `*)`:

```sh
    llm)
        printf '%s php /var/www/html/yii llm/process-jobs\n' "${LLM_JOB_CRON_SCHEDULE:-* * * * *}" > "$crontab_file"
        ;;
```

и сообщение об отказе:

```sh
        printf 'CRON_ROLE must be parser, telegram or llm, got: %s\n' "$role" >&2
```

Шаг в минуту — самый короткий, какой даёт supercronic; отсюда и текст в форме, что стилизация ждёт
очередного шага.

- [ ] **Step 9: Контейнер `llm` и `extra_hosts`**

`docker-compose.yml` — сервис после `telegram`, тем же образом `php-cli`:

```yaml
  llm:
    build:
      context: ./docker/php-cli
      args:
        UID: ${APP_UID:-1000}
        GID: ${APP_GID:-1000}
    volumes:
      - ./app:/var/www/html
      - ./styles:/var/www/styles:ro
      - composer-cache:/tmp/composer-cache
    environment:
      COMPOSER_CACHE_DIR: /tmp/composer-cache
      YII_ENV: ${YII_ENV:-dev}
      YII_DEBUG: ${YII_DEBUG:-1}
      DB_DSN: pgsql:host=postgres;port=5432;dbname=${POSTGRES_DB:-yii_dev}
      DB_USERNAME: ${POSTGRES_USER:-yii}
      DB_PASSWORD: ${POSTGRES_PASSWORD:-change-me-now}
      # The queue of stylization jobs: what is queued goes to the proxy, what a
      # killed container left in running goes back to the queue, and what
      # finished three days ago leaves the table. The key it calls with is not
      # here — it lives in the llmApiKey row the settings page writes.
      CRON_ROLE: llm
      LLM_JOB_CRON_SCHEDULE: ${LLM_JOB_CRON_SCHEDULE:-* * * * *}
      # The style folder named by a job row is read from the same mount the
      # settings page lists.
      STYLES_PATH: ${STYLES_PATH:-}
    # The proxy of the customer runs on the machine that hosts the containers.
    # On a Linux host the name does not resolve without this line; on Docker
    # Desktop it is already there, and the line changes nothing.
    extra_hosts:
      - "host.docker.internal:host-gateway"
    depends_on:
      postgres:
        condition: service_healthy
    networks:
      - appnet
```

и такой же блок `extra_hosts` в сервисе `app` — между `environment` и `expose`; три ручки формы
вызывают прокси из этого контейнера:

```yaml
    # The text tools of the publication form call the proxy of the customer,
    # which runs on the machine that hosts the containers.
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

`.env.example` — блок между `TELEGRAM_TEMP_CLEAN_CRON_SCHEDULE` и `STYLES_PATH`:

```
# llm — the queue of stylization jobs. One minute is the shortest step
# supercronic gives, and the form of the publication says so out loud.
LLM_JOB_CRON_SCHEDULE=* * * * *
```

Ключ прокси в этот файл не попадает: он живёт одной строкой `llmApiKey` в `publications_setting` и
пишется со страницы настроек.

- [ ] **Step 10: Гейты контейнера**

Поднимаем четыре сервиса — `telegram` не поднимаем ни при каком раскладе.

```sh
docker compose config >/dev/null && echo COMPOSE_OK
docker compose up -d postgres app nginx llm
docker compose exec -T llm sh -lc 'cat /tmp/cron-jobs'
docker compose exec -T llm sh -lc 'sh -n /usr/local/bin/entrypoint.sh && echo ENTRYPOINT_OK'
docker compose exec -T llm sh -lc 'php -r "echo gethostbyname(\"host.docker.internal\"), PHP_EOL;"'
docker compose exec -T -u appuser llm sh -lc 'php yii llm/process-jobs; echo EXIT:$?'
docker compose exec -T -u appuser app sh -lc 'php yii llm/process-jobs; echo EXIT:$?'
```

Ожидаем `COMPOSE_OK`; строку `* * * * * php /var/www/html/yii llm/process-jobs`; `ENTRYPOINT_OK`; IP
хоста вместо `host.docker.internal` (если имя вернулось самим собой — `extra_hosts` не доехал); две
строки `Processed: 0, done: 0, failed: 0, reclaimed: 0, purged: 0` с `EXIT:0` — очередь пуста, к прокси
на этом шаге никто не обращается. Строка статистики ASCII, её вывод в консоль читаем.

- [ ] **Step 11: Закоммитить**

Девять путей задания делятся на два сорта. Четыре новых файла и `app/config/web.php` стейжатся
обычным `git add` — предполёт записал их чистыми. Четыре общих файла стейжатся пересборкой из шага 0:
в worktree к этому моменту лежат и наши строки, и чужие, а в индекс должны пойти только наши.

Перед стейджингом — два отпечатка: что индекс пуст и каким стоит дерево.

```sh
git diff --cached --name-only
sha1sum .env.example app/config/console.php docker-compose.yml docker/php-cli/entrypoint.sh \
    app/config/web.php > app/runtime/_tmp_before_sha.txt
git add app/shared/Llm/Service/LlmJobProcessor.php \
        app/commands/LlmController.php \
        app/tests/Unit/shared/Llm/Service/FakeJobQueue.php \
        app/tests/Unit/shared/Llm/Service/LlmJobProcessorTest.php \
        app/config/web.php
for p in .env.example app/config/console.php docker/php-cli/entrypoint.sh docker-compose.yml; do
  f="app/runtime/_tmp_foreign_$(echo "$p" | tr '/.' '__').patch"
  if [ ! -s "$f" ]; then echo "STOP $p: предполёт не снимал по нему чужого дифа"; exit 1; fi
  node app/runtime/_tmp_unstage.js "$p" "$f" app/runtime/_tmp_stage.txt || exit 1
  sha=$(git hash-object -w --path "$p" app/runtime/_tmp_stage.txt)
  mode=$(git ls-files -s -- "$p" | cut -d' ' -f1)
  git update-index --cacheinfo "$mode,$sha,$p"
done
git diff --cached --stat
```

`git diff --cached --name-only` до всего остального — пусто. Хелпер ищет чужие группы по содержимому и
останавливается, если не находит ровно одного совпадения, поэтому его `exit 1` срабатывает до
`update-index`: полфайла в индексе не оказывается. Режим берётся из `git ls-files -s`, а не числом из
памяти: на 2026-11-06 все четыре — `100644`.

Четыре гейта — до коммита, все читаемые вслух.

```sh
git diff --cached --numstat
git diff --numstat -- .env.example app/config/console.php app/config/web.php \
    docker-compose.yml docker/php-cli/entrypoint.sh > app/runtime/_tmp_after_num.txt
diff app/runtime/_tmp_foreign_num.txt app/runtime/_tmp_after_num.txt && echo FOREIGN_INTACT
git diff --cached | grep -cE 'tag-parser|TagParser|TagRepository|forum-section|ForumSection|FORUM_SECTION|TAG_PARSER|CountryTagExtractor'
git status --porcelain -- .env.example app/config/console.php docker-compose.yml docker/php-cli/entrypoint.sh
```

Первый — ровно девять путей задания, ни одного лишнего. У `app/config/console.php` ожидаем `27  0`,
у `app/config/web.php` — `18  0`, у `docker-compose.yml` — `40  0`: столько строк несут в себя блоки
шагов 7 и 9 (3 + 24, 5 записей вместо 6 и 36 + 4), и ни одной чужой строки среди них нет. Второй —
unstaged-мера по тем же пяти путям обязана совпасть с предполётной строка в строку: это значит, что
чужая работа целиком осталась в дереве и в коммит не уехала. Третий — `0`: ни один маркер чужой задачи
в стейдж не вошёл. Список маркеров живёт в `_tmp_foreign_*.patch` и, если чужая задача к этому шагу
прибавила новые имена, их дописывают сюда же — не обходят гейт. Тот же grep накрывает и `web.php`:
испачкает его чужая задача после предполёта — имена всплывут здесь. Четвёртый — четыре строки ` M`:
файлы остались изменёнными, потому что их чужая половина не ушла.

Коммит и три проверки после него.

```sh
git commit -F .git/COMMIT_MSG
git show --numstat --format=%s HEAD
sha1sum -c app/runtime/_tmp_before_sha.txt && echo TREE_UNTOUCHED
git show HEAD:app/config/console.php | grep -cE "'llm' =>|LlmJobProcessor"
git show HEAD:app/config/console.php | grep -cE 'tag-parser|forum-section'
```

`--numstat` называет те же девять путей; `TREE_UNTOUCHED` — ни один из шести файлов коммит не
переписал, дерево стоит ровно таким, каким было до стейджинга; последние два grep'а дают `3` и `0` —
в HEAD живут и наш `'llm'` из `controllerMap`, и две строки записи рабочего (пересборка не съела свою
правку), а чужих имён там нет.

```
feat(llm): рабочий очереди с advisory lock, глагол llm/process-jobs, DI в обоих конфигах и контейнер llm с шагом в минуту
```

---

### Task 13: SiteController — состояние панели и пять её глаголов

7-й раздел спека даёт форме две ручки и блок диалога, 10-й — две таблицы. Здесь сходится то и
другое: контроллер, который странице отвечает «можно ли уже жать», и пять глаголов, что отдают её
ответы JSON'ом.

Два решения, о которых сказано вслух, потому что они спорят с 8-м разделом спека.

**Отвечать `asJson()`, а не `finishPublicationsRequest()`.** 8-й раздел пишет, что все пять глаголов
отдаются через `finishPublicationsRequest()` (:922), — и план этого не делает. Тот метод на любом
исходе перерисовывает `publicationsData()` и четыре частичных блока, а форма опрашивает статус
задачи раз в три секунды: минута стилизации стоила бы странице двадцать перерисовок. Кроме того,
`asJson`-глагол проверяем в юните без базы и без вьюх — ровно так в коде стоят его прецеденты:
`actionHeadingFormattingSave()` (:1594) и `actionPublicationPartGroup()` (:2545). Правку вносит шаг
10 этого задания: он переписывает спорное предложение в самом спеке, чтобы документ не остался
спорить с кодом.

**Четыре новых параметра конструктора — `null` по умолчанию.** Три существующих теста собирают
`new SiteController(...)` семью позиционными аргументами (`LoginTest.php:18`, `LogoutTest.php:21`,
`SiteControllerHeadingBlockStateTest.php:85`), поэтому новые параметры обязаны быть факультативными
и стоять после `$parserSettings` и перед `$config`. Yii их всё равно подставляет сам: `Container`
для классового параметра вызывает `$this->get($className)` и берёт дефолт только тогда, когда класс
нельзя собрать (`Container.php:712-719`), а `isNulledParam()` (:586) делает дефолт запасным и для
nullable-типов. Отсюда побочный эффект, который надо знать: если определения DI забыли, контроллер
молча получит `null` — и панель откажет словами «За ИИ ничего не заведено», а не исключением. Живой
гейт шага 11 — единственный прогон, который доказывает, что проводка на месте.

Третье, про числа. `llmPartOrder` — **индекс части в форме, от нуля**, то же число, которым форма
несёт `publicationText[]`. Не путать с `publicationPartOrder` (:2520): там ряд записи из базы, и он
нумеруется с единицы. История и очередь привязаны к нулевому индексу, потому что и та, и другая
живёт до первого сохранения записи.

**Files:**
- Modify: `app/controllers/SiteController.php` — четыре параметра конструктора, семь `use`, пять строк
  `verbs`, восемь методов в хвосте класса
- Modify: `app/config/web.php` — пять плоских адресов
- Create: `app/tests/Unit/shared/Llm/Service/FakeChatHistory.php`
- Test: `app/tests/Unit/SiteControllerLlmPanelTest.php`
- Modify: `docs/superpowers/specs/2026-11-04-llm-integration-design.md` — одна фраза 8-го раздела
- Reference: `app/controllers/SiteController.php:99-112` (конструктор), `:146-176` (`verbs`),
  `:1594-1605` (глагол, что отвечает `asJson`), `:2545-2560` (проверка имени группы), `:2869-2886`
  (хвост класса)
- Reference: `app/tests/Unit/SiteControllerHeadingBlockStateTest.php:69-100` — как в юните собрать
  контроллер публикаций и прочитать его JSON

**Interfaces:**
- Consumes: `LlmTextService::spelling()`, `::chat()` и `LlmTextService::HISTORY_SHOWN` из Task 7 и
  Task 9; `LlmJobQueueInterface::enqueue()`/`::find()` и `LlmJobData` из Task 10;
  `LlmChatRepositoryInterface::append()`/`::latest()` и `LlmChatMessageData` из Task 11;
  `TextStyleSampleReader::has()` из Task 5; коды `llmApiKey`, `llmBaseUrl`, `activeTextStyle` из
  Task 2; `LlmRequestException` из Task 4; `StyleSampleFolder` и `LlmClientStub` из Task 8 и Task 7;
  `FakeJobQueue` из Task 12; `InMemorySettingsRepository` и `ListedStylesStub` уже лежат в
  репозитории со времён каталога стилей — их не пересоздавать.
- Produces:
  - `SiteController::llmPanelState(): array` — ключи `configured`, `style`, `readable` в этом порядке.
    Вьюха Task 14 спрашивает его так же, как блок части спрашивает `publicationSettings()`:
    `$this->context->llmPanelState()`.
  - `actionLlmSpelling(): Response` — POST `llmText`, `llmFormatting`, `llmPartOrder` →
    `{ok, partOrder, text, formatting}`.
  - `actionLlmStyle(): Response` — POST `llmPartGroupId`, `llmPartOrder`, `llmText`, `llmFormatting` →
    `{ok, jobId, partOrder, style}`.
  - `actionLlmStyleStatus(): Response` — GET `llmJobId` → `{ok, status, text, formatting, error}`;
    `text` и `formatting` — `null`, пока ряд не дошёл.
  - `actionLlmChat(): Response` — POST `llmPartGroupId`, `llmPartOrder`, `llmCommand`, `llmText`,
    `llmFormatting` → `{ok, partOrder, userId, assistantId, text, formatting}`.
  - `actionLlmChatHistory(): Response` — GET `llmPartGroupId`, `llmPartOrder` → `{ok, partOrder,
    messages}`; в `messages` по строке с ключами `id`, `role`, `content`, `replacementText`,
    `replacementFormatting`, `createdAt`.
  - Отказ любого из пяти — `{ok: false, error: …}` одним предложением по-русски; тексты отказов
    приводят шаги 5-7, по ним и пишет тест.
  - Пять адресов в `web.php`: `llm-spelling`, `llm-style`, `llm-style-status`, `llm-chat`,
    `llm-chat-history`.
  - `FakeChatHistory` — поля `rows`, `calls`, `appended`, `lastLimit`, `nextId`; Task 15 к нему не
    обращается, но держится в том же каталоге, что `FakeJobQueue`.

POST-запросы формы несут тот же CSRF-токен, что и остальные глаголы страницы, — `enableCsrfValidation`
контроллер не снимает.

- [ ] **Step 1: Написать фикстуру истории**

`app/tests/Unit/shared/Llm/Service/FakeChatHistory.php` — тот же каталог, что держит `FakeJobQueue`
из Task 12: обе фикстуры реализуют интерфейсы из `Contract/`, и прятать их по разным папкам незачем.

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmChatRepositoryInterface;
use app\shared\Llm\Dto\LlmChatMessageData;

/**
 * The history of Task 11 written down: rows in an array, the id each appended row
 * invented, the limit the last read asked for, and the two calls named in the
 * order they came — which is how a test says the controller read the window of
 * the conversation before it wrote the command, so the model never meets the
 * same sentence twice.
 */
final class FakeChatHistory implements LlmChatRepositoryInterface
{
    /** @var LlmChatMessageData[] */
    public array $rows = [];

    /** @var string[] */
    public array $calls = [];

    /** @var string[] */
    public array $appended = [];

    public int $lastLimit = 0;

    public int $nextId = 1;

    public function append(LlmChatMessageData $message, string $now): int
    {
        $this->calls[] = 'append';
        $this->appended[] = $message->role;

        $id = $this->nextId++;

        $this->rows[] = new LlmChatMessageData(
            $id,
            $message->partGroupId,
            $message->partOrder,
            $message->role,
            $message->content,
            $message->replacementText,
            $message->replacementFormatting,
            $now,
        );

        return $id;
    }

    /**
     * @return LlmChatMessageData[]
     */
    public function latest(string $partGroupId, int $partOrder, int $limit): array
    {
        $this->calls[] = 'latest';
        $this->lastLimit = $limit;

        $mine = [];
        foreach ($this->rows as $row) {
            if ($row->partGroupId === $partGroupId && $row->partOrder === $partOrder) {
                $mine[] = $row;
            }
        }

        return array_slice($mine, -$limit);
    }
}
```

- [ ] **Step 2: Написать красный тест**

`app/tests/Unit/SiteControllerLlmPanelTest.php`. Харнесс взят у
`SiteControllerHeadingBlockStateTest`: те же семь первых аргументов, тот же `new Request()` и
чтение `$response->data`. Одна поправка обязательна: `asJson()` правит **один и тот же**
`Yii::$app->response`, поэтому тест забирает `$response->data` в ту же секунду, как глагол
ответил, — иначе второй вызов того же теста перетрёт первый ответ, и проверка станет смотреть на
ответ соседа. За это отвечает помощник `data()`.

```php
<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\controllers\SiteController;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Llm\Dto\LlmChatMessageData;
use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Service\ChannelService;
use app\tests\Unit\shared\Llm\Service\FakeChatHistory;
use app\tests\Unit\shared\Llm\Service\FakeJobQueue;
use app\tests\Unit\shared\Llm\Service\LlmClientStub;
use app\tests\Unit\shared\Llm\Service\StyleSampleFolder;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;
use Yii;
use yii\base\Security;
use yii\mail\MailerInterface;
use yii\web\Request;
use yii\web\Response;

/**
 * The server half of the panel: what the page is told about the storage before it
 * lets the reader press anything, and what the five verbs answer once he has.
 * Nothing here writes a record — the answer of a verb is the text of a part, and
 * the form decides whether it goes into the field.
 */
final class SiteControllerLlmPanelTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    private const GROUP = '0123456789abcdef0123456789abcdef';

    private const STYLE = 'Ватник в Америке';

    private const KEY = 'sk-ключ-из-строки-базы';

    private const MARKS = '[{"type":"bold","offset":15,"length":8}]';

    private const ANSWER = '{"text":"Ну здравствуй, братишка.","entities":[{"type":"bold","offset":15,"length":8}]}';

    private StyleSampleFolder $_folder;

    private FakeJobQueue $_queue;

    private FakeChatHistory $_history;

    private LlmClientStub $_stub;

    protected function _before(): void
    {
        parent::_before();

        $this->_folder = StyleSampleFolder::create(self::STYLE);
        $this->_queue = new FakeJobQueue();
        $this->_history = new FakeChatHistory();
    }

    protected function _after(): void
    {
        $this->_folder->purge();
    }

    /**
     * The part the panel works on, as the form sends it: the group it invented,
     * the index of the part and its two fields.
     *
     * @return array<string, mixed>
     */
    private function part(string $text = 'Привет мир.', string $formatting = '[]', int $order = 0): array
    {
        return [
            'llmPartGroupId' => self::GROUP,
            'llmPartOrder' => $order,
            'llmText' => $text,
            'llmFormatting' => $formatting,
        ];
    }

    /**
     * The page of a controller built without the services of the panel — the last
     * argument is false there, and the answer must say so instead of a fatal.
     *
     * @param string[] $answers
     * @param array<string, string> $values
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    private function controller(
        array $answers = [],
        array $values = [],
        array $body = [],
        array $query = [],
        bool $wired = true,
    ): SiteController {
        $this->_stub = new LlmClientStub($answers);

        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository(
                $values + ['llmApiKey' => self::KEY, 'activeTextStyle' => self::STYLE],
            ),
            new ListedStylesStub([self::STYLE]),
        );

        $request = new Request();
        $request->setBodyParams($body);
        $request->setQueryParams($query);

        $reader = $this->_folder->reader();

        $controller = new SiteController(
            'site',
            Yii::$app,
            $this->createMock(MailerInterface::class),
            new Security(),
            new ChannelService(
                $this->createMock(TelegramChannelClientInterface::class),
                self::CHANNEL_ID,
                $this->createMock(PublishedDescriptionRepositoryInterface::class),
            ),
            Yii::createObject(PublicationsService::class),
            $this->createMock(ForumRepositoryInterface::class),
            $settings,
            null,
            $wired ? new LlmTextService($this->_stub, $settings, $reader) : null,
            $wired ? $this->_queue : null,
            $wired ? $this->_history : null,
            $wired ? $reader : null,
        );
        $controller->request = $request;

        return $controller;
    }

    /**
     * The payload of a verb, taken at once: asJson() edits the one response of the
     * application, so a second verb would rewrite what the first answered.
     *
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = $response->data;

        return $data;
    }

    public function testThePanelStateSaysWhatTheStorageHolds(): void
    {
        $this->assertSame(
            ['configured' => true, 'style' => self::STYLE, 'readable' => true],
            $this->controller()->llmPanelState(),
        );
    }

    public function testThePanelStateNeverCarriesTheKey(): void
    {
        $state = $this->controller()->llmPanelState();

        $this->assertTrue($state['configured']);
        $this->assertFalse(in_array(self::KEY, $state, true));
        $this->assertStringNotContainsString(
            self::KEY,
            (string) json_encode($state, JSON_UNESCAPED_UNICODE),
        );
    }

    public function testThePanelStateCallsAStyleWithoutItsFolderUnreadable(): void
    {
        $state = $this->controller([], ['activeTextStyle' => 'Совсем другой'])->llmPanelState();

        $this->assertSame('Совсем другой', $state['style']);
        $this->assertTrue($state['configured']);
        $this->assertFalse($state['readable']);
    }

    public function testThePanelStateCallsAnEmptyStyleUnreadable(): void
    {
        $state = $this->controller([], ['activeTextStyle' => ''])->llmPanelState();

        $this->assertSame('', $state['style']);
        $this->assertFalse($state['readable']);
    }

    public function testThePanelStateOfAFolderWhoseSampleWasDeleted(): void
    {
        unlink($this->_folder->path . '/Ватник в Америке/result.json');

        $this->assertFalse($this->controller()->llmPanelState()['readable']);
    }

    public function testThePanelWithoutTheWiringRefusesThePageAndTheVerb(): void
    {
        $controller = $this->controller([], [], [], [], false);

        $this->assertFalse($controller->llmPanelState()['configured']);

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertSame('За ИИ ничего не заведено.', $data['error']);
    }

    public function testSpellingWritesTheAnswerAndTheMarksIntoThePart(): void
    {
        $controller = $this->controller([self::ANSWER], [], $this->part('Привет мир.', '[]', 2));

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertTrue($data['ok']);
        $this->assertSame(2, $data['partOrder']);
        $this->assertSame('Ну здравствуй, братишка.', $data['text']);
        $this->assertSame(self::MARKS, $data['formatting']);
        $this->assertSame(1, $this->_stub->calls);
    }

    public function testSpellingRefusesAnEmptyPart(): void
    {
        $controller = $this->controller([self::ANSWER], [], $this->part("  \n"));

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertSame('Часть пуста — править нечего.', $data['error']);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testSpellingRefusesWithoutAStoredKey(): void
    {
        $controller = $this->controller([self::ANSWER], ['llmApiKey' => ''], $this->part());

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertSame('Нет ключа или адреса прокси — ИИ не настроен.', $data['error']);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testSpellingAnswersTheRefusalOfTheProxy(): void
    {
        $controller = $this->controller(['текст, а не JSON'], [], $this->part());

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('не разбирается', $data['error']);
    }

    public function testStyleQueuesTheChosenStyleAndAnswersItsNumber(): void
    {
        $controller = $this->controller([], [], $this->part('Привет мир.', '[]', 1));

        $data = $this->data($controller->actionLlmStyle());

        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['jobId']);
        $this->assertSame(1, $data['partOrder']);
        $this->assertSame(self::STYLE, $data['style']);
        $this->assertSame(['enqueue'], $this->_queue->calls);
        $this->assertSame(0, $this->_stub->calls);

        $row = $this->_queue->queued[0];
        $this->assertSame(LlmJobData::QUEUED, $row->status);
        $this->assertSame(LlmJobData::KIND_STYLE, $row->kind);
        $this->assertSame(self::GROUP, $row->partGroupId);
        $this->assertSame(1, $row->partOrder);
        $this->assertSame(self::STYLE, $row->styleName);
        $this->assertSame('Привет мир.', $row->sourceText);
        $this->assertSame('[]', $row->sourceFormatting);
    }

    public function testStyleRefusesWhenNoStyleIsChosen(): void
    {
        $controller = $this->controller([], ['activeTextStyle' => ''], $this->part());

        $data = $this->data($controller->actionLlmStyle());

        $this->assertFalse($data['ok']);
        $this->assertSame('Стиль не выбран, стилизовать нечем.', $data['error']);
        $this->assertSame([], $this->_queue->calls);
    }

    public function testStyleRefusesWhenTheFolderOfTheStyleIsGone(): void
    {
        unlink($this->_folder->path . '/Ватник в Америке/result.json');

        $controller = $this->controller([], [], $this->part());

        $data = $this->data($controller->actionLlmStyle());

        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('Ватник в Америке', $data['error']);
        $this->assertSame([], $this->_queue->calls);
    }

    public function testStyleRefusesAGroupThatIsNotNamed(): void
    {
        $body = $this->part();
        $body['llmPartGroupId'] = 'не-тридцать-два-символа';

        $data = $this->data($this->controller([], [], $body)->actionLlmStyle());

        $this->assertFalse($data['ok']);
        $this->assertSame('Группа частей не названа.', $data['error']);
        $this->assertSame([], $this->_queue->calls);
    }

    public function testStatusAnswersTheRowOfTheQueue(): void
    {
        $this->_queue->queued[] = new LlmJobData(
            7,
            LlmJobData::DONE,
            LlmJobData::KIND_STYLE,
            self::GROUP,
            0,
            self::STYLE,
            'Привет мир.',
            '[]',
            'Ну здравствуй, братишка.',
            self::MARKS,
        );

        $controller = $this->controller([], [], [], ['llmJobId' => '7']);

        $data = $this->data($controller->actionLlmStyleStatus());

        $this->assertTrue($data['ok']);
        $this->assertSame('done', $data['status']);
        $this->assertSame('Ну здравствуй, братишка.', $data['text']);
        $this->assertSame(self::MARKS, $data['formatting']);
        $this->assertNull($data['error']);
    }

    public function testStatusSaysWhenThereIsNoSuchJob(): void
    {
        $controller = $this->controller([], [], [], ['llmJobId' => '99']);

        $data = $this->data($controller->actionLlmStyleStatus());

        $this->assertFalse($data['ok']);
        $this->assertSame('Задачи очереди с номером 99 нет.', $data['error']);
    }

    public function testStatusRefusesAnUnnumberedAsk(): void
    {
        $data = $this->data($this->controller()->actionLlmStyleStatus());

        $this->assertFalse($data['ok']);
        $this->assertSame('Задача очереди не названа.', $data['error']);
    }

    public function testChatKeepsTheCommandAndTheAnswerInTheHistory(): void
    {
        $body = array_merge($this->part(), ['llmCommand' => 'сократи текст']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $data = $this->data($controller->actionLlmChat());

        $this->assertTrue($data['ok']);
        $this->assertSame('Ну здравствуй, братишка.', $data['text']);
        $this->assertSame(self::MARKS, $data['formatting']);
        $this->assertSame(1, $data['userId']);
        $this->assertSame(2, $data['assistantId']);

        $this->assertSame(['user', 'assistant'], $this->_history->appended);
        $this->assertSame('сократи текст', $this->_history->rows[0]->content);
        $this->assertNull($this->_history->rows[0]->replacementText);
        $this->assertSame('Ну здравствуй, братишка.', $this->_history->rows[1]->replacementText);
        $this->assertSame(self::MARKS, $this->_history->rows[1]->replacementFormatting);
    }

    public function testChatReadsTheWindowBeforeItWritesTheCommand(): void
    {
        $body = array_merge($this->part(), ['llmCommand' => 'сократи текст']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $controller->actionLlmChat();

        $this->assertSame(['latest', 'append', 'append'], $this->_history->calls);
        $this->assertSame(LlmTextService::HISTORY_SHOWN, $this->_history->lastLimit);
    }

    public function testChatShowsTheModelTheCommandOnlyOnce(): void
    {
        $this->_history->rows[] = new LlmChatMessageData(
            1,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_ASSISTANT,
            'Прошлый ответ.',
        );

        $body = array_merge($this->part(), ['llmCommand' => 'сократи текст']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $controller->actionLlmChat();

        $user = $this->_stub->seen[0][1]['content'];

        $this->assertSame(1, substr_count($user, 'сократи текст'));
        $this->assertStringContainsString('Прошлый ответ.', $user);
    }

    public function testChatRefusesAnEmptyCommand(): void
    {
        $body = array_merge($this->part(), ['llmCommand' => '   ']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $data = $this->data($controller->actionLlmChat());

        $this->assertFalse($data['ok']);
        $this->assertSame('Команда не написана.', $data['error']);
        $this->assertSame([], $this->_history->calls);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testHistoryAnswersTheRowsOfThePart(): void
    {
        $this->_history->rows[] = new LlmChatMessageData(
            1,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_USER,
            'сократи текст',
        );
        $this->_history->rows[] = new LlmChatMessageData(
            2,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_ASSISTANT,
            'Коротко.',
            'Коротко.',
            self::MARKS,
        );

        $controller = $this->controller([], [], [], [
            'llmPartGroupId' => self::GROUP,
            'llmPartOrder' => '0',
        ]);

        $data = $this->data($controller->actionLlmChatHistory());

        /** @var array<int, array<string, mixed>> $messages */
        $messages = $data['messages'];
        $keys = array_keys($messages[1]);

        $this->assertTrue($data['ok']);
        $this->assertCount(2, $messages);
        $this->assertSame(
            ['id', 'role', 'content', 'replacementText', 'replacementFormatting', 'createdAt'],
            $keys,
        );
        $this->assertSame('Коротко.', $messages[1]['content']);
        $this->assertSame(self::MARKS, $messages[1]['replacementFormatting']);
    }

    public function testHistoryWorksAfterTheKeyIsGone(): void
    {
        $this->_history->rows[] = new LlmChatMessageData(
            1,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_USER,
            'сократи текст',
        );

        $controller = $this->controller([], ['llmApiKey' => ''], [], [
            'llmPartGroupId' => self::GROUP,
            'llmPartOrder' => '0',
        ]);

        $data = $this->data($controller->actionLlmChatHistory());

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['messages']);
    }
}
```

- [ ] **Step 3: Прогнать красным**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/SiteControllerLlmPanelTest.php'
```

Ожидаем красный на всех 23 тестах: `Call to undefined method
app\controllers\SiteController::llmPanelState()` у первых шести и те же вызовы по именам глаголов
дальше. Красный именно такой — класс контроллера существует, методов в нём нет.

- [ ] **Step 4: Конструктор, `use` и `verbs`**

Семь `use` в список после `use app\shared\Forum\Service\ParserSettingsService;` (:11), порядок по
алфавиту внутри группы `app\shared\Llm`:

```php
use app\shared\Llm\Contract\LlmChatRepositoryInterface;
use app\shared\Llm\Contract\LlmJobQueueInterface;
use app\shared\Llm\Dto\LlmChatMessageData;
use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Llm\Service\TextStyleSampleReader;
```

Конструктор (:99-112) дополняется четырьмя параметрами между `$parserSettings` и `$config` —
существующие девять строк не меняются:

```php
    public function __construct(
        $id,
        $module,
        private readonly MailerInterface $mailer,
        private readonly Security $security,
        private readonly ChannelService $telegramChannel,
        private readonly PublicationsService $publications,
        private readonly ForumRepositoryInterface $forum,
        private readonly ?PublicationSettingsService $settings = null,
        private readonly ?ParserSettingsService $parserSettings = null,
        private readonly ?LlmTextService $llmText = null,
        private readonly ?LlmJobQueueInterface $llmJobs = null,
        private readonly ?LlmChatRepositoryInterface $llmChat = null,
        private readonly ?TextStyleSampleReader $llmReader = null,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }
```

Пять строк в `verbs` (:147-176) после `'parser-settings-save' => ['post'],`:

```php
                    'llm-spelling' => ['post'],
                    'llm-style' => ['post'],
                    'llm-chat' => ['post'],
                    'llm-style-status' => ['get'],
                    'llm-chat-history' => ['get'],
```

Два глагола статуса и истории — `get`, как и записал спек в веке 8: форма опрашивает очередь и
достаёт историю без поста, а `VerbFilter` тогда не пропустит к ним ничего другого. Остальные три —
`post` наравне с прочими ручками страницы.

- [ ] **Step 5: Состояние панели и два помощника**

Весь блок новых методов вставляется в хвост класса — после `getUserDisplayTimezone()` (:2873-2885) и
перед закрывающей `}` файла (:2886). Первым идёт публичный метод: страница спрашивает его так же, как
блок частички спрашивает `publicationSettings()` (:125), — пропускать число через каждый рендер
незачем.

```php
    /**
     * The three facts the panel of the form needs from the server: whether the
     * connection is stored at all, which style the reader stands on, and whether
     * the folder of that style is there to read. The page asks for them the way a
     * block partial asks for publicationSettings(), and the key of the proxy is
     * not among them — the panel gets a boolean in its place and never the secret
     * itself, which is what lets the answer be logged or painted into the page.
     *
     * @return array{configured: bool, style: string, readable: bool}
     */
    public function llmPanelState(): array
    {
        $style = $this->settings !== null ? (string) $this->settings->value('activeTextStyle') : '';

        $wired = $this->llmText !== null
            && $this->llmJobs !== null
            && $this->llmChat !== null
            && $this->llmReader !== null;

        return [
            'configured' => $wired && $this->llmMissingConnection() === null,
            'style' => $style,
            'readable' => $style !== '' && $this->llmReader !== null && $this->llmReader->has($style),
        ];
    }

    /**
     * The reason the panel cannot talk to the proxy, or null when it can. Only the
     * two numbers that decide it are read here: the key is used as a fact of
     * being present, and the address of the schema default already points at the
     * proxy of the customer.
     */
    private function llmMissingConnection(): ?string
    {
        if ($this->settings === null) {
            return 'Настройки публикаций не заведены — ИИ не настроен.';
        }

        $key = (string) $this->settings->value('llmApiKey');
        $baseUrl = (string) $this->settings->value('llmBaseUrl');

        if ($key === '' || $baseUrl === '') {
            return 'Нет ключа или адреса прокси — ИИ не настроен.';
        }

        return null;
    }

    /**
     * The answer of a verb whose services are missing: the DI of Task 12 wires all
     * four of them, and a controller built without any — as three older tests
     * still build it — has no panel to speak of.
     */
    private function llmNothingWired(): Response
    {
        return $this->asJson(['ok' => false, 'error' => 'За ИИ ничего не заведено.']);
    }
```

`configured` держит и проводку, и хранилище: ручка, которая гаснет из-за забытого определения DI,
честнее ручки, что отказывает уже после клика.

- [ ] **Step 6: Орфография и стилизация**

```php
    /**
     * Checks the spelling of one part and answers with the text and the marks the
     * field takes. Nothing is written to the record: the reader still holds the
     * form, and the answer lands in the editor of the part the same way the
     * toolbar puts a mark there — the buttons the form already has save it.
     *
     * The group of parts is not asked for: an orthography check leaves no row
     * behind, so the verb needs the text and nothing that ties it to a record.
     *
     * @return Response
     */
    public function actionLlmSpelling(): Response
    {
        $refused = $this->llmMissingConnection();
        if ($refused !== null) {
            return $this->asJson(['ok' => false, 'error' => $refused]);
        }

        if ($this->llmText === null) {
            return $this->llmNothingWired();
        }

        $text = (string) $this->request->post('llmText', '');
        $formatting = (string) $this->request->post('llmFormatting', '[]');
        $partOrder = (int) $this->request->post('llmPartOrder', 0);

        if (trim($text) === '') {
            return $this->asJson(['ok' => false, 'error' => 'Часть пуста — править нечего.']);
        }

        try {
            $answer = $this->llmText->spelling($text, $formatting);
        } catch (LlmRequestException $e) {
            return $this->asJson(['ok' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson([
            'ok' => true,
            'partOrder' => $partOrder,
            'text' => $answer->text,
            'formatting' => $answer->formattingJson(),
        ]);
    }

    /**
     * Puts the stylization of one part into the queue and answers with the number
     * of its row: the reader pressed the handle, the two calls that make a
     * portrait and rewrite the text are the business of the worker of Task 12,
     * which steps once a minute.
     *
     * The style is taken here, from the setting the reader stands on, so the row
     * freezes the style the press meant even if he moves the active style before
     * the minute step reaches the row.
     *
     * @return Response
     */
    public function actionLlmStyle(): Response
    {
        $refused = $this->llmMissingConnection();
        if ($refused !== null) {
            return $this->asJson(['ok' => false, 'error' => $refused]);
        }

        if ($this->llmJobs === null) {
            return $this->llmNothingWired();
        }

        $panel = $this->llmPanelState();
        if ($panel['style'] === '') {
            return $this->asJson(['ok' => false, 'error' => 'Стиль не выбран, стилизовать нечем.']);
        }

        if ($panel['readable'] === false) {
            return $this->asJson([
                'ok' => false,
                'error' => 'Папки стиля «' . $panel['style'] . '» в каталоге нет — стилизовать нечем.',
            ]);
        }

        $groupId = trim((string) $this->request->post('llmPartGroupId', ''));
        if (preg_match('/^[a-f0-9]{32}$/', $groupId) !== 1) {
            return $this->asJson(['ok' => false, 'error' => 'Группа частей не названа.']);
        }

        $partOrder = (int) $this->request->post('llmPartOrder', 0);
        $text = (string) $this->request->post('llmText', '');
        $formatting = (string) $this->request->post('llmFormatting', '[]');

        if (trim($text) === '') {
            return $this->asJson(['ok' => false, 'error' => 'Часть пуста — править нечего.']);
        }

        $jobId = $this->llmJobs->enqueue(
            new LlmJobData(
                0,
                LlmJobData::QUEUED,
                LlmJobData::KIND_STYLE,
                $groupId,
                $partOrder,
                $panel['style'],
                $text,
                $formatting,
            ),
            gmdate('Y-m-d H:i:s'),
        );

        return $this->asJson([
            'ok' => true,
            'jobId' => $jobId,
            'partOrder' => $partOrder,
            'style' => $panel['style'],
        ]);
    }

    /**
     * What became of a queued stylization. The form asks every three seconds, so
     * this verb reads only the row and touches no settings: a job that is already
     * in the queue runs even if the reader removed the key in the meantime, and
     * the page has to be able to say what happened to it.
     *
     * @return Response
     */
    public function actionLlmStyleStatus(): Response
    {
        if ($this->llmJobs === null) {
            return $this->llmNothingWired();
        }

        $jobId = (int) $this->request->get('llmJobId', 0);
        if ($jobId <= 0) {
            return $this->asJson(['ok' => false, 'error' => 'Задача очереди не названа.']);
        }

        $job = $this->llmJobs->find($jobId);
        if ($job === null) {
            return $this->asJson(['ok' => false, 'error' => 'Задачи очереди с номером ' . $jobId . ' нет.']);
        }

        return $this->asJson([
            'ok' => true,
            'status' => $job->status,
            'text' => $job->resultText,
            'formatting' => $job->resultFormatting,
            'error' => $job->error,
        ]);
    }
```

`text` и `formatting` ответа статуса — `null`, пока ряд не дошёл: заполнитель `'[]'` здесь был бы
обещанием, что разметка уже есть, и форма подставила бы пустые метки в часть. Форма ждёт статуса
`done` и только тогда читает оба поля — тот же смысл, что держат колонки таблицы.

Дата, которой глагол помечает постановку, — `gmdate('Y-m-d H:i:s')`, те же часы, что у рабочего из
Task 12 и у случайных времён публикаций. Разница поясов в очереди означала бы, что дрейнер считает
«устаревший» ряд по часам, которых писатель не имел в виду.

- [ ] **Step 7: Два глагола диалога**

```php
    /**
     * One reply of the free dialog. The window of the conversation is read before
     * the command is stored, so the model does not meet the same sentence twice —
     * once as the command over the text and once as a past reply. The two rows the
     * verb leaves in the history stand in it in the order the reader typed them.
     *
     * A refused call keeps the row of the command and writes no answer: the reader
     * did ask, and the next reply reads the conversation with that question in it.
     *
     * @return Response
     */
    public function actionLlmChat(): Response
    {
        $refused = $this->llmMissingConnection();
        if ($refused !== null) {
            return $this->asJson(['ok' => false, 'error' => $refused]);
        }

        if ($this->llmText === null || $this->llmChat === null) {
            return $this->llmNothingWired();
        }

        $groupId = trim((string) $this->request->post('llmPartGroupId', ''));
        if (preg_match('/^[a-f0-9]{32}$/', $groupId) !== 1) {
            return $this->asJson(['ok' => false, 'error' => 'Группа частей не названа.']);
        }

        $partOrder = (int) $this->request->post('llmPartOrder', 0);
        $command = trim((string) $this->request->post('llmCommand', ''));
        $text = (string) $this->request->post('llmText', '');
        $formatting = (string) $this->request->post('llmFormatting', '[]');

        if ($command === '') {
            return $this->asJson(['ok' => false, 'error' => 'Команда не написана.']);
        }

        if (trim($text) === '') {
            return $this->asJson(['ok' => false, 'error' => 'Часть пуста — править нечего.']);
        }

        $now = gmdate('Y-m-d H:i:s');

        $history = $this->llmChat->latest($groupId, $partOrder, LlmTextService::HISTORY_SHOWN);

        $userId = $this->llmChat->append(
            new LlmChatMessageData(0, $groupId, $partOrder, LlmChatMessageData::ROLE_USER, $command),
            $now,
        );

        /** @var array<int, array{role: string, content: string}> $window */
        $window = array_map(
            static fn (LlmChatMessageData $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ],
            $history,
        );

        try {
            $answer = $this->llmText->chat($command, $text, $formatting, $window);
        } catch (LlmRequestException $e) {
            return $this->asJson(['ok' => false, 'error' => $e->getMessage(), 'userId' => $userId]);
        }

        $assistantId = $this->llmChat->append(
            new LlmChatMessageData(
                0,
                $groupId,
                $partOrder,
                LlmChatMessageData::ROLE_ASSISTANT,
                $answer->text,
                $answer->text,
                $answer->formattingJson(),
            ),
            $now,
        );

        return $this->asJson([
            'ok' => true,
            'partOrder' => $partOrder,
            'userId' => $userId,
            'assistantId' => $assistantId,
            'text' => $answer->text,
            'formatting' => $answer->formattingJson(),
        ]);
    }

    /**
     * The dialog of one part as the block of the form paints it: the last fifty
     * replies, oldest first, each with the text and the marks the reader is
     * offered when the row is an answer. This verb reads only the storage, so a
     * conversation is still there after the key of the proxy has been taken away —
     * the reader can read what was said, only not ask for more.
     *
     * @return Response
     */
    public function actionLlmChatHistory(): Response
    {
        if ($this->llmChat === null) {
            return $this->llmNothingWired();
        }

        $groupId = trim((string) $this->request->get('llmPartGroupId', ''));
        if (preg_match('/^[a-f0-9]{32}$/', $groupId) !== 1) {
            return $this->asJson(['ok' => false, 'error' => 'Группа частей не названа.']);
        }

        $partOrder = (int) $this->request->get('llmPartOrder', 0);

        $rows = $this->llmChat->latest($groupId, $partOrder, LlmTextService::HISTORY_SHOWN);

        return $this->asJson([
            'ok' => true,
            'partOrder' => $partOrder,
            'messages' => array_map(
                static fn (LlmChatMessageData $message): array => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'replacementText' => $message->replacementText,
                    'replacementFormatting' => $message->replacementFormatting,
                    'createdAt' => $message->createdAt,
                ],
                $rows,
            ),
        ]);
    }
```

Реплика assistant несёт `content` и `replacement_text` одним и тем же текстом, а `replacement_*`
держат ещё и метки: история читается как разговор (модель видит `content`), а кнопка «Принять» в
блоке диалога берёт из той же строки разметку. Двух разных чисел в одной строке нет, поэтому и
расойтись они не могут.

- [ ] **Step 8: Прогнать зелёным и снять гейты**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/SiteControllerLlmPanelTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/SiteControllerHeadingBlockStateTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/LoginTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/LogoutTest.php'
docker compose exec -T app sh -lc 'php -l controllers/SiteController.php'
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 controllers/SiteController.php app/tests/Unit/SiteControllerLlmPanelTest.php app/tests/Unit/shared/Llm/Service/FakeChatHistory.php
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
```

Ожидаем `OK (23 tests, …)` на новый файл, `OK (20 tests, …)` на проверки блока заголовка и по
`OK (1 test, …)` на вход и выход: конструктор дополнен, а трое, кто собирает его позиционными
аргументами, этого не заметили. phpcs — 0 ошибок, phpstan — прежние 5.

- [ ] **Step 9: Пять адресов в `web.php`**

Пять строк в `rules` (:141-177) сразу после `'publication-part-schedule' => 'site/publication-part-schedule',`
(:165), пока не началась группа `forum-*`:

```php
                'llm-spelling' => 'site/llm-spelling',
                'llm-style' => 'site/llm-style',
                'llm-style-status' => 'site/llm-style-status',
                'llm-chat' => 'site/llm-chat',
                'llm-chat-history' => 'site/llm-chat-history',
```

Плоско и по строке на глагол, как держат адреса этой страницы все её ручки: правила читаются по
порядку, и адрес вида `<controller>/<action>` поймал бы их и сам, но страница держит
свои адреса списком — новый глагол без строки был бы отклонением от соседских.

```sh
docker compose exec -T app sh -lc 'php -l config/web.php'
```

- [ ] **Step 10: Правка спека**

8-й раздел файла `docs/superpowers/specs/2026-11-04-llm-integration-design.md` обещает, что пять глаголов
отдают через `finishPublicationsRequest()`. Заменяем предложение на тот смысл, который реализован:

```
Пять глаголов в `SiteController`: `actionLlmSpelling`, `actionLlmStyle`, `actionLlmStyleStatus`,
`actionLlmChat`, `actionLlmChatHistory`; все в список `verbs` ([SiteController.php:147](file:///E:/SERVER/Work_awd/app/controllers/SiteController.php#L147)) с `POST`, кроме `llm-style-status` и `llm-chat-history` (GET). Отдают они `$this->asJson()` — как `actionHeadingFormattingSave()` (:1594) и `actionPublicationPartGroup()` (:2545), а не `finishPublicationsRequest()` (:922): тот метод на любом исходе перерисовывает `publicationsData()` и четыре блока, а форма опрашивает статус задачи раз в три секунды.
```

- [ ] **Step 11: Живой гейт проводки**

Стек поднят тем, что осталось от шага 10 Task 12 (`postgres app nginx llm`). Порт nginx — из `.env`,
по умолчанию 8081. `access` в `behaviors()` закрывает только `logout` (:138), поэтому глаголы
страницы доступны и без входа; POST всё равно упрётся в CSRF.

```sh
curl -s -o /dev/null -w "POST llm-spelling -> %{http_code}\n" -X POST http://127.0.0.1:8081/llm-spelling
curl -s http://127.0.0.1:8081/llm-style-status > "$TEMP/llm-status.json"
curl -s http://127.0.0.1:8081/llm-chat-history > "$TEMP/llm-history.json"
```

Ожидаем `400` на POST: адрес разобран, глагол найден, до тела запроса не дошло только потому, что
токена CSRF в `curl` нет. Оба GET отдают `200`, и доказывает это их **тело** — Yii кодирует JSON без
`JSON_UNESCAPED_UNICODE`, поэтому кириллица в ответе экранирована и разбор её даёт только `Read`
файла, а не консоль (`$TEMP` Git Bash — папка Temp профиля Windows). В `$TEMP/llm-status.json`
ожидаем «Задача очереди не названа.», в `$TEMP/llm-history.json` — «Группа частей не названа.»:
параметры не переданы, и до прокси оба глагола не дошли.

Тот же `200`, но со словами «За ИИ ничего не заведено.» (в экранированном виде их выдаёт `\u0418\u0418`
в середине строки), означал бы обратное: определения DI из Task 12 в контейнер не легли и контроллер
получил `null` вместо сервиса. `500` означал бы, что глагол вообще не собрался. Ни того, ни другого
юнит не поймает: там контроллер собирается руками и о проводке контейнера ничего не знает.

- [ ] **Step 12: Закоммитить**

```sh
git add app/controllers/SiteController.php \
        app/config/web.php \
        app/tests/Unit/SiteControllerLlmPanelTest.php \
        app/tests/Unit/shared/Llm/Service/FakeChatHistory.php \
        docs/superpowers/specs/2026-11-04-llm-integration-design.md
git commit -F .git/COMMIT_MSG
```

```
feat(llm): пять глаголов панели в SiteController, состояние для вьюхи и пять адресов в web.php
```

---

### Task 14: Вьюха — ряд двух ручек и карточка диалога

7-й раздел спека даёт форме две ручки и блок свободного диалога. Здесь ложится только то, что
страница отдаёт сама: разметка, два правила листа и четыре имени, из которых разметка
рисует гашение. Скрипт, что ходит по этим пяти адресам, — Task 15; до него ряд стоит на странице
немым, и это честное состояние, а не недоделка: харнесс этого задания читает вьюху как текст, а
не как живой DOM.

**Почему подготовка имени живёт во вьюхе, а не в контроллере.** `llmPanelState()` (Task 13)
отдаёт три факта — заведено ли подключение, выбран ли стиль, читается ли его папка. Слова,
которыми гашеная ручка объясняет читателю своё гашение, — это уже не факт, а формулировка, и
держит её та страница, что их показывает: ровно так же в этом файле живут подписи `title` у
ручек разбивки. Контроллер, знающий про вьюху, был бы обратным направлением зависимости.

**Почему вьюха не спрашивает ключ.** Панель отвечает на то, что лежит в хранилище, а не на то,
что показывают поля страницы настроек: пустой `llmApiKey` там значит «не меняли», и честное
чтение «заведён ли ключ» даёт только строка базы. Поэтому в prepare — ни `llmApiKey`, ни
`llmBaseUrl`: только боoleans, которыми ответил контроллер. Проверка D-части харнесса считает
вхождения этих двух слов по всему файлу и требует нуля.

**Files:**
- Create: `app/runtime/pubjs/llmpanel.js` — части A–D: ряд, карточка, лист, подготовка имён
  (вне git: `app/runtime/` не индексируется).
- Modify: `app/views/site/publications.php:60` — имена после `$headingNumberStyle = …`
- Modify: `app/views/site/publications.php:956` — два правила перед терминатором `CSS`
- Modify: `app/views/site/publications.php:1610` — ряд двух ручек после закрытия
  `#publicationSplitModes`
- Modify: `app/views/site/publications.php:1768` — карточка диалога после закрытия
  `.publication-parts-group`
- Reference: `app/views/site/parser-settings.php:15` — как вьюха уже зовёт метод контроллера
  через `$this->context->`; `app/views/site/publications.php:1611-1615` и `:1770-1783` — образец
  блока `<?php /* … */ ?>` перед рядом и перед рамкой.

**Interfaces:**
- Consumes: `llmPanelState()` из Task 13 — три боoleans о подключении, выбранном стиле и читаемости
  его папки, и ни одного промта; пять глаголов `site/llm-*` (route из Task 13), чьи адреса это
  задание только называет — по ним ходит Task 15.
- Produces: якоря, из которых Task 15 собирает руку — `#publicationLlmControls`,
  `#publicationLlmSpelling`, `#publicationLlmStyle`, `#publicationLlmCommand`, `#publicationLlmSend`,
  `#publicationLlmSendKey`, `#publicationLlmThreads`, `#publicationLlmStatus` и класс
  `.publication-llm-panel`; плюс часть A–D харнесса `app/runtime/pubjs/llmpanel.js`, которая читает
  вьюху как текст. Ни одной новой PHP-величины не заводится: из `app/` правится только вьюха.

- [ ] **Step 1: Красный харнесс**

Файл `app/runtime/pubjs/llmpanel.js`. Конвенция аргументов — та же, что у соседей: `argv[2]` —
путь к вырезанному `page.js` (здесь не нужен, пустая строка), `argv[3]` — вьюха; без аргументов
харнесс читает настоящий файл, и ровно так его зовёт `run-all.js`:

```javascript
// The panel of the AI: the row of the two handles, the card of the dialog and the two
// rules the page writes for them, as the server lays them out.
//
// Until this revision the page asked nothing of a model. The two handles now stand in the
// green box of the parts, because what they work on is the text of a part, and the card of
// the dialog stands outside that box, because a conversation must not be rebuilt when the
// parts are. Both of them read the storage through the state the controller answers with —
// never a field of the settings page, where an empty key means «not changed» — and the
// reason a handle cannot be pressed is written into the handle, not into a notice.
//
// Nothing here runs a script: this is the part of the harness that reads the page as the
// server sends it out. The script of the panel — the five addresses, the row the thread is
// built of, the poll of the queue — is the next part of this same file.
//
//   node llmpanel.js
//   node llmpanel.js '' view.mut.php
const fs = require('fs');
const path = require('path');

const VIEW = 'E:/SERVER/Work_awd/app/views/site/publications.php';

const read = (file, fallback) => fs.readFileSync(file ? path.resolve(file) : fallback, 'utf8')
    .replace(/\r/g, '');

const view = read(process.argv[3], VIEW);

// The view splits in two: everything the server writes out, and the nowdoc of the page
// script. The row, the card and the rules of the panel all belong to the first half; the
// CSS is cut off from it by the comment the markup starts with, so a rule is never counted
// as a piece of the form.
const lines = view.split('\n');
const jsOpen = lines.findIndex((line) => line.includes("<<<'JS'"));
let jsClose = -1;
for (let i = jsOpen + 1; i < lines.length; i += 1) {
    if (lines[i] === 'JS') {
        jsClose = i;
        break;
    }
}
if (jsOpen < 0 || jsClose < 0) {
    throw new Error('nowdoc boundaries not found');
}

const page = lines.slice(jsOpen + 1, jsClose).join('\n');
const before = view.slice(0, view.indexOf(page));
const rowStartMark = before.indexOf('<!-- Row start -->');
const css = rowStartMark >= 0 ? before.slice(0, rowStartMark) : '';
const body = rowStartMark >= 0 ? before.slice(rowStartMark) : before;

let pass = 0;
let fail = 0;
function report(label, detail) {
    fail += 1;
    console.log('FAIL ' + label + '\n  ' + JSON.stringify(detail));
}
function ok(label, condition, detail) {
    if (condition) {
        pass += 1;

        return;
    }
    report(label, detail === undefined ? false : detail);
}
function stop() {
    console.log('llm panel: ' + pass + ' passed, ' + fail + ' failed');
    process.exit(fail === 0 ? 0 : 1);
}

function tagOf(source, id) {
    const at = source.indexOf('id="' + id + '"');
    if (at < 0) {
        return '';
    }

    return source.slice(source.lastIndexOf('<', at), source.indexOf('>', at) + 1);
}

// A handle of the panel carries an echo of PHP inside its own tag, and the closing `>`
// of that echo is not the end of the tag. So a tag asked of a control that writes itself
// from the state is taken up to the terminator the control really has — otherwise the
// half of the tag that holds the state is cut off, and a rule reads a handle that never
// was drawn.
function controlOf(source, id) {
    const at = source.indexOf('id="' + id + '"');
    if (at < 0) {
        return '';
    }
    const close = source.indexOf('</button>', at);

    return source.slice(source.lastIndexOf('<', at), close < 0 ? at : close);
}

function textAfter(source, id) {
    const tag = tagOf(source, id);
    if (tag === '') {
        return '';
    }

    return source.slice(source.indexOf(tag) + tag.length,
        source.indexOf('</button>', source.indexOf(tag) + tag.length));
}

// --- part A: the row of the two handles --------------------------------------

const ROW_TAG = '<div class="d-flex flex-wrap justify-content-end gap-2 mb-2" id="publicationLlmControls">';
const rowAt = body.indexOf('id="publicationLlmControls"');
const numberingAt = body.indexOf('class="publication-numbering-controls"', rowAt);
const row = rowAt < 0 || numberingAt < 0 ? '' : body.slice(rowAt, numberingAt);

ok('the row of the AI handles stands once',
    (body.match(/id="publicationLlmControls"/g) || []).length === 1,
    (body.match(/id="publicationLlmControls"/g) || []).length);
ok('the row gathers at the right edge the way the row of the split does',
    body.indexOf(ROW_TAG) >= 0, body.slice(rowAt, rowAt + 120));
ok('the row stands inside the box of the parts controls',
    body.indexOf('class="publication-parts-controls"') >= 0
        && body.indexOf('class="publication-parts-controls"') < rowAt,
    [body.indexOf('class="publication-parts-controls"'), rowAt]);
ok('the row stands under the row of the split and above the box of the numbering',
    body.indexOf('id="publicationSplitModes"') < rowAt
        && rowAt < body.indexOf('class="publication-numbering-controls"', rowAt),
    [body.indexOf('id="publicationSplitModes"'), rowAt,
        body.indexOf('class="publication-numbering-controls"', rowAt)]);

const statusAt = row.indexOf('id="publicationLlmStatus"');
ok('the word of the state stands before both handles, so the handles hold the edge',
    statusAt >= 0 && statusAt < row.indexOf('id="publicationLlmSpelling"')
        && statusAt < row.indexOf('id="publicationLlmStyle"'),
    [statusAt, row.indexOf('id="publicationLlmSpelling"'), row.indexOf('id="publicationLlmStyle"')]);
ok('the word of the state is a live region',
    /<span[^>]*id="publicationLlmStatus"[^>]*role="status"|<span[^>]*role="status"[^>]*id="publicationLlmStatus"/
        .test(row), tagOf(row, 'publicationLlmStatus'));

const SPELL_TAG = controlOf(row, 'publicationLlmSpelling');
const STYLE_TAG = controlOf(row, 'publicationLlmStyle');
ok('both handles are buttons of a form that must not be submitted by them',
    SPELL_TAG !== '' && STYLE_TAG !== '' && /type="button"/.test(SPELL_TAG) && /type="button"/.test(STYLE_TAG),
    [SPELL_TAG, STYLE_TAG]);
ok('both handles wear the info of a control that writes for the reader',
    /class="btn btn-outline-info btn-sm"/.test(SPELL_TAG)
        && /class="btn btn-outline-info btn-sm"/.test(STYLE_TAG),
    [SPELL_TAG, STYLE_TAG]);
ok('each handle is named by the icon of what it does',
    /<i class="bi bi-spellcheck me-1"><\/i>/.test(row) && /<i class="bi bi-brush me-1"><\/i>/.test(row),
    [textAfter(row, 'publicationLlmSpelling').slice(0, 40),
        textAfter(row, 'publicationLlmStyle').slice(0, 40)]);
ok('the two words asked of the reader are the two of the request',
    /Проверить орфографию/.test(textAfter(row, 'publicationLlmSpelling'))
        && /Стилизовать/.test(textAfter(row, 'publicationLlmStyle')),
    [textAfter(row, 'publicationLlmSpelling'), textAfter(row, 'publicationLlmStyle')]);
ok('each handle carries its reason in its title, encoded',
    /title="<\?= Html::encode\(\$llmSpellingTitle\) \?>"/.test(SPELL_TAG)
        && /title="<\?= Html::encode\(\$llmStyleTitle\) \?>"/.test(STYLE_TAG),
    [SPELL_TAG, STYLE_TAG]);
ok('each handle is put out of use by the state it was drawn from',
    /<\?= \$llmSpellingOff \? 'disabled' : '' \?>/.test(SPELL_TAG)
        && /<\?= \$llmStyleOff \? 'disabled' : '' \?>/.test(STYLE_TAG),
    [SPELL_TAG, STYLE_TAG]);

// --- part B: the card of the dialog ------------------------------------------

const cardAt = body.indexOf('<div class="publication-llm-panel');
const headingAt = body.indexOf('class="publication-heading-controls"', cardAt);
const card = cardAt < 0 || headingAt < 0 ? '' : body.slice(cardAt, headingAt);

ok('the card of the dialog stands once',
    (body.match(/class="publication-llm-panel/g) || []).length === 1,
    (body.match(/class="publication-llm-panel/g) || []).length);
ok('the card stands outside the group the parts are rebuilt in and above the box of the heading',
    body.indexOf('class="publication-parts-group"') < cardAt
        && cardAt < body.indexOf('class="publication-heading-controls"', cardAt)
        && body.indexOf('id="publicationAlbumHome"') > body.indexOf('class="publication-heading-controls"', cardAt),
    [body.indexOf('class="publication-parts-group"'), cardAt,
        body.indexOf('class="publication-heading-controls"', cardAt),
        body.indexOf('id="publicationAlbumHome"')]);
ok('the card is a card of the page: its own class, the sheet of the card, one room under it',
    /<div class="publication-llm-panel card mb-3">/.test(card), card.slice(0, 80));
ok('the header of the card is the header of the ui-kit and wears no colour',
    /<div class="card-header">\s*<h5 class="card-title mb-0">Диалог с ИИ<\/h5>/.test(card)
        && !/bg-|text-(?:primary|info|warning|danger|success|secondary)/.test(
            card.slice(0, card.indexOf('<div class="card-body">'))),
    card.slice(card.indexOf('card-header'), card.indexOf('card-header') + 160));
ok('the rows of the dialog are nested the way the notification-center is nested',
    card.indexOf('class="scroll350"') >= 0
        && card.indexOf('class="scroll350"') < card.indexOf('class="notification-center"')
        && card.indexOf('class="notification-center"') < card.indexOf('id="publicationLlmThreads"'),
    [card.indexOf('class="scroll350"'), card.indexOf('class="notification-center"'),
        card.indexOf('id="publicationLlmThreads"')]);
ok('the threads of the dialog open empty: the page fills them from the server',
    /<div class="threads" id="publicationLlmThreads"><\/div>/.test(card),
    (card.match(/<div class="threads"[^>]*>[\s\S]{0,40}/) || [''])[0]);
ok('nothing of the card names itself for the form',
    card !== '' && (card.match(/\bname="/g) || []).length === 0, (card.match(/\bname="/g) || []));
ok('every button of the card is a button that does not submit',
    (card.match(/<button\b/g) || []).length === 2
        && (card.match(/<button[^>]*type="button"/g) || []).length === 2,
    [(card.match(/<button\b/g) || []).length, (card.match(/<button[^>]*type="button"/g) || []).length]);
ok('the field of the command is a textarea of two rows and keeps the room for its handle',
    /<textarea class="form-control pe-5" rows="2"/.test(card)
        && /id="publicationLlmCommand"/.test(tagOf(card, 'publicationLlmCommand')),
    tagOf(card, 'publicationLlmCommand'));
ok('the round handle stands in the wrapper of the field and the separate button under it',
    card.indexOf('class="position-relative mt-3"') >= 0
        && card.indexOf('id="publicationLlmCommand"') > card.indexOf('class="position-relative mt-3"')
        && card.indexOf('id="publicationLlmSendKey"') < card.indexOf('id="publicationLlmSend"')
        && card.indexOf('id="publicationLlmSendKey"')
            < card.indexOf('</div>', card.indexOf('id="publicationLlmSendKey"')),
    [card.indexOf('class="position-relative mt-3"'), card.indexOf('id="publicationLlmSendKey"'),
        card.indexOf('id="publicationLlmSend"')]);
ok('the round handle is a handle and not a word: an icon and a name for a screen reader',
    /aria-label="Отправить"/.test(tagOf(card, 'publicationLlmSendKey'))
        && /<i class="bi bi-arrow-up"><\/i>/.test(card), tagOf(card, 'publicationLlmSendKey'));
ok('the two senders say what they send',
    /<i class="bi bi-arrow-up"><\/i>/.test(card)
        && /id="publicationLlmSend"[^>]*>[^<]*Отправить/.test(card.replace(/\s+/g, ' ')),
    textAfter(card, 'publicationLlmSend'));

// --- part C: the two rules of the page ---------------------------------------

const rowsRule = (css.match(/\.publication-llm-panel \.threads \.thread p \{[^}]*\}/) || [''])[0];
const keyRule = (css.match(/\.publication-llm-send-key \{[^}]*\}/) || [''])[0];
ok('both rules are written into the sheet of the page, not into a vendor file',
    rowsRule !== '' && keyRule !== '', [rowsRule, keyRule]);
ok('a row of the dialog holds a long text of the channel without widening the card',
    /white-space: pre-line/.test(rowsRule) && /word-break: break-word/.test(rowsRule), rowsRule);
ok('the round handle is set inside the field it sends, and square',
    /position: absolute/.test(keyRule) && /right: \.5rem/.test(keyRule)
        && /bottom: \.5rem/.test(keyRule) && /width: 2rem/.test(keyRule)
        && /height: 2rem/.test(keyRule), keyRule);
ok('neither rule draws in the colour of the heading',
    rowsRule !== '' && keyRule !== '' && !/--bs-info-rgb/.test(rowsRule) && !/--bs-info-rgb/.test(keyRule),
    [rowsRule, keyRule]);

// --- part D: what the panel is allowed to know -------------------------------

ok('the view reads no key and no address of the proxy',
    !/llmApiKey/.test(view) && !/llmBaseUrl/.test(view),
    [(view.match(/llmApiKey/g) || []).length, (view.match(/llmBaseUrl/g) || []).length]);
ok('the panel asks the server what the storage holds',
    /\$llmPanel = \$this->context->llmPanelState\(\);/.test(before),
    (before.match(/\$llmPanel[^\n]*/) || [''])[0]);
ok('the three names the markup reads are made in the preparation, in order',
    before.indexOf('$llmSpellingOff =') >= 0 && before.indexOf('$llmStyleOff =') >= 0
        && before.indexOf('$llmSpellingTitle =') >= 0
        && before.indexOf('$llmPanel =') < before.indexOf('$llmSpellingOff =')
        && before.indexOf('$llmSpellingOff =') < before.indexOf('$llmStyleOff =')
        && before.indexOf('class="publication-llm-panel') > before.indexOf('$llmStyleTitle ='),
    [before.indexOf('$llmPanel ='), before.indexOf('$llmSpellingOff ='),
        before.indexOf('$llmStyleOff ='), before.indexOf('$llmStyleTitle =')]);

stop();
```

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs && node --check llmpanel.js
cd /e/SERVER/Work_awd/app/runtime/pubjs && node llmpanel.js > ../_tmp_llmpanel_red.txt 2>&1
```

Файл `_tmp_llmpanel_red.txt` читаем `Read` — метки харнесса ASCII, но деталь падения несёт
кириллицу из вьюхи, а через консоль Windows она неразбираема.

Ожидаем на нетронутой вьюхе `llm panel: 1 passed, 30 failed`. Зелёной остаётся одна проверка —
негативная: «во вьюхе нет ни `llmApiKey`, ни `llmBaseUrl`». Она зелёна и до правки, и после, и
именно поэтому её надо держать: она единственная сторожит то, чего в файле быть не должно.
Остальные тридцать падают, и каждую падение называет своими якорями.

- [ ] **Step 2: Четыре имени, которые читает разметка**

Вставляем сразу после `$headingNumberStyle = PublicationSettingsService::headingNumberStyleOf($headingNumberTemplate);`
(:60) — тот же отступ, пустая строка перед `$this->registerCss(` (:62) остаётся:

```php
// The panel of the AI answers to what the storage holds, never to what the fields of
// the settings page show: an empty key there means «not changed», so the only honest
// reading of «is a key kept» is the stored row. The controller gives that reading out as
// three facts — see llmPanelState() — and the words a disabled handle carries are made
// here, where the reader can read them.
$llmPanel = $this->context->llmPanelState();
$llmSpellingOff = $llmPanel['configured'] === false;
$llmStyleOff = $llmSpellingOff || $llmPanel['style'] === '' || $llmPanel['readable'] === false;
$llmSpellingTitle = 'Исправить опечатки, орфографию и пунктуацию в той части, где последним стоял редактор';
$llmStyleTitle = 'Переписать часть в манере выбранного стиля: задача встаёт в очередь, а очередь шагает раз в минуту';
if ($llmSpellingOff) {
    $llmSpellingTitle = 'Нет ключа и адреса прокси — ИИ не настроен на странице настроек';
    $llmStyleTitle = $llmSpellingTitle;
} elseif ($llmPanel['style'] === '') {
    $llmStyleTitle = 'Стиль не выбран — стилизовать нечем';
} elseif ($llmPanel['readable'] === false) {
    $llmStyleTitle = 'Папки стиля «' . $llmPanel['style'] . '» с образцом нет — стилизовать нечем';
}
```

`configured` ложен и тогда, когда четырёх сервисов нет в DI (шаг 5 Task 13 собирает `$wired`
именно так). Формулировка «Нет ключа и адреса прокси» остаётся одной на оба случая: читателю
важно, что ручка не работает, а причина «сервис не заведён» — это поломка сборки, и она
ловится живым гейтом Task 13, а не текстом в `title`.

- [ ] **Step 3: Два правила листа**

Вставляем перед терминатором `CSS` (:956), то есть после правила `.publication-list-block >
.card-body > .scroll350` (:951-955), — и пустая строка между ними:

```css
/* The block of the dialog is a card of the ui-kit, so the sheet gives it its line, its
   corners and its header, and the scroller of 350px it gives to `.scroll350`. Two things
   are left to this page. The rows of the conversation are written by the page itself, out
   of what the server answers, and a row has to hold a long text of the channel without
   widening the card — which is what the first rule says, and what the vendored sheet does
   not: the `.thread` of the ui-kit carries no rule of its own at all, it is made of
   Bootstrap utilities written into its markup, and a page that builds a row in code has no
   markup to write them into. The second rule puts the round handle of the field inside the
   field it sends; the wrapper that holds both is drawn by the markup, and `pe-5` is the
   room the field keeps clear for it. Neither rule draws in a colour: the two handles of the
   AI wear info as a button, from the class the theme already ships, and the frame of the
   heading stays the one box on this page that info is drawn round. */
.publication-llm-panel .threads .thread p {
    white-space: pre-line;
    word-break: break-word;
}

.publication-llm-send-key {
    position: absolute;
    right: .5rem;
    bottom: .5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    padding: 0;
}
```

Оба правила идут в `registerCss` этой страницы, а не в вендорный лист: `headingblock.js`
считает буквально, сколько раз в листе встречается `rgba(var(--bs-info-rgb), 1)`, и требует
ровно трёх носителей — рамки заголовка, его колец и его активного заполнения. Новое правило с
этим токеном сломало бы чужой зелёный харнесс, и потому ни одно из двух цвета не рисует.

- [ ] **Step 4: Ряд двух ручек**

Вставляем между `</div>` ряда `#publicationSplitModes` (:1610) и комментарием рамки нумерации
(:1611), с тем же отступом в 36 пробелов и без пустой строки — ровно как соседний ряд стоит
сам:

```php
                                    <?php /* Two handles of the AI, the word they answer with and
                                            the room the word stands in. They get a row of their own,
                                            under the row of the split and above the box of the
                                            numbering, inside the same green box: the reader is asking
                                            about the text of a part, and that is what the box holds.
                                            The word of the state goes before both buttons, so the two
                                            keep the right edge of the row and the mouse never has to
                                            chase a handle that moved while «Проверяю…» became
                                            «Готово». Both handles work on the part whose editor stood
                                            last on the page — the same rule the handle of the seam
                                            keeps — and both go out of use while the storage holds
                                            neither a key nor an address, which is what the state of
                                            the panel says. The handle of the style goes out of use
                                            twice more: while no style is chosen, and while the chosen
                                            style has no folder to read. A handle that is out of use
                                            says why in its title and stays on the page, because a
                                            door the reader cannot open through is still a door he is
                                            allowed to see. */ ?>
                                    <div class="d-flex flex-wrap justify-content-end gap-2 mb-2" id="publicationLlmControls">
                                        <span class="small text-muted align-self-center"
                                              id="publicationLlmStatus" role="status"></span>
                                        <button type="button" class="btn btn-outline-info btn-sm"
                                                id="publicationLlmSpelling"
                                                title="<?= Html::encode($llmSpellingTitle) ?>"
                                                <?= $llmSpellingOff ? 'disabled' : '' ?>>
                                            <i class="bi bi-spellcheck me-1"></i>Проверить орфографию
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-sm"
                                                id="publicationLlmStyle"
                                                title="<?= Html::encode($llmStyleTitle) ?>"
                                                <?= $llmStyleOff ? 'disabled' : '' ?>>
                                            <i class="bi bi-brush me-1"></i>Стилизовать
                                        </button>
                                    </div>
```

Метка ряда совпадает с меткой ряда разбивки (`d-flex flex-wrap justify-content-end gap-2 mb-2`)
намеренно: `notices.js` якорится полным тегом с `id="publicationSplitModes"` и на чужой `id`
не реагирует, а два ряда, собранные одним классом, и ведут себя одинаково при переносе.
`btn-outline-info` — цвет, названный в спеке; зелёный остаётся у разбивки, фиолетовый — у
коробки кнопок-ссылок, розовый — у даты, синий — у рядов перемещения альбома.

- [ ] **Step 5: Карточка диалога**

Вставляем после `</div>`, закрывающего `.publication-parts-group` (:1768): пустая строка :1769
стоит над блоком, а под ним — ещё одна, чтобы комментарий рамки заголовка (:1770) не прирос к
карточке. Отступ — 28 пробелов, как у самой группы и у рамки.

```php
                            <?php /* The free dialog with the model. The card stands outside the
                                   group the parts are rebuilt in, for the same reason the box of the
                                   heading below it does: a split, a merge or a move of the parts must
                                   not take the conversation with it. Its rows are the
                                   notification-center of the ui-kit — the scroller of 350px, the
                                   threads, one row per message — and the page paints them itself out
                                   of the history the server keeps, so a reload brings the
                                   conversation back and the reader finds what he already asked.
                                   Nothing in this card has a `name`: the form of a publication is a
                                   real form, and a control that names itself would travel out in
                                   `publicationText[]` with the texts of the parts. The round handle
                                   inside the field sends on Enter, the button under it sends on a
                                   click, and Shift+Enter only breaks a line — which is the whole of
                                   the keyboard the field answers to. The two words of an answer,
                                   «Принять» and «Отклонить», belong to a row of the conversation
                                   and never to a field of the form: the reader decides what an answer is
                                   worth, and the page writes his decision into the part he asked
                                   about. */ ?>
                            <div class="publication-llm-panel card mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Диалог с ИИ</h5>
                                </div>
                                <div class="card-body">
                                    <div class="scroll350">
                                        <div class="notification-center">
                                            <div class="threads" id="publicationLlmThreads"></div>
                                        </div>
                                    </div>
                                    <div class="position-relative mt-3">
                                        <textarea class="form-control pe-5" rows="2"
                                                  id="publicationLlmCommand"
                                                  placeholder="Перепиши этот абзац, сократи текст, убери упоминания"></textarea>
                                        <button type="button"
                                                class="btn btn-primary btn-sm rounded-circle publication-llm-send-key"
                                                id="publicationLlmSendKey" aria-label="Отправить"
                                                title="Отправить команду модели">
                                            <i class="bi bi-arrow-up"></i>
                                        </button>
                                    </div>
                                    <div class="d-flex justify-content-end mt-2">
                                        <button type="button" class="btn btn-primary btn-sm"
                                                id="publicationLlmSend"
                                                title="Спросить модель о той части, где последним стоял редактор">Отправить</button>
                                    </div>
                                </div>
                            </div>
```

Три вложенных дива скроллера (`scroll350 > notification-center > threads`) — та же цепочка, что
в `ui-kit/notifications.html`; `.scroll350` отдаёт тему с её же `height: 350px`, а
`custom-scrollbar.js` из `DashboardAsset` инициализирует оверлей по классу, так что ни одного
нового ассета карточка не просит. Ни у одного контрола здесь нет `name`, и обе кнопки —
`type="button"`: иначе они сдвинули бы `publicationText[]` и `publicationFormatting[]`.

- [ ] **Step 6: Зелёный прогон и нулевая коллапсия**

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs
node llmpanel.js
node headingblock.js; node notices.js; node buttonsperpart.js; node dateframe.js
node partsgroup.js; node seamsplit.js; node albumrowsblank.js
node numbertemplate.js; node titleeditor.js; node partnumber.js
```

Ожидаем `llm panel: 31 passed, 0 failed` и — по каждому из десяти соседей — ровно те же числа,
что и до правки, потому что рядом и карточкой добавлены узлы, а ни один из их якорей не
сдвинут:

| Харнесс | Ожидаем |
|---|---|
| `headingblock.js` | 110 passed, 0 failed |
| `notices.js` | 45 passed, 0 failed |
| `buttonsperpart.js` | 107 passed, 0 failed |
| `dateframe.js` | 40 passed, 0 failed |
| `partsgroup.js` | 75 passed, 0 failed |
| `seamsplit.js` | 38 passed, 4 failed (базовая линия) |
| `albumrowsblank.js` | 13 passed, 0 failed |
| `numbertemplate.js` | 134 passed, 0 failed |
| `titleeditor.js` | 93 passed, 0 failed |
| `partnumber.js` | 162 passed, 0 failed |

Падение любого из них — не «так вышло», а сигнал, что новый узел лёг в чужой срез: ряд попал
внутрь `.publication-numbering-controls`, или карточка встала внутри `.publication-parts-group`,
или правило листа принесло четвёртого носителя `info`.

- [ ] **Step 7: Харнесс встаёт в общий прогон**

`app/runtime/pubjs/run-all.js` (вне git) — в список `suites` после `'headingblock.js',`:

```javascript
    'llmpanel.js',
```

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs && node run-all.js; tail -3 run-all.txt
```

Ожидаем в отчёте строку `ok    llmpanel.js  31 passed, 0 failed` и прежний вердикт
`PROBLEMS: 5` — базовая линия песочницы красна по праву (четыре харнесса с устаревшими якорями
и один драйвер мутантов) и от этой правки не растёт. Числа снимаются этим прогоном, а не из
памяти: любой новый красный здесь — работа этого задания.

- [ ] **Step 8: Закоммитить**

`app/runtime/` в индекс не попадает — в stage идёт одна вьюха:

```sh
git add app/views/site/publications.php
git status --short
git commit -F .git/COMMIT_MSG
```

```
feat(llm): ряд двух ручек и карточка диалога ложатся в вьюху публикаций
```

Размер правки снимается до коммита и идёт в доку Task 18: `+129` строк во вьюхе (10 604 → 10 733),
и дельта строк длиннее 120 байт — `252 → 259`, то есть семь (четыре в подготовке имён, одна в
теге ряда, две в разметке карточки; кириллица считается двумя байтами). phpcs этого файла в
Task 16 переснимает то и другое живым прогоном, а не этим числом.

---

### Task 15: Вьюха — скрипт панели

Task 14 положил ряд и карточку; здесь к ним идёт рука, которая по ним ходит: пять адресов,
два глагола, отвечающих сразу, один — через очередь, ряд ответа с «Принять»/«Отклонить» и окно
диалога, которое следует за кареткой. Правится только nowdoc скрипта страницы и две строки в
рядах, которые уже существовали; ни одного нового файла в `app/` это задание не заводит.

**Почему страница читает гашение с нарисованных ручек, а не спрашивает контроллер.** Задача 13
отдала состояние один раз — вместе с разметкой, и `disabled` у двух ручек уже несёт в себе ответ
хранилища. Скрипту остаётся добавлять причины: часть пуста, рука занята, команда не написана.
Обратное направление было бы неверным в обе стороны: адрес «живо ли подключение» означал бы, что
страница переспрашивает о том, что ей только что сказали, а право зажигать ручку, которую сервер
погасил, у страницы вообще нет — харнесс считает это отдельной проверкой («no hand of the page
ever lights a handle the server put out of use») и ломает мутантом, который снимает флаг хранилища
из `disabled`.

**Почему орфография пишет в часть сама, а стилизация кончается рядом с двумя словами.** Спек
выбрал гибрид, и разница не в удобстве: проверка орфографии — один вызов над одним текстом, после
него метки стоят на тех же координатах, и читатель видит свою часть исправленной сразу; стилизация
сначала читает папку образца, поэтому считается минутами и уходит в `llm_job`, а очередь шагает по
ней раз в минуту: сама страница спрашивает её статус раз в три секунды и сдаётся через три минуты.
Текст, переписанный в чужой манере, — не опечатка, и навязывать его форма не права: он ложится рядом
двумя словами, и только «Принять» перекладывает его в поле. Ряд offer'а стоит в окне диалога — тот же
экран, что и реплики, чтобы читатель видел, что ему уже отвечали и чего он не взял.

**Почему ответ вставляет номер части двумя руками.** `seedPart` получает текст без номера — ровно
тем текстом часть отправляется модели и в тех же координатах живут её метки. Значит номер, который
читатель выбрал рядоми шаблоном, надо вернуть, а страница держит инвариант: каждый
`applyPartNumbers();` рядом с `applyHeadingNumbers();`. Проверка харнесса считает и то и другое по
всему скрипту и требует равенства, а `partnumber.js` следит за этим же за пределами панели —
именно он и поймал первую версию этого кода, где номер возвращался в текст и не возвращался в
заголовок.

**Files:**
- Modify: `app/runtime/pubjs/llmpanel.js` — к частям A–D дописывается часть E (вне git:
  `app/runtime/` не индексируется).
- Create: `app/runtime/pubjs/llmpanel-mutants.js` — драйвер мутантов той же части E (вне git).
- Modify: `app/views/site/publications.php:2623` — пять адресов после `$partDraftUrl = …`
- Modify: `app/views/site/publications.php:2670` — пять переменных после `var __PART_DRAFT_URL`
- Modify: `app/views/site/publications.php:3903` — читатель JSON перед комментарием зеркала адреса
- Modify: `app/views/site/publications.php:7612` и `:9510` — по одной строке
  `updateLlmControls();` после `updateMergeRows();`
- Modify: `app/views/site/publications.php:10718` — скрипт панели перед `renderUtcTimes(document);`
- Reference: `app/runtime/pubjs/dateframe-mutants.js` — форма драйвера мутантов;
  `app/views/site/publications.php:7612-7620` — ряд глаголов, перестраивающих части, в который
  вставляется строка панели; `app/views/site/publications.php:10718` — последняя команда `initAll`,
  перед которой панель и встаёт.

**Interfaces:**
- Consumes: восемь якорей и класс, которые Task 14 положил во вьюху; пять адресов глаголов Task 13
  (`$llmSpellingUrl`, `$llmStyleUrl`, `$llmStyleStatusUrl`, `$llmChatUrl`, `$llmHistoryUrl`) и формы
  их ответов, которые тот же Task 13 держит в `SiteController`; `disabled` двух ручек как уже
  готовый ответ хранилища.
- Produces: часть E харнесса `llmpanel.js` и её драйвер мутантов; скрипт страницы с восемью
  функциями панели — `llmTarget()`, `llmSpelling()`, `llmStyle()`, `llmPollStyle()`, `llmSay()`,
  `llmOffer()`, `llmHistory()` и `applyLlmAnswer()` — и рядом `updateLlmControls()`, которую
  вставляют два существующих ряда. PHP-интерфейсов это задание не заводит ни одного.

Номера строк сняты с вьюхи такой, какой её оставляет Task 14, — до этой правки файл 10 733
строки, после — 11 252.

- [ ] **Step 1: Красный харнесс**

В конец `app/runtime/pubjs/llmpanel.js` — вместо последней его строки `stop();` — дописывается
часть E: сорок её проверок на старой вьюхе красные. Проходит одна — последняя, и проходит заслуженно:
она считает носители `--bs-info-rgb` по всему файлу, а разметка Task 14 уже оставила ровно три, и до
скрипта панели ничего не добавляется. Тридцать одна проверка частей A–D остаются зелёными, поэтому
сумма и равна `32 passed, 40 failed`:

```javascript
// --- part E: the script of the panel ---------------------------------------

const count = (source, re) => (source.match(re) || []).length;

// The body of one function of the block of the parts, taken by the eight spaces that
// function stands on and the eight spaces that close it. A hand of the panel is asked on its
// own so that a word standing in the neighbour of it is never read as a promise of this one.
function fn8(name) {
    const start = page.indexOf('\n        function ' + name + '(');

    if (start < 0) {
        return '';
    }

    const body = page.slice(start).split('\n');
    let end = 1;

    while (end < body.length && body[end] !== '        }') {
        end += 1;
    }

    return body.slice(0, end + 1).join('\n');
}

// The two helpers of the requests stand one step higher, four spaces from the edge.
function fn4(name) {
    const start = page.indexOf('\n    function ' + name + '(');

    if (start < 0) {
        return '';
    }

    const body = page.slice(start).split('\n');
    let end = 1;

    while (end < body.length && body[end] !== '    }') {
        end += 1;
    }

    return body.slice(0, end + 1).join('\n');
}

// The line a given line hands its work over to, for the two rules that are about a pair of
// neighbours rather than about one line of them.
function nextLine(source, index) {
    return source.split('\n')[index + 1] || '';
}

const ADDRESS = ['__LLM_SPELLING_URL', '__LLM_STYLE_URL', '__LLM_STYLE_STATUS_URL',
    '__LLM_CHAT_URL', '__LLM_HISTORY_URL'];
const ROUTE = ['llm-spelling', 'llm-style', 'llm-style-status', 'llm-chat', 'llm-chat-history'];

ok('the five addresses of the panel are made the way the five of the lists are made',
    count(before, /Url::to\(\['site\/llm-/g) === 5
        && ROUTE.every((name) => count(before, new RegExp("Url::to\\(\\['site/" + name + "'\\]\\)", 'g')) === 1),
    (before.match(/Url::to\(\['site\/llm[^']*/g) || []));
ok('every one of them crosses into the script as a variable of its own',
    count(before, /var __LLM_[A-Z_]+ = /g) === 5
        && ADDRESS.every((name) => count(before, new RegExp(name + " = '\\{", 'g')) === 1),
    (before.match(/var __LLM_[A-Z_]+/g) || []));
ok('the five lines of the script keep the order the five lines of the view have',
    ADDRESS.every((name, index) => before.indexOf('var ' + name) >= 0
        && (index === 0 || before.indexOf('var ' + ADDRESS[index - 1]) < before.indexOf('var ' + name)))
        && ROUTE.every((name, index) => before.indexOf("['site/" + name + "']") >= 0
            && (index === 0
                || before.indexOf("['site/" + ROUTE[index - 1] + "']") < before.indexOf("['site/" + name + "']"))),
    ADDRESS.map((name) => before.indexOf('var ' + name)));
ok('the script asks every address of the panel once, by its variable and never by the route',
    ADDRESS.every((name) => count(page, new RegExp(name, 'g')) === 1)
        && count(page, /__LLM_/g) === 5
        && count(page, /['"]site\/llm/g) === 0,
    [count(page, /__LLM_/g), (page.match(/['"]site\/llm[^']*/g) || [])]);
ok('the panel adds one call of the network to the page and no more',
    count(page, /fetch\(/g) === 2, count(page, /fetch\(/g));
ok('the two reads are asked by a helper of their own, and it asks them as a read',
    count(page, /getForJson/g) === 3 && /method: 'GET'/.test(fn4('getForJson'))
        && !/body:/.test(fn4('getForJson')) && /credentials: 'same-origin'/.test(fn4('getForJson')),
    [count(page, /getForJson/g), fn4('getForJson').slice(0, 160)]);
ok('the queue and the dialog are the only two things read that way',
    count(page, /getForJson\(__LLM/g) === 2
        && /getForJson\(__LLM_STYLE_STATUS_URL, \{ llmJobId: jobId \}\)/.test(page)
        && /getForJson\(__LLM_HISTORY_URL, \{ llmPartGroupId: groupId, llmPartOrder: target\.index \}\)/
            .test(page),
    (page.match(/getForJson\(__LLM[A-Z_]*/g) || []));
ok('the three operations that change a part go through the hand the page already had',
    ['__LLM_SPELLING_URL', '__LLM_STYLE_URL', '__LLM_CHAT_URL']
        .every((name) => count(page, new RegExp('postForJson\\(' + name, 'g')) === 1),
    (page.match(/postForJson\(__LLM[A-Z_]*/g) || []));

const HAND = fn8('llmTarget');
ok('the part an answer belongs to is the part the handle under the list of parts would take',
    /var bare = bareParts\(\);/.test(HAND) && /splitTarget\(bare\.fields, bare\.values\)/.test(HAND)
        && !/lastEditedField/.test(HAND) && !/Math\.max/.test(HAND) && !/value\.length/.test(HAND),
    HAND);
ok('the text of that part goes out bare and its marks go out as one string of JSON',
    count(page, /llmText: target\.text/g) === 3
        && count(page, /JSON\.stringify\(target\.formatting\)/g) === 3,
    [count(page, /llmText: target\.text/g), count(page, /JSON\.stringify\(target\.formatting\)/g)]);
ok('a reply comes back in the same kind of string and is read again, not pasted',
    count(page, /JSON\.parse\(payload\.formatting\)/g) === 3, count(page, /JSON\.parse\(payload\.formatting\)/g));

const SPELL = fn8('llmSpelling');
ok('the orthography answers into the part itself and leaves no row of the dialog behind',
    /applyLlmAnswer\(target\.field, payload\.text, JSON\.parse\(payload\.formatting\)\);/.test(SPELL)
        && !/llmOffer/.test(SPELL) && !/llmPartGroupId/.test(SPELL),
    SPELL);
const WRITE = fn8('applyLlmAnswer').replace(/\s+/g, ' ');
const STEPS = ['seedPart(field, text,', 'applyPartNumbers();', 'applyHeadingNumbers();',
    'updateCounters();', 'writeFormattingFields();', 'update();'];
ok('a text the model answered is restated in the order a fill of the forum restates it',
    STEPS.every((step, index) => WRITE.indexOf(step) >= 0
        && (index === 0 || WRITE.indexOf(STEPS[index - 1]) < WRITE.indexOf(step))),
    [WRITE, STEPS.map((step) => WRITE.indexOf(step))]);
ok('a number the answer puts back into the text it puts back into the heading with it',
    /applyPartNumbers\(\);\n\s+applyHeadingNumbers\(\);/.test(fn8('applyLlmAnswer'))
        && count(page, /applyPartNumbers\(\);\n\s+applyHeadingNumbers\(\);/g)
            === count(page, /applyPartNumbers\(\);/g),
    [count(page, /applyPartNumbers\(\);\n\s+applyHeadingNumbers\(\);/g), count(page, /applyPartNumbers\(\);/g)]);
const PRESS = fn8('llmStyle');
ok('a stylization is only put into the queue: it writes no part with its own hand',
    !/applyLlmAnswer/.test(PRESS)
        && /llmPollStyle\(payload\.jobId, target\.index, payload\.style \|\| ''\);/.test(PRESS),
    PRESS);
const OFFER = fn8('llmOffer');
ok('the name of the style is carried across the wait, not read again when it ends',
    /function llmPollStyle\(jobId, index, style\)/.test(page)
        && /Стилизовано в манере «' \+ style \+ '»/.test(fn8('llmPollStyle'))
        && !/payload\.style/.test(fn8('llmPollStyle'))
        && count(fn8('llmPollStyle'), /style/g) === 3,
    [count(fn8('llmPollStyle'), /style/g), (fn8('llmPollStyle').match(/.{0,20}style.{0,20}/g) || [])]);
ok('the page waits on the queue by a timer of its own and never by a loop',
    /var LLM_POLL_MS = 3000;/.test(page) && /var LLM_WAIT_MS = 180000;/.test(page)
        && count(page, /llmPollTimer = setTimeout/g) === 1 && !/setInterval/.test(page),
    [count(page, /setTimeout/g), count(page, /setInterval/g)]);
const ASK = fn8('llmPollStyle');
ok('the wait of the page is a clock, not a count of the asks',
    /llmWaitUntil = Date\.now\(\) \+ LLM_WAIT_MS;/.test(PRESS)
        && /if \(Date\.now\(\) > llmWaitUntil\) \{/.test(ASK)
        && /Очередь не ответила за три минуты\./.test(ASK),
    [PRESS.indexOf('llmWaitUntil'), ASK.indexOf('llmWaitUntil')]);
ok('every end of a wait takes the next ask off',
    count(ASK, /llmStopPolling\(\);/g) === 4 && count(PRESS, /llmStopPolling\(\);/g) === 1
        && /clearTimeout\(llmPollTimer\);/.test(fn8('llmStopPolling')),
    [count(ASK, /llmStopPolling\(\);/g), count(PRESS, /llmStopPolling\(\);/g)]);
ok('the queue is asked by its number and the number is the one the press answered with',
    /'llmJobId'/.test(ASK) === false && /payload\.jobId/.test(PRESS) && /\{ llmJobId: jobId \}/.test(ASK),
    [PRESS.indexOf('payload.jobId'), ASK.indexOf('llmJobId')]);
ok('an answer the reader has not taken yet is shown to him in full',
    /answer\.textContent = text;/.test(OFFER) && /llmAddRow\('assistant', words\);/.test(OFFER)
        && /added\.body\.appendChild\(answer\);/.test(OFFER),
    OFFER);
ok('the two words of an offer are buttons that do not submit, and only the one that takes colours',
    /take\.type = 'button';/.test(OFFER) && /drop\.type = 'button';/.test(OFFER)
        && /take\.className = 'btn btn-outline-info btn-sm';/.test(OFFER)
        && /drop\.className = 'btn btn-outline-secondary btn-sm';/.test(OFFER)
        && /take\.textContent = 'Принять';/.test(OFFER) && /drop\.textContent = 'Отклонить';/.test(OFFER)
        && !/\.style\./.test(OFFER),
    [OFFER.indexOf('.style.'), OFFER.indexOf('Отклонить')]);
ok('an offer that arrives after its part was cut away says so instead of writing into a neighbour',
    /var fields = textParts\(\);/.test(OFFER) && /if \(index >= fields\.length\) \{/.test(OFFER)
        && /ушла из формы — принять нечего\./.test(OFFER)
        && /applyLlmAnswer\(fields\[index\], text, formatting\);/.test(OFFER),
    OFFER);
ok('an answer the reader refused leaves the screen with its own words, not with the whole dialog',
    /drop\.addEventListener\('click', function \(\) \{\n\s+box\.remove\(\);/.test(OFFER)
        && !/llmThreadsBox/.test(OFFER)
        && count(page, /llmThreadsBox\.textContent = '';/g) === 1,
    [OFFER.indexOf('llmThreadsBox'), count(page, /llmThreadsBox\.textContent = '';/g)]);
const ROW = fn8('llmAddRow');
const CHAT = fn8('llmChat');
const HISTORY = fn8('llmHistory');
ok('a row of the dialog is built of elements and of words, never of markup',
    (ROW + OFFER + HISTORY).indexOf('innerHTML') === -1 && /text\.textContent = words;/.test(ROW)
        && count(page, /innerHTML/g) === 8,
    [count(page, /innerHTML/g), (ROW + OFFER + HISTORY).indexOf('innerHTML')]);
ok('who spoke is drawn by the glyph of it and nothing the panel builds is a field of the form',
    /role === 'user' \? 'bi-person' : 'bi-cpu'/.test(ROW)
        && /row\.className = 'thread mb-3 pb-3 border-bottom';/.test(ROW)
        && /line\.className = 'd-flex align-items-start gap-2';/.test(ROW)
        && (ROW + OFFER).indexOf('.name =') === -1,
    ROW);
ok('a reply of the dialog is an offer too and says no word above itself',
    /llmOffer\(target\.index, payload\.text, JSON\.parse\(payload\.formatting\), ''\);/.test(CHAT)
        && /if \(words !== ''\) \{/.test(ROW),
    [CHAT.indexOf('llmOffer'), ROW.indexOf("words !== ''")]);
ok('what the reader asked is put on the screen before the answer to it arrives',
    CHAT.indexOf("llmAddRow('user', command);") >= 0
        && CHAT.indexOf("llmAddRow('user', command);") < CHAT.indexOf('postForJson(__LLM_CHAT_URL')
        && CHAT.indexOf("llmCommandField.value = '';") < CHAT.indexOf('postForJson(__LLM_CHAT_URL'),
    [CHAT.indexOf("llmAddRow('user'"), CHAT.indexOf('postForJson'), CHAT.indexOf("value = ''")]);
ok('the dialog that is shown is the dialog of the part the caret stands in',
    /partsBox\.addEventListener\('focusin', llmHistory\);/.test(page)
        && /target\.index === llmHistoryPart/.test(HISTORY)
        && /llmHistoryPart = target\.index;/.test(HISTORY)
        && /llmThreadsBox\.textContent = '';/.test(HISTORY),
    HISTORY);
ok('a part that has no group behind it is not asked for a dialog',
    count(page, /\/\^\[a-f0-9\]\{32\}\$\//g) === 1 && /\{32\}\$\/\.test\(groupId\)/.test(HISTORY)
        && HISTORY.indexOf('{32}') < HISTORY.indexOf('getForJson(__LLM_HISTORY_URL'),
    [count(page, /\/\^\[a-f0-9\]\{32\}\$\//g), HISTORY.indexOf('{32}')]);
ok('the group a stylization hangs on is invented the way the submit of the form invents it',
    count(page, /crypto\.getRandomValues\(new Uint8Array\(16\)\)/g) === 2
        && /setPartGroupId\(/.test(fn8('llmGroupId'))
        && /if \(partGroupIdInput\.value === ''\) \{/.test(fn8('llmGroupId')),
    [count(page, /crypto\.getRandomValues/g), fn8('llmGroupId')]);

const RESTATE = fn8('updateLlmControls');
ok('the two reasons the storage knows are read off the drawn handles once',
    /var llmSpellingOff = !!\(llmSpellingBtn && llmSpellingBtn\.disabled\);/.test(page)
        && /var llmStyleOff = !!\(llmStyleBtn && llmStyleBtn\.disabled\);/.test(page)
        && /var llmChatOff = llmSpellingOff;/.test(page),
    (page.match(/var llm\w*Off = .*/g) || []));
ok('no hand of the page ever lights a handle the server put out of use',
    /llmSpellingBtn\.disabled = llmSpellingOff \|\| empty \|\| llmSpellingBusy;/.test(RESTATE)
        && /llmStyleBtn\.disabled = llmStyleOff \|\| empty \|\| llmStyleBusy;/.test(RESTATE)
        && /llmSendBtn\.disabled = llmChatOff \|\| empty \|\| llmChatBusy \|\| !asked;/.test(RESTATE)
        && /llmCommandField\.disabled = llmChatOff;/.test(RESTATE),
    RESTATE);
ok('the reason the page has is written into the handle and the word of the server put back',
    ['Проверка уже идёт.', 'Стилизация уже стоит в очереди.', 'Модель уже отвечает.',
        'Часть пуста — править нечего.', 'Команда не написана.'].every((word) => page.indexOf(word) >= 0)
        && /var llmSpellingWord = llmSpellingBtn \? llmSpellingBtn\.title : '';/.test(page)
        && /var llmStyleWord = llmStyleBtn \? llmStyleBtn\.title : '';/.test(page)
        && /: llmSpellingWord\);/.test(RESTATE) && /: llmStyleWord\);/.test(RESTATE)
        && /: llmSendWord\)\);/.test(RESTATE),
    [page.indexOf('Проверка уже идёт.'), RESTATE.slice(0, 200)]);
ok('a handle the server darkened keeps the word the server gave it',
    count(RESTATE, /&& !llm(?:Spelling|Style|Chat)Off \? 'Часть пуста/g) === 3
        && /!asked && !llmChatOff \? 'Команда не написана\.'/.test(RESTATE),
    (RESTATE.match(/!llm\w+Off \? '[^']*/g) || []));
ok('both places the parts are restated restate the panel with them',
    (() => {
        const lines = page.split('\n');
        const hooked = [];
        lines.forEach((line, index) => {
            if (line.trim() === 'updateMergeRows();') {
                hooked.push(nextLine(page, index).trim() === 'updateLlmControls();');
            }
        });

        return count(page, /updateMergeRows\(\);/g) === 2 && hooked.length === 2 && hooked.every((next) => next);
    })(),
    (page.match(/updateMergeRows\(\);/g) || []).length);
ok('every flag of a wait that goes up or down is followed by the restating of the handles',
    (() => {
        const lines = page.split('\n');
        const left = [];
        let writes = 0;
        lines.forEach((line, index) => {
            if (/^\s+llm(?:Spelling|Style|Chat)Busy = (?:true|false);$/.test(line)) {
                writes += 1;

                if (nextLine(page, index).trim() !== 'updateLlmControls();') {
                    left.push(index + 1);
                }
            }
        });

        return writes === 10 && left.length === 0;
    })(),
    (page.match(/^\s+llm(?:Spelling|Style|Chat)Busy = (?:true|false);$/gm) || []).length);
ok('Enter asks and Shift-Enter only breaks the line of the command',
    /if \(event\.key === 'Enter' && !event\.shiftKey\) \{/.test(page)
        && /event\.preventDefault\(\);\n\s+llmChat\(\);/.test(page)
        && count(page, /addEventListener\('click', llmChat\);/g) === 2,
    (page.match(/addEventListener\('click', llmChat\);/g) || []).length);
ok('the two senders light up with the first word of the command and go out with its last',
    /llmCommandField\.addEventListener\('input', updateLlmControls\);/.test(page)
        && /var asked = !!\(llmCommandField && llmCommandField\.value\.trim\(\) !== ''\);/.test(RESTATE)
        && /llmSendKeyBtn\.disabled = llmChatOff \|\| empty \|\| llmChatBusy \|\| !asked;/.test(RESTATE),
    RESTATE.slice(RESTATE.indexOf('var asked'), RESTATE.indexOf('var asked') + 90));
ok('the handles and the dialog are set once when the page is drawn',
    /\n        updateLlmControls\(\);\n        llmHistory\(\);\n/.test(page)
        && page.indexOf('        updateLlmControls();\n        llmHistory();')
            < page.indexOf('        renderUtcTimes(document);'),
    [page.indexOf('        llmHistory();'), page.indexOf('        renderUtcTimes(document);')]);
ok('the script of the panel paints no colour of the heading anywhere in the page',
    count(view, /--bs-info-rgb/g) === 3, count(view, /--bs-info-rgb/g));

stop();
```

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs && node llmpanel.js
```

Ожидаем `llm panel: 32 passed, 40 failed`.

- [ ] **Step 2: Пять адресов**

После строки `$partDraftUrl = …`, одной пустой строкой:

```php
// The five addresses the panel of the AI asks: two of them take the text of a part
// and answer with a text of their own, one puts a stylization into the queue of
// Task 12, and the two that stay are read — the queue is asked how one of its rows
// stands, and the dialog is asked what was said of this part before.
$llmSpellingUrl = \yii\helpers\Url::to(['site/llm-spelling']);
$llmStyleUrl = \yii\helpers\Url::to(['site/llm-style']);
$llmStyleStatusUrl = \yii\helpers\Url::to(['site/llm-style-status']);
$llmChatUrl = \yii\helpers\Url::to(['site/llm-chat']);
$llmHistoryUrl = \yii\helpers\Url::to(['site/llm-chat-history']);
```

- [ ] **Step 3: Пять переменных скрипта**

Сразу после `var __PART_DRAFT_URL = …`, без пустой строки — они того же ряда переменных, что
несёт странице адреса списков:

```javascript
var __LLM_SPELLING_URL = '{$llmSpellingUrl}';
var __LLM_STYLE_URL = '{$llmStyleUrl}';
var __LLM_STYLE_STATUS_URL = '{$llmStyleStatusUrl}';
var __LLM_CHAT_URL = '{$llmChatUrl}';
var __LLM_HISTORY_URL = '{$llmHistoryUrl}';
```

- [ ] **Step 4: Читатель JSON**

Перед комментарием `// The address bar mirrors the state the server has just stored` — блок
начинается с пустой строки:

```javascript

    // The two reads of the panel — how one row of the queue stands, and what the
    // dialog of one part holds — carry no body and name themselves in the address.
    // The token every write of this page wraps along belongs to a request that
    // changes something, and neither of these two changes anything, so the query is
    // built from the words and nothing else is added to it.
    function getForJson(url, params) {
        var query = new URLSearchParams(params || {}).toString();

        return fetch(url + (query === '' ? '' : '?' + query), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(function (payload) {
            if (!payload || typeof payload !== 'object') {
                throw new Error('некорректный ответ сервера');
            }

            return payload;
        });
    }
```

Два чтения панели — очередь и окно диалога — не меняют ничего, поэтому они не несут ни тела, ни
токена: запрос собирается из `URLSearchParams`, а `postForJson` этого ряда страниц остаётся
единственной дорогой, которая пишет. Харнесс считает `fetch(` по всему скрипту и требует ровно
двух — своего глагола панель не заводит.

- [ ] **Step 5: Два ряда перестройки**

В обоих местах, где страница перестраивает части, после `updateMergeRows();` вставляется одна
строка `updateLlmControls();`: в ряду 7612 (перестройка частей) и в ряду 9510 (отклик на набор в
поле части). Больше в тех двух рядах не меняется ничего — панель не лезет в чужой ряд, а только
просит пересчитать себя, когда части уже пересчитаны.

- [ ] **Step 6: Скрипт панели — состояние**

Перед `renderUtcTimes(document);` вставляется блок, начинающийся с пустой строки. Сначала — всё,
что панель помнит о себе: ручки, три причины хранилища, снятые с нарисованной разметки, три флага
занятости, таймер очереди и две функции, которые перекрашивают ручки и собирают часть, чей ответ
ожидается.

```javascript
        // --- the panel of the AI -----------------------------------------------

        // Three of the reasons a handle of the panel goes dark belong to the page and
        // not to the storage: which part the caret was written in last, whether that
        // part holds a word, and whether the same question is already on its way. The
        // two the server knows — no key of the proxy, no style to rewrite in — are
        // drawn into the markup by the preparation of the view, so they are read off
        // the drawn handle once here and never asked again: a handle the page drew
        // disabled stays disabled whatever the caret does after it.
        var llmStatusBox = document.getElementById('publicationLlmStatus');
        var llmSpellingBtn = document.getElementById('publicationLlmSpelling');
        var llmStyleBtn = document.getElementById('publicationLlmStyle');
        var llmThreadsBox = document.getElementById('publicationLlmThreads');
        var llmCommandField = document.getElementById('publicationLlmCommand');
        var llmSendKeyBtn = document.getElementById('publicationLlmSendKey');
        var llmSendBtn = document.getElementById('publicationLlmSend');
        var llmSpellingOff = !!(llmSpellingBtn && llmSpellingBtn.disabled);
        var llmStyleOff = !!(llmStyleBtn && llmStyleBtn.disabled);
        var llmChatOff = llmSpellingOff;
        var llmSpellingWord = llmSpellingBtn ? llmSpellingBtn.title : '';
        var llmStyleWord = llmStyleBtn ? llmStyleBtn.title : '';
        var llmSendWord = llmSendBtn ? llmSendBtn.title : '';
        var llmSpellingBusy = false;
        var llmStyleBusy = false;
        var llmChatBusy = false;
        var llmPollTimer = null;
        var llmWaitUntil = 0;
        var llmHistoryPart = -1;
        // The queue of Task 12 is stepped once a minute, so the page asks it three
        // seconds at a time and stops asking after three minutes: one minute for the
        // step to reach the row, two more for the pair of calls a stylization is
        // made of, and the rest is the time the reader was never meant to watch.
        var LLM_POLL_MS = 3000;
        var LLM_WAIT_MS = 180000;

        function llmSay(words) {
            if (llmStatusBox) {
                llmStatusBox.textContent = words;
            }
        }

        // The part an answer of the panel belongs to: the field the caret was written
        // in last, and without a caret the longest of them — the same two words the
        // handle under the list of parts splits by, so the sentence the reader was
        // editing is the sentence the model is asked about. The text and its marks
        // come out bare, without the number of the part in front of them, because
        // that is the text the server is told to rewrite and the coordinates the
        // marks of its answer are counted in.
        function llmTarget() {
            var bare = bareParts();
            var index = splitTarget(bare.fields, bare.values);

            if (index === -1) {
                return null;
            }

            return {
                index: index,
                field: bare.fields[index],
                text: bare.values[index],
                formatting: bare.formats[index],
            };
        }

        // The queue and the dialog hang their rows on the group of the parts, and a
        // publication nothing was saved under has no group until its submit invents
        // one. The panel asks for the same thirty-two words the submit would have
        // written, so a part stylized before the first save is found again after it.
        function llmGroupId() {
            if (!partGroupIdInput) {
                return '';
            }

            if (partGroupIdInput.value === '') {
                setPartGroupId(Array.from(crypto.getRandomValues(new Uint8Array(16)))
                    .map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join(''));
            }

            return partGroupIdInput.value;
        }

        function updateLlmControls() {
            var target = llmTarget();
            var empty = target === null;
            var asked = !!(llmCommandField && llmCommandField.value.trim() !== '');

            if (llmSpellingBtn) {
                llmSpellingBtn.disabled = llmSpellingOff || empty || llmSpellingBusy;
                llmSpellingBtn.title = llmSpellingBusy ? 'Проверка уже идёт.'
                    : (empty && !llmSpellingOff ? 'Часть пуста — править нечего.'
                        : llmSpellingWord);
            }

            if (llmStyleBtn) {
                llmStyleBtn.disabled = llmStyleOff || empty || llmStyleBusy;
                llmStyleBtn.title = llmStyleBusy ? 'Стилизация уже стоит в очереди.'
                    : (empty && !llmStyleOff ? 'Часть пуста — править нечего.' : llmStyleWord);
            }

            if (llmCommandField) {
                llmCommandField.disabled = llmChatOff;
            }

            if (llmSendKeyBtn) {
                llmSendKeyBtn.disabled = llmChatOff || empty || llmChatBusy || !asked;
            }

            if (llmSendBtn) {
                llmSendBtn.disabled = llmChatOff || empty || llmChatBusy || !asked;
                llmSendBtn.title = llmChatBusy ? 'Модель уже отвечает.'
                    : (empty && !llmChatOff ? 'Часть пуста — править нечего.'
                        : (!asked && !llmChatOff ? 'Команда не написана.' : llmSendWord));
            }
        }

        // The text a model answered goes through the same hands a fill of the forum
        // uses: the value and its marks are written bare, the number of the part is put
        // back by the switch that owns it — into the text and into the heading in one
        // breath, the way every other hand that renumbers does — and only then the
        // counters, the fields the form submits and the preview are restated. Written in
        // the other order the answer would reach the channel as «Часть 3» over the body
        // of a part the reader had told to wear its number in the heading.
        function applyLlmAnswer(field, text, formatting) {
            seedPart(field, text, Array.isArray(formatting) ? formatting : []);
            applyPartNumbers();
            applyHeadingNumbers();
            updateCounters();
            writeFormattingFields();
            update();
        }

        // A row of the dialog is the row of the ui-kit: the glyph of who spoke, the
        // words under it, the line under those. They are written as words and never
        // as markup, because an answer of the model is a text nobody vouched for, and
        // a bracket that reached it has to stay a bracket on the screen.
        function llmAddRow(role, words) {
            var row = document.createElement('div');
            var line = document.createElement('div');
            var mark = document.createElement('i');
            var body = document.createElement('div');

            row.className = 'thread mb-3 pb-3 border-bottom';
            line.className = 'd-flex align-items-start gap-2';
            mark.className = 'bi ' + (role === 'user' ? 'bi-person' : 'bi-cpu');
            body.className = 'flex-grow-1';

            if (words !== '') {
                var text = document.createElement('p');
                text.className = 'mb-1';
                text.textContent = words;
                body.appendChild(text);
            }

            line.appendChild(mark);
            line.appendChild(body);
            row.appendChild(line);

            if (llmThreadsBox) {
                llmThreadsBox.appendChild(row);
                llmScrollDown();
            }

            return { row: row, body: body };
        }

        function llmScrollDown() {
            var scroller = llmThreadsBox ? llmThreadsBox.closest('.scroll350') : null;

            if (!scroller) {
                return;
            }
            var viewport = scroller.querySelector('.os-viewport') || scroller;
            viewport.scrollTop = viewport.scrollHeight;
        }

        // The two words an answer that rewrites a part ends with. The part of the form
        // changes only when the reader says so, and the row of the answer stays either
        // way: a rewrite he refused is still a thing that was said, and the dialog of
        // the part keeps it beside the ones he took. The index is carried instead of
        // the field, because a queue that answers a minute later meets a form the
        // reader may have cut in two since the press.
        function llmOffer(index, text, formatting, words) {
            var added = llmAddRow('assistant', words);
            var answer = document.createElement('p');
            var box = document.createElement('div');
            var take = document.createElement('button');
            var drop = document.createElement('button');

            answer.className = 'mb-0';
            answer.textContent = text;
            box.className = 'd-flex justify-content-end gap-2 mt-2';
            take.type = 'button';
            take.className = 'btn btn-outline-info btn-sm';
            take.textContent = 'Принять';
            drop.type = 'button';
            drop.className = 'btn btn-outline-secondary btn-sm';
            drop.textContent = 'Отклонить';

            take.addEventListener('click', function () {
                var fields = textParts();

                if (index >= fields.length) {
                    showFlash('error', 'Часть ' + (index + 1)
                        + ' ушла из формы — принять нечего.');

                    return;
                }
                applyLlmAnswer(fields[index], text, formatting);
                box.remove();
                llmSay('Часть ' + (index + 1) + ' переписана.');
            });
            drop.addEventListener('click', function () {
                box.remove();
                llmSay('Отклонено — часть осталась как была.');
            });
            box.appendChild(take);
            box.appendChild(drop);
            added.body.appendChild(answer);
            added.body.appendChild(box);
        }
```

- [ ] **Step 7: Скрипт панели — орфография**

Тем же блоком, сразу за состоянием:

```javascript

        // One call, and no row behind it: a check of the orthography answers into the
        // part itself, so the only word the dialog gets is the one beside the handles.
        function llmSpelling() {
            var target = llmTarget();

            if (target === null || llmSpellingBusy) {
                return;
            }

            llmSpellingBusy = true;
            updateLlmControls();
            llmSay('Проверяю часть ' + (target.index + 1) + '…');

            postForJson(__LLM_SPELLING_URL, {
                llmPartOrder: target.index,
                llmText: target.text,
                llmFormatting: JSON.stringify(target.formatting),
            }).then(function (payload) {
                if (payload.ok !== true) {
                    throw new Error(payload.error || 'ИИ не ответил.');
                }

                applyLlmAnswer(target.field, payload.text, JSON.parse(payload.formatting));
                llmSay('Часть ' + (target.index + 1) + ' проверена — правки внесены.');
            }).catch(function (error) {
                llmSay('');
                showFlash('error', 'Орфография не проверена: '
                    + (error && error.message ? error.message : error));
            }).then(function () {
                llmSpellingBusy = false;
                updateLlmControls();
            });
        }

        if (llmSpellingBtn) {
            llmSpellingBtn.addEventListener('click', llmSpelling);
        }
```

- [ ] **Step 8: Скрипт панели — стилизация и её ожидание**

```javascript

        // Stylization is the one operation of the panel that does not answer on the
        // spot: a pair of calls of the model and a worker that steps once a minute
        // stand behind it, so the press only puts a row into the queue and the asking
        // of that row is what brings the text back. The name of the style is taken
        // from the answer of the press, not from the setting as it stands a minute
        // later: the row of the queue froze the style the reader meant.
        function llmStyle() {
            var target = llmTarget();

            if (target === null || llmStyleBusy) {
                return;
            }

            llmStyleBusy = true;
            updateLlmControls();
            llmSay('Ставлю часть ' + (target.index + 1) + ' в очередь…');

            postForJson(__LLM_STYLE_URL, {
                llmPartGroupId: llmGroupId(),
                llmPartOrder: target.index,
                llmText: target.text,
                llmFormatting: JSON.stringify(target.formatting),
            }).then(function (payload) {
                if (payload.ok !== true) {
                    throw new Error(payload.error || 'ИИ не ответил.');
                }
                llmWaitUntil = Date.now() + LLM_WAIT_MS;
                llmSay('Часть ' + (target.index + 1)
                    + ' в очереди — она шагает раз в минуту.');
                llmPollStyle(payload.jobId, target.index, payload.style || '');
            }).catch(function (error) {
                llmStopPolling();
                llmStyleBusy = false;
                updateLlmControls();
                llmSay('');
                showFlash('error', 'Стилизация не поставлена в очередь: '
                    + (error && error.message ? error.message : error));
            });
        }

        // One ask of one row of the queue. The clock of the worker and the clock of the
        // page are two different clocks, so the row may be answered long before the next
        // of these asks comes — and the wait ends the moment it is. Three minutes of
        // asking is the end of the patience of the page, not of the queue: the row stays
        // where it is, and a reader who presses the handle again finds the same answer
        // standing there.
        function llmPollStyle(jobId, index, style) {
            if (Date.now() > llmWaitUntil) {
                llmStopPolling();
                llmStyleBusy = false;
                updateLlmControls();
                llmSay('');
                showFlash('error', 'Очередь не ответила за три минуты.');

                return;
            }

            getForJson(__LLM_STYLE_STATUS_URL, { llmJobId: jobId }).then(function (payload) {
                if (payload.ok !== true) {
                    throw new Error(payload.error || 'Очередь не ответила.');
                }

                if (payload.status === 'done') {
                    llmStopPolling();
                    llmStyleBusy = false;
                    updateLlmControls();
                    llmSay('');
                    llmOffer(index, payload.text, JSON.parse(payload.formatting),
                        'Стилизовано в манере «' + style + '».');

                    return;
                }

                if (payload.status === 'failed') {
                    llmStopPolling();
                    llmStyleBusy = false;
                    updateLlmControls();
                    llmSay('');
                    showFlash('error', 'Стилизация не удалась: '
                        + (payload.error || 'ИИ не ответил.'));

                    return;
                }
                llmPollTimer = setTimeout(function () {
                    llmPollStyle(jobId, index, style);
                }, LLM_POLL_MS);
            }).catch(function (error) {
                llmStopPolling();
                llmStyleBusy = false;
                updateLlmControls();
                llmSay('');
                showFlash('error', 'Очередь не отвечает: '
                    + (error && error.message ? error.message : error));
            });
        }

        // The timer of one wait: a queue that answered, a queue that refused and a
        // queue the reader is no longer waiting for are all the same thing to the page,
        // and none of them leaves a second ask running behind the first.
        function llmStopPolling() {
            if (llmPollTimer !== null) {
                clearTimeout(llmPollTimer);
                llmPollTimer = null;
            }
        }

        if (llmStyleBtn) {
            llmStyleBtn.addEventListener('click', llmStyle);
        }
```

Ряд очереди держится на часах, а не на счёте спросов: `llmWaitUntil` ставится в момент постановки,
и каждый спрос смотрит на `Date.now()`. Рама ожидания и рамка очереди сошлись на одном числе: при
дефолтных 90 секундах таймаута `staleHorizon()` Task 12 отдаёт ровно те же 180 секунд, — раньше
этого срока чужой ряд роборука поднять не имеет права, а позже он уже поднят, и новому спросу
страницы нечего ждать. Больше таймаут — рамка очереди дальше, и страница сдаётся первой; меньше —
ряд вернётся в очередь раньше, чем страница устанет. Название стиля переносится через весь вызов
ожидания отдельным словом — ответ статуса его не знает, и подпись «Стилизовано в манере „Ватник в
Америке“» иначе пришлось бы выпрашивать вторым адресом.

- [ ] **Step 9: Скрипт панели — диалог**

```javascript

        // One call per question. The reader's sentence is put on the screen the moment
        // it is sent, before the answer, because the wait of a rewrite is longer than
        // the patience of a page: a dialog that shows nothing while the model thinks
        // reads as a handle that did not take the click.
        function llmChat() {
            var target = llmTarget();
            var command = llmCommandField ? llmCommandField.value.trim() : '';

            if (target === null || command === '' || llmChatBusy) {
                return;
            }

            llmChatBusy = true;
            updateLlmControls();
            llmSay('Спрашиваю часть ' + (target.index + 1) + '…');
            llmAddRow('user', command);
            llmCommandField.value = '';

            postForJson(__LLM_CHAT_URL, {
                llmPartGroupId: llmGroupId(),
                llmPartOrder: target.index,
                llmCommand: command,
                llmText: target.text,
                llmFormatting: JSON.stringify(target.formatting),
            }).then(function (payload) {
                if (payload.ok !== true) {
                    throw new Error(payload.error || 'ИИ не ответил.');
                }

                llmOffer(target.index, payload.text, JSON.parse(payload.formatting), '');
            }).catch(function (error) {
                // The row of the reader is already in the storage by the time a call of
                // the model fails, so the sentence that is on the screen here is the
                // sentence the next read of the dialog will bring back.
                showFlash('error', 'Диалог не задался: '
                    + (error && error.message ? error.message : error));
            }).then(function () {
                llmChatBusy = false;
                updateLlmControls();
                llmSay('');
            });
        }

        // The dialog of the part the caret stands in, oldest first. It is read as words
        // and not as offers: a rewrite the reader left two hours ago is a thing that was
        // said, and the two words that take it belong to the answer that has just come.
        // A part with no group behind it has no dialog either — the rows hang on the
        // group, and inventing one to read nothing about would only make the field of
        // the form hold a word nobody meant.
        function llmHistory() {
            var target = llmTarget();
            var groupId = partGroupIdInput ? partGroupIdInput.value : '';

            if (!llmThreadsBox || target === null || target.index === llmHistoryPart) {
                return;
            }

            if (!/^[a-f0-9]{32}$/.test(groupId)) {
                return;
            }

            llmHistoryPart = target.index;
            getForJson(__LLM_HISTORY_URL, { llmPartGroupId: groupId, llmPartOrder: target.index })
                .then(function (payload) {
                    if (payload.ok !== true || !Array.isArray(payload.messages)) {
                        return;
                    }

                    llmThreadsBox.textContent = '';
                    payload.messages.forEach(function (message) {
                        llmAddRow(message.role === 'user' ? 'user' : 'assistant', message.content);
                    });
                })
                .catch(function () {
                    llmHistoryPart = -1;
                });
        }

        if (llmCommandField) {
            llmCommandField.addEventListener('keydown', function (event) {
                // Enter asks, Shift-Enter breaks the line: a command is three words as
                // often as it is one, and the field that holds it has to keep both.
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    llmChat();
                }
            });
            // The two senders answer to the field: they light up with its first word and
            // go out again with its last.
            llmCommandField.addEventListener('input', updateLlmControls);
        }
        if (llmSendKeyBtn) {
            llmSendKeyBtn.addEventListener('click', llmChat);
        }
        if (llmSendBtn) {
            llmSendBtn.addEventListener('click', llmChat);
        }
        // The caret moves between the parts by a click, and the dialog follows it: the
        // conversation the reader sees is the conversation of the part he is looking at.
        partsBox.addEventListener('focusin', llmHistory);

        updateLlmControls();
        llmHistory();
```

Реплика читателя ложится на экран до запроса, а не после ответа: модель думает секунды, и строка,
которая появляется вместе с ответом, выглядит так, будто её написал он. История читается по
`focusin` поля части и только когда каретка перешла в другую часть, — иначе каждое нажатие клавиши
спрашивало бы окно диалога заново и стирало бы те ряды offer'ов, которые ещё не приняты.

- [ ] **Step 10: Синтаксис скрипта**

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs && node extract.js && node --check page.js
```

Ожидаем `page.js: 8541 lines` и молчание `node --check`.

- [ ] **Step 11: Зелёный прогон и нулевая коллапсия**

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs
node llmpanel.js
node headingblock.js; node notices.js; node buttonsperpart.js; node dateframe.js
node partsgroup.js; node seamsplit.js; node albumrowsblank.js
node numbertemplate.js; node titleeditor.js; node partnumber.js
```

Ожидаем `llm panel: 72 passed, 0 failed` и — по каждому из десяти соседей — ровно те же числа,
что и до правки:

| Харнесс | Ожидаем |
|---|---|
| `headingblock.js` | 110 passed, 0 failed |
| `notices.js` | 45 passed, 0 failed |
| `buttonsperpart.js` | 107 passed, 0 failed |
| `dateframe.js` | 40 passed, 0 failed |
| `partsgroup.js` | 75 passed, 0 failed |
| `seamsplit.js` | 38 passed, 4 failed (базовая линия) |
| `albumrowsblank.js` | 13 passed, 0 failed |
| `numbertemplate.js` | 134 passed, 0 failed |
| `titleeditor.js` | 93 passed, 0 failed |
| `partnumber.js` | 162 passed, 0 failed |

Красным здесь может встать только `partnumber.js`: он следит за парами нумерации по всему скрипту,
а панель — единственный новый писатель части. Ровно он и поймал ту ошибку, о которой сказано в
третьем абзаце этого задания, поэтому его числа здесь — не формальность.

- [ ] **Step 12: Мутанты**

Файл `app/runtime/pubjs/llmpanel-mutants.js` — та же форма, что у `dateframe-mutants.js`: список
[надпись, что сломать, чем сломать], прогон харнесса по копии вьюхи, «killed» или «SURVIVED».
Тридцать восемь мутантов лежат семью группами — адреса, часть и метки, три глагола, руки ответа,
ожидание очереди, ряд offer'а, диалог и причины гашения:

```javascript
// The red half of the panel of the AI: every fact llmpanel.js part E claims to read is broken
// one at a time in a copy of the view — an address asked twice, a mark that crosses as an
// object, a number put back into the text and forgotten in the heading, a wait that keeps
// asking after it is over, a handle the page lights again under a hand of the server. Each
// copy has to fail at least one check; a mutant that changes nothing means a check that tests
// nothing.
//
//   node llmpanel-mutants.js
//   node llmpanel-mutants.js view.mut.php llmpanel.js
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const HARNESS = process.argv[3] || path.join(__dirname, 'llmpanel.js');
const MUT_VIEW = path.join(__dirname, 'view.llmpanel.php');

const clean = fs.readFileSync(process.argv[2] || 'E:/SERVER/Work_awd/app/views/site/publications.php', 'utf8')
    .replace(/\r/g, '');

const mutants = [
    // The five addresses.
    ['the queue was asked at the address of the press',
        'getForJson(__LLM_STYLE_STATUS_URL, { llmJobId: jobId })', 'getForJson(__LLM_STYLE_URL, { llmJobId: jobId })'],
    ['the dialog was read as a write',
        'getForJson(__LLM_HISTORY_URL', 'postForJson(__LLM_HISTORY_URL'],
    ['the panel opened a third call of the network of its own',
        '            postForJson(__LLM_SPELLING_URL, {',
        '            fetch(__LLM_SPELLING_URL, { method: \'POST\' });\n            postForJson(__LLM_SPELLING_URL, {'],
    ['the name of one address was carried by two of them',
        'postForJson(__LLM_CHAT_URL, {', 'postForJson(__LLM_STYLE_URL, {'],

    // The part an answer belongs to, and the shape its marks cross in.
    ['the part was guessed by the number of parts instead of by the caret',
        '        function llmTarget() {\n            var bare = bareParts();\n            var index = splitTarget(bare.fields, bare.values);',
        "        function llmTarget() {\n            var bare = bareParts();\n            var index = bare.values.length - 1;"],
    ['the marks went out as a list, not as a string of JSON',
        "            postForJson(__LLM_SPELLING_URL, {\n                llmPartOrder: target.index,\n                llmText: target.text,\n                llmFormatting: JSON.stringify(target.formatting),",
        '            postForJson(__LLM_SPELLING_URL, {\n                llmPartOrder: target.index,\n                llmText: target.text,\n                llmFormatting: target.formatting,'],
    ['the marks came back pasted into the part as they stood',
        'JSON.parse(payload.formatting));', 'payload.formatting));'],

    // The three operations.
    ['the orthography offered its answer instead of writing it',
        'applyLlmAnswer(target.field, payload.text, JSON.parse(payload.formatting));',
        'llmOffer(target.index, payload.text, JSON.parse(payload.formatting), \'Проверено.\');'],
    ['a stylization was written into the part before the worker had thought it',
        'llmPollStyle(payload.jobId, target.index, payload.style || \'\');',
        'applyLlmAnswer(target.field, payload.text || \'\', []);'],
    ['a reply of the dialog was read by nobody but the flash',
        'llmOffer(target.index, payload.text, JSON.parse(payload.formatting), \'\');', 'mirrorUrl(payload);'],

    // The hands an answer goes through.
    ['the number of the part was taken out of the text and never put back',
        "            seedPart(field, text, Array.isArray(formatting) ? formatting : []);\n            applyPartNumbers();\n            applyHeadingNumbers();",
        '            seedPart(field, text, Array.isArray(formatting) ? formatting : []);'],
    ['the number went into the text and the heading was left nameless',
        "            seedPart(field, text, Array.isArray(formatting) ? formatting : []);\n            applyPartNumbers();\n            applyHeadingNumbers();",
        '            seedPart(field, text, Array.isArray(formatting) ? formatting : []);\n            applyPartNumbers();'],
    ['the preview was restated before the fields the form submits',
        '            writeFormattingFields();\n            update();',
        '            update();\n            writeFormattingFields();'],

    // The wait on the queue.
    ['the queue was asked in a loop the page never ends',
        '                llmPollTimer = setTimeout(function () {\n                    llmPollStyle(jobId, index, style);\n                }, LLM_POLL_MS);',
        '                setInterval(function () {\n                    llmPollStyle(jobId, index, style);\n                }, 3000);'],
    ['the page stopped waiting the moment it began asking',
        'llmWaitUntil = Date.now() + LLM_WAIT_MS;', 'llmWaitUntil = 0;'],
    ['one end of a wait left the next ask running',
        '                if (payload.status === \'failed\') {\n                    llmStopPolling();',
        '                if (payload.status === \'failed\') {'],
    ['the name of the style was read again when the wait was over',
        'function llmPollStyle(jobId, index, style) {', 'function llmPollStyle(jobId, index) {'],

    // The row of an offer.
    ['the offer showed its two words and kept the text to itself',
        '            answer.className = \'mb-0\';\n            answer.textContent = text;',
        '            answer.className = \'mb-0\';'],
    ['the two words of an offer were of a kind that submits the form',
        'take.type = \'button\';', 'take.type = \'submit\';'],
    ['the word that took the answer wore the green of the split handles',
        'take.className = \'btn btn-outline-info btn-sm\';', 'take.className = \'btn btn-outline-success btn-sm\';'],
    ['an answer that arrived after its part was cut wrote into the next one',
        'if (index >= fields.length) {', 'if (index > fields.length) {'],
    ['a row of the dialog was assembled from markup and not from words',
        'text.textContent = words;', 'text.innerHTML = words;'],
    ['who spoke was written as a word instead of a glyph',
        'mark.className = \'bi \' + (role === \'user\' ? \'bi-person\' : \'bi-cpu\');',
        'mark.textContent = role === \'user\' ? \'Вы\' : \'ИИ\';'],
    ['the panel drew the colour of the heading itself',
        'drop.textContent = \'Отклонить\';',
        'drop.style.borderColor = \'rgba(var(--bs-info-rgb), 1)\';\n            drop.textContent = \'Отклонить\';'],

    // The dialog.
    ['the sentence of the reader appeared only when the answer did',
        '            llmSay(\'Спрашиваю часть \' + (target.index + 1) + \'…\');\n            llmAddRow(\'user\', command);',
        '            llmSay(\'Спрашиваю часть \' + (target.index + 1) + \'…\');'],
    ['the dialog was read again on every move of the caret',
        'target === null || target.index === llmHistoryPart', 'target === null'],
    ['a part with no group behind it was asked for a dialog anyway',
        'if (!/^[a-f0-9]{32}$/.test(groupId)) {', 'if (groupId === \'\') {'],
    ['the panel invented a group in a shape of its own',
        "        function llmGroupId() {\n            if (!partGroupIdInput) {\n                return '';\n            }\n\n            if (partGroupIdInput.value === '') {\n                setPartGroupId(Array.from(crypto.getRandomValues(new Uint8Array(16)))",
        "        function llmGroupId() {\n            if (!partGroupIdInput) {\n                return '';\n            }\n\n            if (partGroupIdInput.value === '') {\n                setPartGroupId(Math.random().toString(36).slice(2)"],
    ['the dialog the reader refused was wiped off the screen with its handles',
        '            drop.addEventListener(\'click\', function () {\n                box.remove();',
        '            drop.addEventListener(\'click\', function () {\n                llmThreadsBox.textContent = \'\';\n                box.remove();'],

    // The reasons a handle goes dark.
    ['the page lit a handle the server had put out of use',
        'llmSpellingBtn.disabled = llmSpellingOff || empty || llmSpellingBusy;',
        'llmSpellingBtn.disabled = empty || llmSpellingBusy;'],
    ['the word of the server was lost under the word of the page',
        ': llmSpellingWord);', ': \'\');'],
    ['an empty part overwrote the reason of the storage',
        '(empty && !llmSpellingOff ? \'Часть пуста', '(empty ? \'Часть пуста'],
    ['the senders stayed open while the field of the command was empty',
        'llmSendBtn.disabled = llmChatOff || empty || llmChatBusy || !asked;',
        'llmSendBtn.disabled = llmChatOff || empty || llmChatBusy;'],
    ['Shift-Enter sent the command like Enter',
        'if (event.key === \'Enter\' && !event.shiftKey) {', 'if (event.key === \'Enter\') {'],
    ['a rebuild of the parts left the panel its old reason',
        '            updateMergeRows();\n            updateLlmControls();\n            updatePartSchedules();',
        '            updateMergeRows();\n            updatePartSchedules();'],
    ['a keystroke in a part left the panel its old reason',
        '            updateMergeRows();\n            updateLlmControls();\n            update();',
        '            updateMergeRows();\n            update();'],
    ['a wait that began left its handle looking idle',
        '            llmChatBusy = true;\n            updateLlmControls();', '            llmChatBusy = true;'],
    ['the panel was never set when the page was drawn',
        '        updateLlmControls();\n        llmHistory();\n', ''],
];

let surviving = 0;

mutants.forEach(([label, search, replace]) => {
    if (clean.indexOf(search) < 0) {
        console.log('STALE MUTANT  ' + label + ' — the text it meant to break is not there');
        surviving += 1;

        return;
    }
    // The panel sits at the end of the script, so a words that also stand in one of the
    // older hands are broken there first and the panel is never touched: the mutant then
    // proves nothing about the checks it was written against, even when it looks killed.
    if (clean.split(search).length - 1 > 1) {
        console.log('TWICE MUTANT  ' + label + ' — the text is in the page '
            + (clean.split(search).length - 1) + ' times, so only the first of them was broken');
        surviving += 1;

        return;
    }
    fs.writeFileSync(MUT_VIEW, clean.replace(search, replace));
    let out = '';
    try {
        out = execFileSync(process.execPath, [HARNESS, '', MUT_VIEW], { encoding: 'utf8' });
    } catch (error) {
        out = (error.stdout || '') + '\n' + (error.stderr || '');
    }
    const m = out.match(/(\d+) passed, (\d+) failed/);
    if (!m) {
        console.log('CRASHED MUTANT ' + label + '\n  ' + out.trim().split('\n')[0].slice(0, 140));
        surviving += 1;

        return;
    }
    const failed = Number(m[2]);
    const first = out.split('\n').filter((l) => l.indexOf('FAIL') === 0).slice(0, 2).join(' / ');
    if (failed === 0) {
        console.log('SURVIVED      ' + label);
        surviving += 1;
    } else {
        console.log('killed  ' + String(failed).padStart(2) + '  ' + label + '  →  ' + first.slice(0, 110));
    }
});

fs.rmSync(MUT_VIEW, { force: true });
console.log(surviving === 0
    ? 'all ' + mutants.length + ' mutants killed'
    : surviving + ' of ' + mutants.length + ' mutants survived');
process.exit(surviving === 0 ? 0 : 1);
```

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs && node llmpanel-mutants.js
```

Ожидаем `all 38 mutants killed`.

Драйвер отказывается ломать слово, которое встречается в файле дважды («TWICE MUTANT»): первый
раз такой мутант перекрашивал шов между частями вместо панели, оставался «killed» чужой проверкой
и ничего не доказывал про ту, ради которой был написан. Поэтому поиск каждого мутанта — строка
вместе с заголовком своей функции.

- [ ] **Step 13: Драйвер встаёт в общий прогон**

`app/runtime/pubjs/run-all.js` (вне git) — в список `drivers` после `'headingblock-mutants.js',`:

```javascript
    'llmpanel-mutants.js',
```

```sh
cd /e/SERVER/Work_awd/app/runtime/pubjs && node run-all.js; tail -3 run-all.txt
```

Ожидаем в отчёте строку `ok    llmpanel-mutants.js  all 38 mutants killed` и прежний вердикт
`PROBLEMS: 5` — базовая линия песочницы красна по праву (четыре харнесса с устаревшими якорями и
один драйвер мутантов) и от этой правки не растёт. Числа снимаются этим прогоном, а не из памяти.

- [ ] **Step 14: Закоммитить**

`app/runtime/` в индекс не попадает — в stage идёт одна вьюха:

```sh
git add app/views/site/publications.php
git status --short
git commit -F .git/COMMIT_MSG
```

```
feat(llm): скрипт панели идёт по пяти адресам и держит окно диалога за кареткой
```

Размер правки снимается до коммита и идёт в доку Task 18: `+519` строк во вьюхе (10 733 → 11 252),
и дельта строк длиннее 120 байт — `259 → 259`, то есть ноль: блоки переписаны так, что ни одна их
строка не перешагнула рамку, а перенос рядом с `llmSay` в очереди стоил того, чтобы разбить
строку. phpcs этого файла в Task 16 переснимает то и другое живым прогоном, а не этим числом.

---

### Task 16: Свод гейтов — что наработали задания 1–15

Задания 1–15 снимали гейты своего файла: юнит своего класса, `php -l` своей правки, харнесс своей
половины вьюхи. Здесь тот же набор проходит одним прогоном по всему, что подсистема заняла, — и
ничего нового не пишет. Выход этого задания не диф, а файл-захват `app/runtime/_tmp_gates.txt`:
из него Task 18 берёт числа в доку, потому что последний пункт Global Constraints запрещает доке
писать число, которого этот прогон не показывал.

**Почему свод нужен, когда каждый шаг уже был зелёным.** У гейтов этой подсистемы есть общая
граница, которую один шаг не видит: `container->singletons` в двух конфигах собирает весь граф
сразу, и промах в проводке Task 12 или в четырёх новых параметрах конструктора Task 13 зелёным
юнитом не ловится вовсе — там контроллер и сервис собирают руками, и о контейнере они ничего не
знают. Ровно поэтому шаг 5 гоняет всё дерево `tests/Unit/shared/Llm/`, а не файлы по одному, и
ровно поэтому шаг 7 просит `yii llm/process-jobs` у поднятого контейнера, а не у фикстуры.

**Почему захват — файл, а не память исполнителя.** Числа в этой доке относятся к трём разным
мерам: `codecept` считает тесты, `phpcs --report=source` — предупреждения `Generic.Files.LineLength`
по каждому файлу, `run-all.txt` — пары «passed, failed» по каждому харнессу. Сверяя доку,
их нельзя путать, и нельзя пересказывать со слов: все три снимаются в один файл, и Task 18
цитирует из него строки.

**Files:**
- Create: `app/runtime/_tmp_gates.txt` — захват трёх мер (вне git: `app/runtime/` не индексируется).
- Ничего не правится: ни один файл репозитория это задание не трогает и в stage не кладёт.
- Reference: `docs/superpowers/plans/2026-11-04-llm-integration.md:64-65` — пункт Global
  Constraints о пересъёмке чисел; `:671-673` — базовая линия unit-набора `372 tests, 1228 assertions`
  и правило «дельта равна числу новых тестов»; `:684-687` — как мерить `LineLength` и почему
  phpstan до вьюхи не касается.

**Interfaces:**
- Consumes: всё, что поставили задания 1–15, — три таблицы, семь кодов настроек, граф DI из двух
  конфигов, пять глаголов контроллера и разметку со скриптом вьюхи; ничего из этого задание не
  правит.
- Produces: только строки захвата `app/runtime/_tmp_gates.txt` — итог unit-дерева, итог всего
  набора, `PROBLEMS` phpstan, `--report=source` по view, `node --check` и пары `run-all.txt`.
  Из них Task 18 берёт числа доки; ни одного PHP- или JS-интерфейса это задание не заводит и не
  меняет.

- [ ] **Step 1: Поднять четыре сервиса**

`telegram` не поднимается ни при каком раскладе: его тики `*/5` реально пишут, правят и удаляют в
`@gsu_travels`.

```sh
cd /e/SERVER/Work_awd
mkdir -p app/runtime && : > app/runtime/_tmp_gates.txt
docker compose config >/dev/null && echo COMPOSE_OK | tee -a app/runtime/_tmp_gates.txt
docker compose up -d postgres app nginx llm
docker compose ps --format '{{.Service}} {{.State}} {{.Health}}' | tee -a app/runtime/_tmp_gates.txt
```

Ожидаем `COMPOSE_OK` и четыре строки `running`. `llm` — без healthcheck'а, у него только cron; если
он в состоянии `exited`, значит `entrypoint.sh` не дождался своего случая `case` и шаг 7 это
покажет. Docker Desktop поднимается руками заказчика; отказ «unable to start» — это заклинившая
WSL-VM, а не композиция: `wsl --shutdown`, перезапуск Docker Desktop, и только потом повтор.

- [ ] **Step 2: Три миграции на обеих базах**

```sh
cd /e/SERVER/Work_awd
docker compose exec -T -u appuser app php yii migrate --interactive=0 2>&1 | tee -a app/runtime/_tmp_gates.txt
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app php yii migrate --interactive=0 2>&1 | tee -a app/runtime/_tmp_gates.txt
docker compose exec -T postgres psql -U yii -d yii_dev -c '\d publications_setting' -c '\d llm_job' -c '\d llm_chat_message' 2>&1 | tee -a app/runtime/_tmp_gates.txt
```

Ожидаем во втором прогоне те же три имени, что и в первом (на `yii_test` их уже применили задания,
гонявшие пишущие юниты), и в `\d` — ровно те формы, что обещали миграции: у `publications_setting`
колонка `value` типа `text` без ограничения длины (Task 1); у `llm_job` четырнадцать колонок, из них
`status` и `kind` — `character varying(16)`, `part_group_id` — `varchar(32)`, `style_name` —
`varchar(190)`, `source_formatting` — `json not null`, а `result_text`, `result_formatting` и `error`
— с `null`, и один индекс `idx_llm_job_status` по одному лишь `status`; у `llm_chat_message` восемь
колонок и индекс `idx_llm_chat_message_part` по `(part_group_id, part_order, id)`. Индекс по
одному `status`, а не по паре со временем: дрейнер выбирает `WHERE status = 'queued'`, и ровно для
этого `part_order` в истории `notNull`, хотя 10-й раздел спека пишет `int null`.

- [ ] **Step 3: `php -l` по всем новым файлам**

```sh
docker compose exec -T app sh -lc '
  files=$(find migrations -name "m261104_*.php"; find shared/Llm commands/LlmController.php tests/Unit/shared/Llm \
               tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php \
               tests/Unit/SiteControllerLlmPanelTest.php -name "*.php")
  echo "$files" | wc -l
  echo "$files" | while read -r f; do php -l "$f"; done | grep -c "No syntax errors detected"
' 2>&1 | tee -a app/runtime/_tmp_gates.txt
```

Ожидаем `35` и `35`. Разложение, которым проверяется первое число: три миграции, шестнадцать
файлов `shared/Llm` (3 контракта + 5 DTO + 5 инфраструктуры + 3 сервиса; второго исключения в
`Infrastructure/` не видно из имён — `LlmRequestException` принёс Task 4, а
`StyleSampleUnavailableException` — Task 5), один глагол-воркер, тринадцать файлов дерева
`tests/Unit/shared/Llm` (9 в `Service/` — пять тестов и четыре фикстуры, 3 в `Infrastructure/`,
1 в `Dto/`), тест семи кодов настроек и тест панели. Два одинаковых числа означают, что ни один
файл не потерялся и ни один не сломан. `34` вместо `35` — не доехала одна фикстура дерева (их в
`Service/` четыре, а в карте файлов плана названы только две); `30` — из `shared/Llm` не доехала
папка `Dto` целиком; `35` и `34` — один файл не парсится, и его имя видно из того же прогона без
`grep -c`.

- [ ] **Step 4: PSR12 по новому коду и по двум вьюхам**

```sh
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 --report=source \
  shared/Llm commands/LlmController.php tests/Unit/shared/Llm \
  tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php \
  tests/Unit/SiteControllerLlmPanelTest.php 2>&1 | tee -a app/runtime/_tmp_gates.txt
docker compose exec -T app vendor/bin/phpcs --standard=PSR12 --report=source \
  views/site/publications.php views/site/settings.php controllers/SiteController.php \
  2>&1 | tee -a app/runtime/_tmp_gates.txt
```

Первый прогон обязан кончаться `A TOTAL OF 0 ERRORS`. Во втором ошибок тоже ноль, а предупреждения
`Generic.Files.LineLength` — это та самая мера, ради которой здесь стоит `--report=source`: строки
отчёта с именем файла и числом переносятся в доку Task 18 дословно. Вьюха публикаций держит 259
строк длиннее 120 байт после Task 15 (`252 → 259` дали задания 14 и 15), и эта цифра — мера
плана, а не phpcs; отчёт phpcs кладётся рядом, а не вместо.

- [ ] **Step 5: Unit — дерево Llm, два отдельных файла и весь набор**

```sh
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Llm/'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/shared/Settings/Service/PublicationSettingsServiceLlmTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit tests/Unit/SiteControllerLlmPanelTest.php'
docker compose exec -T -u appuser -e DB_DSN='pgsql:host=postgres;port=5432;dbname=yii_test' app sh -lc 'vendor/bin/codecept run Unit' 2>&1 | tee -a app/runtime/_tmp_gates.txt
```

Ожидаем `OK (87 tests, …)` на дерево (58 в `Service/`, 23 в `Infrastructure/`, 6 в `Dto/`), `OK
(13 tests, …)` и `OK (23 tests, …)` на два файла. Целый набор обязан дать `495 tests` — базовая
линия этого прогона плюс ровно те 123, что добавили задания:

| Файл | Задание | Тестов |
|---|---|---|
| `PublicationSettingsServiceLlmTest.php` | 2 и 3 | 13 |
| `LlmReplyTest.php` | 4 | 6 |
| `TextStyleSampleReaderTest.php` | 5 | 13 |
| `OpenAiCompatibleClientUrlTest.php` | 6 | 4 |
| `LlmTextServiceTest.php` | 7 | 13 |
| `LlmTextServiceStylizeTest.php` | 8 | 12 |
| `LlmTextServiceChatTest.php` | 9 | 11 |
| `LlmJobRepositoryTest.php` | 10 | 11 |
| `LlmChatRepositoryTest.php` | 11 | 8 |
| `LlmJobProcessorTest.php` | 12 | 9 |
| `SiteControllerLlmPanelTest.php` | 13 | 23 |
| Итого новых | | 123 |

Дельта больше 123 — значит кто-то дописал метод в чужой существующий тест и об этом в плане нет
ни строки; меньше — значит один из девяти файлов дерева с тестами не доехал (проверить `Step 3`,
где их число уже стоит).

`495` — число чистого дерева. Пока в рабочем дереве лежат незакоммиченные тесты чужой задачи, прогон
даёт их сверху: четыре файла форума (`ForumSectionScanServiceTest` 6, `ForumTagScanServiceTest` 4,
`CountryTagExtractorTest` 6, `ForumSectionPageParserTest` 4) добавляют ровно 20, и снятая на живом
дереве цифра `421` после Task 5 складывается из `372 + 29 + 20`. Сверять надо дельту, а не итог.

- [ ] **Step 6: PHPStan и песочница**

```sh
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G 2>&1 | tee -a app/runtime/_tmp_gates.txt
cd /e/SERVER/Work_awd/app/runtime/pubjs && node extract.js && node --check page.js
node llmpanel.js; node llmpanel-mutants.js
node run-all.js; tail -3 run-all.txt
```

phpstan — прежние `PROBLEMS: 5` прежних четырёх имён: новые классы `shared/Llm` в путях анализа,
`views/` в них нет. `node --check` молчит, `extract.js` печатает `page.js: 8541 lines` — это тело
новдока после Task 15. `llmpanel.js` даёт `72 passed, 0 failed`, его драйвер — `all 38 mutants
killed`. `run-all.txt` обязан кончаться прежним `PROBLEMS: 5`: базовая линия песочницы красна по
праву (`partsgroup.js`, `partmove.js` и три драйвера мутантов с устаревшими якорями), и ни одна
строка новой красноты не имеет права в ней появиться — ни от панели, ни от вьюхи настроек.

- [ ] **Step 7: Контейнер `llm` и глагол очереди**

```sh
docker compose exec -T llm sh -lc 'cat /tmp/cron-jobs'
docker compose exec -T llm sh -lc 'sh -n /usr/local/bin/entrypoint.sh && echo ENTRYPOINT_OK'
docker compose exec -T llm sh -lc 'php -r "echo gethostbyname(\"host.docker.internal\"), PHP_EOL;"'
docker compose exec -T -u appuser llm sh -lc 'php yii llm/process-jobs; echo EXIT:$?'
docker compose exec -T -u appuser app sh -lc 'php yii llm/process-jobs; echo EXIT:$?'
```

Ожидаем строку `* * * * * php /var/www/html/yii llm/process-jobs`, `ENTRYPOINT_OK`, IP хоста вместо
`host.docker.internal` (имя, вернувшее само себя, — `extra_hosts` не доехал) и две одинаковые строки
`Processed: 0, done: 0, failed: 0, reclaimed: 0, purged: 0` с `EXIT:0`. Обе статистики — ASCII, их
вывод в консоль читаем; очередь на этом шаге пуста, к прокси не обращается никто, и этого довольно,
чтобы признать проводку живой. Живой ход задачи через прокси — Task 17.

- [ ] **Step 8: Проводка пяти адресов со стороны веб-сервера**

```sh
curl -s -o /dev/null -w "POST llm-spelling -> %{http_code}\n" -X POST http://127.0.0.1:8081/llm-spelling
curl -s http://127.0.0.1:8081/llm-style-status > "$TEMP/llm-status.json"
curl -s http://127.0.0.1:8081/llm-chat-history > "$TEMP/llm-history.json"
```

Ожидаем `400` на POST (токена CSRF в `curl` нет) и `200` на оба GET. Тела читает `Read`, не консоль:
Yii кодирует JSON без `JSON_UNESCAPED_UNICODE`, и «Задача очереди не названа.» приходит экранированной
последовательностью. Оба GET обязаны сказать именно это, а не «За ИИ ничего не заведено.» — второе
означало бы `null` вместо сервиса в контейнере и было бы провалом шага 6 DI, который юнит ловить не
умеет.

- [ ] **Step 9: Проверить, что коммтить нечего**

```sh
git status --short
git log --oneline -15
cat app/runtime/_tmp_gates.txt
```

Ожидаем пустой вывод `git status --short` кроме двух незакоммиченных строк в `AWD.md` — их план не
трогает никогда. Пятнадцать строк `git log` — по коммиту на задание (Task 1…15), темы русские,
`type(scope):`. Если коммита не хватает, этот шаг его и не придумывает: свод гейтов чинит
констатацию, а не историю, и пропущенный коммит возвращается исполнителю как отказ, а не как
правка.

- [ ] **Step 10: Заглушить стек**

```sh
docker compose stop
```

`stop`, не `down`: тома с базой остаются, контейнер `telegram` не поднимался. Сам захват
`app/runtime/_tmp_gates.txt` не удаляется: Task 18 берёт из него строки, и он же вместе с
`_tmp_live.txt` снимается диском только последним шагом плана. Числа этого шага в доку пишутся из
`cat` выше, а не из памяти прогона.

---

### Task 17: Живой прогон подсистемы на настоящем прокси

Task 16 доказал, что граф собирается и глагол отвечает; он же оставил очередь пустой — к прокси за
всем сводом не обратилось ни единой строки кода. Это задание делает то, чего не может ни один
харнесс: одним текстом отвечает живому прокси, одним текстом его ответ кладёт в поле, а другой — в
таблицу. Оно ничего не правит в репозитории и кончается не коммитом, а захватом
`app/runtime/_tmp_live.txt`, из которого Task 18 берёт живые числа и честный список того, что
проверить не удалось.

**Почему прогон идёт до док, а не после.** Доку Task 18 нельзя написать по юнитам: 12-й раздел
спека обещает четыре вещи, которых в юнитах нет — орфография одной части, стилизация с
«Ватник в Америке» и принятием, команда диалога и жизнь меток до предпросмотра. Всё это проверяется
только настоящим ответом, а ответ зависит от адреса, ключа, выбранного стиля и поднятого
контейнера `llm`. После доки прогон уже не что другое, как пересъёмка того, что в доке написано, —
и ровно этого запрещает пункт Global Constraints о пересъёмке чисел.

**Почему ключ берётся словом заказчика и нигде не повторяется.** Ключ живёт одной строкой
`llmApiKey` и попадает в неё только из поля формы: ни `.env.example`, ни параметр команды, ни
`--values` сюда не ведут. Поэтому в задании нет ни одной команды, которая читает значение этого
кода — есть `octet_length` и признак `filled`, которые отвечают «пусто ли» и «какую длину
сохранило», и этого довольно для всех гейтов прогона. Тот же смысл у чтения очереди и истории:
колонки текста мерятся байтами, а не цитируются.

**Files:**
- Create: `app/runtime/_tmp_live.txt` — лист живого прогона (вне git: `app/runtime/` не
  индексируется).
- Ничего не правится: ни один файл репозитория это задание не трогает и в stage не кладёт.
- Правятся данные: `publications_setting` (семь кодов и `activeTextStyle`), `llm_job` и
  `llm_chat_message` в базе `yii_dev`. Строки `publications*` прогон не трогает ни в каком виде:
  ни одна из трёх операций не сохраняется сама, запись живёт в поле, пока человек не нажмёт свою
  обычную кнопку публикации.
- Reference: `docs/superpowers/specs/2026-11-04-llm-integration-design.md` — 12-й раздел (что
  обязан показать живой прогон), 7-й (три причины гашения и где их читать), 11-й (что говорит
  отказ), 15-й (измеренные времена `auto` и `fusion`).
- Reference: `docs/superpowers/plans/2026-11-04-llm-integration.md:40-41` — пункт Global
  Constraints о ключе; `:64-65` — о пересъёмке чисел; `:124` — о временных `_tmp_*` в конце плана.
- Reference: шаг 8 Task 16 — тот же свод curl-гейтов, из которого видно, что адреса живут и без
  браузера; здесь к ним добавляется единственный, который без токена не проходит.

**Interfaces:**
- Consumes: собранный граф Task 12, пять глаголов Task 13, панель Task 14-15 и поднятый контейнер
  `llm` — плюс настоящая пара «адрес + ключ», которую даёт только слово заказчика; ни один харнесс
  этой подсистемы ответа прокси не видел, поэтому здесь проверяется именно ответ, а не вызов.
- Produces: строки листа `app/runtime/_tmp_live.txt` — четыре обещанных 12-м разделом спека факта,
  измеренные времена `auto` и `fusion` и честный список того, что проверить не удалось. Репозиторий
  задание не трогает: ни одного интерфейса оно не заводит и не меняет, и в stage ничего не кладёт.

- [ ] **Step 1: Поднять стек и снять, что заведено до ключа**

```sh
cd /e/SERVER/Work_awd
mkdir -p app/runtime && : > app/runtime/_tmp_live.txt
docker compose up -d postgres app nginx llm
docker compose ps --format '{{.Service}} {{.State}} {{.Health}}' | tee -a app/runtime/_tmp_live.txt
docker compose exec -T llm sh -lc 'cat /tmp/cron-jobs' | tee -a app/runtime/_tmp_live.txt
docker compose exec -T postgres psql -U yii -d yii_dev -c \
  "select count(*) as rows_llm from publications_setting where code like 'llm%'" | tee -a app/runtime/_tmp_live.txt
```

Ожидаем четыре `running`, строку cron-шага `* * * * * php /var/www/html/yii llm/process-jobs` и
то, что `rows_llm` равно нулю: семь новых кодов заводятся в базе только после первого «Сохранить»
на странице настроек, а до него `value()` отдаёт дефолт из `SCHEMA`, и панель обязана видеть
именно его. В этом смысле ноль здесь не отсутствие ИИ, а его заводские значения; адрес прокси при
этом уже годный, а ключа нет — отсюда гашение следующего шага. `telegram` не поднимается ни при
каком раскладе.

- [ ] **Step 2: Гашение до ключа**

Открыть в браузере `http://localhost:8081/publications` на любой записи с двумя и более частями.
Снять в лист `disabled` и `title` у пяти контролов: `#publicationLlmSpelling`,
`#publicationLlmStyle`, `#publicationLlmCommand`, `#publicationLlmSendKey`, `#publicationLlmSend`.

Ожидаем у всех пяти `disabled === true` — гаснет и ряд двух ручек, и весь низ карточки диалога,
потому что `llmChatOff` в скрипте равен `llmSpellingOff`, а `llmCommandField.disabled` ставится от
него же. Про причины ровно одно: сервер пишет их только на двух ручках, и там читается
`Нет ключа и адреса прокси — ИИ не настроен на странице настроек`. У двух отправителей и у поля
команды `title` остаётся свой, серверный — «Спросить модель о той части, где последним стоял
редактор» и «Отправить команду модели»: скрипт подставляет свою фразу лишь в двух состояниях
(занято, пуста часть), а здесь ни одного из них нет. Это единственное наблюдение прогона, которое
получается только до ключа: пустой ввод в поле «Ключ LLM» означает «не менять», поэтому назад к
этому состоянию форма не вернёт.

- [ ] **Step 3: Пять строк настроек и ключ — словом заказчика**

Попросить у заказчика ключ прокси. Значение вводится руками в одно поле и в лист не пишется ни в
каком виде — ни целиком, ни хешем, ни длиной до сохранения. На `http://localhost:8081/settings`
заполнить пять строк блока LLM и сохранить:

| Строка | Что ставить |
|---|---|
| «Адрес LLM» | `http://host.docker.internal:31415/v1` |
| «Ключ LLM» | словом заказчика, в лист не попадает |
| «Модель LLM» | `auto` |
| «Размер образца стиля» | `40000` |
| «Таймаут запроса к LLM» | `90` |

Два промта не править вовсе: их тексты приходят дефолтами из `SCHEMA` и стоят в `textarea` до
первого сохранения, а `save()` требует присутствия в форме каждого кода схемы — значит после этого
«Сохранить» в базе окажутся все семь строк, и двумя нетронутыми в том числе. Этим же объясняется
число в следующем шаге. В лист записывается сам факт: «сохранено со страницы настроек, семь полей
формы». Адрес стоит вбить и в том случае, если он уже равен дефолту: проверка следующего шага —
длина сохранённой строки, а не память о том, что поле было заполнено.

- [ ] **Step 4: Проверить, что легло, — длинами, не значениями**

```sh
docker compose exec -T postgres psql -U yii -d yii_dev -c \
  "select code, octet_length(value) as bytes from publications_setting where code like 'llm%' order by code" \
  | tee -a app/runtime/_tmp_live.txt
docker compose exec -T postgres psql -U yii -d yii_dev -c \
  "select code, (value <> '') as filled from publications_setting where code = 'llmApiKey'" | tee -a app/runtime/_tmp_live.txt
```

Ожидаем семь строк с шестью известными длинами: `llmBaseUrl` ровно 36 байт
(`http://host.docker.internal:31415/v1`), `llmModel` ровно 4 (`auto`), `llmStyleSampleChars` ровно
5 (`40000`, код типа `int` хранится строкой), `llmTimeoutSeconds` ровно 2 (`90`),
`llmPromptSpelling` ровно 930 и `llmPromptStyle` ровно 1767 байт — эти два числа сняты с текстов
`DEFAULT_PROMPT_SPELLING` и `DEFAULT_PROMPT_STYLE` этого плана (`:449-456` и `:465-478`), и
расхождение с базой означает, что дефолт в схеме правили, не пересняв доку. У `llmApiKey`
ожидаем `filled = true` и ничего про его длину. `bytes` вместо значения — не осторожность ради
осторожности: `value` у ключа в консоли хостовой машины означает строку в истории bash, а историю
bash планом не почистить.

- [ ] **Step 5: Стиль для стилизации**

На `http://localhost:8081/settings` выбрать «Активный стиль текста» = `Ватник в Америке` и
сохранить. Это тот же `activeTextStyle` из ревизии каталога стилей: у него уже было имя, подсказка
и список вариантов, а с Task 8 он ещё и доходит до промта. В лист — что в базе держится имя этой
папки:

```sh
docker compose exec -T postgres psql -U yii -d yii_dev -c \
  "select value from publications_setting where code = 'activeTextStyle'" | tee -a app/runtime/_tmp_live.txt
```

Имя папки — не тайна, его печатает форма, и оно же попадёт в `title` ручки при отсутствующем
образце. Папок в каталоге две (`Ватник в Америке` и `g_jobbot`), обе с читабельным `result.json`;
выбирается первая, потому что её корпус 1 521 930 знаков, а потолок образца 40 000 — этого
довольно, чтобы манера снялась, и вызов остаётся в пределах 110 секунд.

- [ ] **Step 6: Орфография — один вызов, сразу в поле, метки живы**

Перезагрузить форму публикации, поставить фокус в редактор части 2, нажать «Проверить
орфографию». Снять в лист четыре вещи: текст `#publicationLlmStatus` до и после, `innerHTML` поля
части 2 до и после, число строк в `llm_job` после и время ожидания.

Ожидаем `Проверяю часть 2…` во время вызова и `Часть 2 проверена — правки внесены.` после; правки
видны глазами (вставлена пропущенная запятая, поправлено окончание), а метка, которая в части была
(жирное или курсивное слово), остаётся жирной или курсивной и в предпросмотре карточки, и в
`innerHTML` редактора; `count(*)` в `llm_job` при этом прежний — синхронная операция в очередь не
встаёт. Время прохода — секунды: 15-й раздел спека мерил `auto` на 17 000 знаках в 2,5 с, а здесь
текст части меньше тысячи. Отказ ловится теми же глазами, но не в статусе: ветка отказа вызывает
`llmSay('')` и `showFlash('error', …)`, так что строка под ручками пустеет, а над формой вырастает
красный `Ошибка: Орфография не проверена: ` — и поле остаётся ровно таким, каким было. Отдельно
подменять промт, чтобы поймать случай «метки не сходятся с текстом» (пятая ветка 11-го раздела
спека), здесь нечего: он есть юнитом Task 7, а живой прокси таких меток не ломает.

- [ ] **Step 7: Стилизация — очередь, «Принять», «Отклонить»**

Не перезагружая форму, снова фокус в часть 2 и «Стилизовать». Снять в лист, что отвечает ручка
(она сама говорит `Ставлю часть 2 в очередь…`), `id` ряда, который пришёл в ответе, и снимок
очереди:

```sh
docker compose exec -T postgres psql -U yii -d yii_dev -c \
  "select id, status, kind, left(style_name, 24) as style, octet_length(source_text) as src, octet_length(result_text) as out, error is not null as has_error from llm_job order by id" \
  | tee -a app/runtime/_tmp_live.txt
```

Первый снимок делается сразу после нажатия — ряд обязан быть в `queued`; дальше тот же запрос
раз в полминуты, пока ряд не станет `done`. Ожидаем один ряд `kind = 'style'`,
`style = 'Ватник в Америке'`, прошедший `queued → running → done` без `error`; `src` — байты
текста части, `out` — байты ответа, оба больше нуля. Шаг cron'а равен минуте, и ручка честно
говорит об этом своим `title` ещё до нажатия: «…задача встаёт в очередь, а очередь шагает раз в
минуту».

Рядом кладётся второй, независимый прогон глагола — он дрейнит ту же очередь руками из
контейнера `app`:

```sh
docker compose exec -T -u appuser app sh -lc 'php yii llm/process-jobs; echo EXIT:$?' \
  | tee -a app/runtime/_tmp_live.txt
docker compose exec -T postgres psql -U yii -d yii_dev -c \
  "select id, status, octet_length(result_text) as out from llm_job order by id" | tee -a app/runtime/_tmp_live.txt
```

Здесь возможны две честные строки, и обе зелёные: `Processed: 1, done: 1` — если руками дошёл
этот ряд, и `Processed: 0` со всеми нулями — если его раньше поднял cron. Это не двусмысленность
гейта, а его смысл: два дрейнера дерутся за один ряд ровно один раз, и решает advisory lock Task
12. В лист записывается та строка, что вышла, и тот снимок очереди, который её объясняет; провалом
считается только `EXIT` ненулевой, строка `Дрейнер уже работает в другом запуске — шаг пропущен.`
при пустой очереди или ряд, застрявший в `running`.

На странице это время видно по ряду диалога: как только `llm-style-status` приносит `done`,
под репликой модели вырастает пара ручек `Принять` и `Отклонить`, а первой строкой того же ряда
стоит `Стилизовано в манере «Ватник в Америке».` — в статусной строке этих слов нет, там после
ответа пусто. `Отклонить` жмём первой: `#publicationLlmStatus` говорит `Отклонено — часть осталась
как была.`, поле части 2 обязано остаться прежним, а ряд с двумя ручками исчезнуть. Потом вторично
«Стилизовать», ждём и жмём `Принять`: текст части становится ответом, метки пересаживаются
(проверка — `innerHTML` поля и предпросмотр), статус говорит `Часть 2 переписана.` В лист идут оба
состояния поля и секунды от нажатия до ряда с ручками; если за три минуты ряда нет, над формой
встанет красный `Ошибка: Очередь не ответила за три минуты.` — потолок опроса здесь, а не в
промте, и записывать в доку надо ровно эту фразу, а не ожидание.

- [ ] **Step 8: Диалог — Enter, Shift+Enter, «сократи текст» и его след в базе**

В `#publicationLlmCommand` набрать «сократи текст» и нажать Enter; затем во втором прогоне набрать
команду в две строки, поставив перенос по Shift+Enter. Снять в лист: что `#publicationLlmCommand`
держит после Shift+Enter (`value` содержит `\n`, и ни один из двух отправителей не отправляет),
статусную строку во время вызова, текст пользовательского ряда и текст реплики модели в
`#publicationLlmThreads`, и оба id из ответа:

```sh
docker compose exec -T postgres psql -U yii -d yii_dev -c \
  "select id, part_group_id, part_order, role, octet_length(content) as content, octet_length(replacement_text) as offered, replacement_formatting is not null as has_marks from llm_chat_message order by id" \
  | tee -a app/runtime/_tmp_live.txt
```

Ожидаем на каждую команду по паре строк (`user`, потом `assistant`), у `assistant` — `offered > 0`
и `has_marks = true` (ответ диалога всегда предлагает, чем заменить часть), а у `user` — `offered`
null: человек предлагает команду, а не текст. Ряд читателя печатает команду дословно, а ряд модели
подписан пустым словом — `llmChat()` зовёт `llmOffer(..., '')`, и подпись с названием стиля бывает
только у стилизации; это надо записать ровно так, а не выдумывать ряду заголовок.
`part_order` обязан равняться 1 для цели под фокусом в части 2 — колонка `notNull` ровно для того,
чтобы история одной части не валилась в историю другой. Потом `F5`: те же реплики возвращаются из
`llm-chat-history` и встают в `#publicationLlmThreads` в том же порядке — это и есть та ветка
10-го раздела, что держит историю сохранённой записи.

- [ ] **Step 9: Отказ адреса и возвращение на место**

Поменять «Адрес LLM» на `http://host.docker.internal:31416/v1`, сохранить, вернуться к публикации,
фокус в часть 2, «Проверить орфографию». Снять в лист, что ответила ручка и что стало с полем.

Ожидаем `disabled === false` у всех пяти контролов (адрес и ключ заведены — панель их не гасит) и
красный флеш, начинающийся с `Ошибка: Орфография не проверена: LLM не ответил: `, дальше — текст
cURL-ошибки (`Failed to connect…`); статусная строка при этом пуста, а поле части 2 обязано
остаться прежним до последнего символа. Всего тут три попытки — первая и две с задержками 0,5 и 1 с
(`delayMicroseconds * $attempt`), поэтому отказ наступает не мгновенно, но и не на таймауте.
После — вернуть `http://host.docker.internal:31415/v1` и сделать один контрольный вызов
орфографии: он обязан ответить `Часть 2 проверена — правки внесены.` Строка с пустым адресом в
форме не проверяется: `normalize()` на пустом тексте возвращает дефолт, поэтому обнулить адрес
сохранением нельзя — этот случай остаётся юниту Task 7.

- [ ] **Step 10: Что осталось непроверенным**

Дописать в тот же лист три строки честного списка, без которых дока Task 18 выглядела бы
пересказом:

- `Папки стиля «…» с образцом нет — стилизовать нечем` не снято живьём: у обеих папок каталога
  `result.json` читается, а получить эту причину можно только переименовав папку в примонтированном
  томе, что прогон данных не делает. Причина доказана юнитом Task 12 (ряд с нечитанным образцом
  становится `failed`) и признаком `readable`, который тестирован шагом 2 Task 13; фраза же живёт в
  вьюхе Task 15.
- Модель `fusion` (660 с на вызов) не тронута: она за пределами и 110 секунд `llmTimeoutSeconds`, и
  трёхминутного потолка опроса; 15-й раздел спека мерит её как отдельный риск фоновой очереди.
- Осиротевшая история несохранённой публикации не проверяется: у новой записи `part_group_id`
  придумывается при загрузке формы, и после `F5` она уже другая, а чисткой таких строк ревизия по
  14-му разделу спека не занимается.

- [ ] **Step 11: Сверить лист и заглушить стек**

```sh
cat app/runtime/_tmp_live.txt
git status --short
docker compose stop
```

Ожидаем у `git status --short` те же две незакоммиченные строки `AWD.md`, что и до прогона, — живой
ход ничего в репозиторий не добавил. В листе к этому шагу лежат: четыре сервиса и cron-строка,
шесть байтовых длин настроек и признак `filled` у ключа, пять пар `disabled`/`title` до ключа,
секунды и тексты статуса орфографии, снимки очереди и ровно одна строка `Processed:`, ряды истории
с их `role` и `has_marks`, отказ битого адреса, контрольный вызов после возврата адреса и три
строки непроверенного. `stop`, не `down`; `_tmp_gates.txt` и `_tmp_live.txt` на диске остаются — их
читает Task 18, а снимает диск последний шаг плана.

---

### Task 18: Доки и два коммита — README, PUBLICATIONS.md, CHANGELOG

Task 17 закрыл вопрос «работает ли». Этот закрывает вопрос «что о подсистеме написано»: три файла
дока перестают говорить о стиле как о выборе, который «только хранится», заводят раздел о трёх
действиях с текстом части и переводят в цифрах то, что снято живыми прогонами заданий 16 и 17.
Ни одного строчного кода здесь нет — только текст, две пересборки индекса и два коммита.

Числа этого задания берутся из двух захватов (`app/runtime/_tmp_gates.txt`,
`app/runtime/_tmp_live.txt`), а не из памяти прогона: глобальное правило плана переснимает всё,
что пишется в доку, и если живой прогон дал другое — доку пишется другое.

**Files:**
- Modify: `README.md` (девять правок: шаги 1–7 кладут семь в 684–803, шаг 19 правит строку 3 и
  раздел «Релизы»), `PUBLICATIONS.md` (восемь правок в 52–565: шаги 8–15),
  `CHANGELOG.md` (одна новая секция над :7 и переезд блока «Планируется»: шаги 17–18)
- Read: `app/runtime/_tmp_gates.txt` (Task 16), `app/runtime/_tmp_live.txt` (Task 17)
- Scratch (все в `app/runtime/`, вне git): `_tmp_unstage.js`, `_tmp_foreign_README_md.patch`,
  `_tmp_docs_num_before.txt`, `_tmp_docs_before.txt`, `_tmp_stage.txt`, `_tmp_probe.txt`,
  `_tmp_a_sha.txt`, `_tmp_a_num_after.txt`, `_tmp_b_num_after.txt`; темы коммитов —
  `.git/COMMIT_MSG_A` и `.git/COMMIT_MSG_B` (тоже вне индекса)

**Interfaces:**
- Consumes: семь кодов `SCHEMA` (Task 2, 3), две ветки вьюхи настроек (Task 2), `hint` у
  `activeTextStyle` (Task 9), пять адресов (Task 13), ряд двух ручек и карточка диалога (Task 14),
  скрипт панели (Task 15), контейнер `llm` с cron-строкой (Task 12), две таблицы (Task 10, 11),
  свод гейтов (Task 16) и живой лист (Task 17).
- Produces: два коммита — `docs(llm)` за README + PUBLICATIONS.md и `docs(release)` за CHANGELOG.md +
  README. Пуш и тег не здесь: они по отдельному слову заказчика.

- [ ] **Step 0: Предполёт — чужая строка README не уезжает в коммит**

Из четырёх общих файлов этого плана README — пятый грязный путь: чужая задача (скан разделов форума
и теги) правит в нём строку про таблицы форума, и по снимку 2026-11-06 это одна замена в одном
хуке на :153. Дерево не трогается: ни `git stash`, ни `git checkout` — чужая работа должна остаться
в worktree целой. В индекс по этому пути идёт пересборка, а не worktree.

Хелпер пересборки пишется один раз в Task 12 Step 0 и живёт в `app/runtime/`. Если его там нет
(задания 12–17 гонялись в другой сессии, диск чистили), он вынимается из того же шага этого же
плана — из ` ```js ` фенса между заголовком «Step 0: Предполёт — отделить свою правку от чужой» и
заголовком «Step 1»:

```sh
mkdir -p app/runtime
if [ ! -s app/runtime/_tmp_unstage.js ]; then
  awk '/^\*\*Step 0: Предполёт — отделить свою правку от чужой\*\*/,/^\*\*Step 1: Написать фикстуру очереди\*\*/' \
      docs/superpowers/plans/2026-11-04-llm-integration.md \
    | awk '/^```js$/{f=1;next} /^```$/{f=0} f' > app/runtime/_tmp_unstage.js
fi
wc -l app/runtime/_tmp_unstage.js
node --check app/runtime/_tmp_unstage.js && echo HELPER_OK
```

Ожидаем `75 app/runtime/_tmp_unstage.js` (столько строк в фенсе) и молчаливый `node --check` с
`HELPER_OK`. Пустой файл или `HELPER_FAIL` на следующих шагах — это стоп, а не «починить хелпер».

Снять мерку по своему пути и переснять её заново, если она разошлась с тем, что писал Task 12: чужая
задача живёт и правится параллельно.

```sh
git diff --numstat -- README.md | tee app/runtime/_tmp_docs_num_before.txt
git diff -U3 -- README.md > app/runtime/_tmp_foreign_README_md.patch
wc -l app/runtime/_tmp_foreign_README_md.patch
sha1sum README.md PUBLICATIONS.md CHANGELOG.md app/runtime/_tmp_foreign_README_md.patch \
    > app/runtime/_tmp_docs_before.txt
grep -c forum_tag README.md
git show HEAD:README.md | grep -c forum_tag
```

`_tmp_docs_num_before.txt` — чужая мера README; на 2026-11-06 в ней ровно одна строка `1  1
README.md`. `-U3` пишется явно: хелпер разбирает ровно этот формат. Последняя пара чисел обязана
дать `1` и `0` — маркер чужой строки живёт в worktree и отсутствует в HEAD; это и есть то, что не
должно уехать. Нули в обоих — чужая правка ушла из README, и тогда путь стейджится обычным
`git add`, а пересборка не нужна (сказать об этом вслух и не делать вид, что она сработала).

Доказать хелпер на самом себе до первой правки: переснятый чужой диф, обращённый назад, обязан
отдать blob, которым README пришёл в `HEAD`.

```sh
node app/runtime/_tmp_unstage.js README.md app/runtime/_tmp_foreign_README_md.patch app/runtime/_tmp_probe.txt
a=$(git hash-object -w --path README.md app/runtime/_tmp_probe.txt)
b=$(git rev-parse HEAD:README.md)
[ "$a" = "$b" ] && echo "MATCH README.md" || echo "MISMATCH README.md got=$a head=$b"
```

Ожидаем `MATCH README.md` (на 2026-11-06 — blob `4eec0cf1c464e0e8222cc716a5876da6320c08db`; сверяться
по числу, искать по содержимому). `MISMATCH` или `HELPER_FAIL` — снимок больше не описывает файл:
переснять этим же блоком выше и доказать снова. `--path` у `hash-object` обязателен: без него git не
применяет к собранному файлу свою нормализацию переводов строки, и SHA разойдётся на ровном месте.

С этого места и до конца плана README не стейджится `git add` никогда: только пересборкой, как в
шаге 16. `PUBLICATIONS.md` и `CHANGELOG.md` предполёт записал чистыми — они стейжатся обычно.

- [ ] **Step 1: README — семь строк в таблицу настроек**

Последняя строка таблицы — `| Активный стиль текста | \`activeTextStyle\` | пусто — «Стиль не
выбран» | подпапки каталога \`styles\` |` (:703). Сразу после неё вставить семь строк теми же
четырьмя колонками, в том же порядке, что `SCHEMA` (Task 2, 3), и с границами ровно из неё:

```
| Адрес LLM | `llmBaseUrl` | `http://host.docker.internal:31415/v1` | 10–255, адрес |
| Ключ LLM | `llmApiKey` | пусто — не менять | 0–255, в форму не возвращается |
| Модель LLM | `llmModel` | `auto` | 1–120 |
| Промт проверки орфографии | `llmPromptSpelling` | `DEFAULT_PROMPT_SPELLING` | 20–4000 |
| Промт стилизации | `llmPromptStyle` | `DEFAULT_PROMPT_STYLE` | 20–4000 |
| Размер образца стиля | `llmStyleSampleChars` | 40000 | 2000–200000 знаков |
| Таймаут запроса к LLM | `llmTimeoutSeconds` | 90 | 10–110 секунд |
```

Подписи в первой колонке — те же слова, что держит ключ `label` в `SCHEMA`; если на шаге вёрстки
окажется иначе, правится строка доки по коду, а не наоборот. Гейт один и он счётный:

```sh
awk 'NR>=684 && NR<=712' README.md | grep -c '^|'
```

Ожидаем `27` — было 20 (шапка, разделитель, 18 строк), стало 20 + 7.

Числа строк во всех шагах этого задания сняты до его правок и даны для сверки, а не для поиска:
шаги 1, 3, 5, 8–15 вставляют строки, и всё, что лежит ниже, едет сам. Искать надо по куску текста,
который шаг называет дословно.

- [ ] **Step 2: README — семь кодов в перечисляющее предложение**

Предложение под таблицей (:705 до правок этого задания; после семи строк шага 1 — :712) заканчивается словами `` `activeTextStyle` — списком
стилей, который читается с диска (см. ниже), остальные — тем, что следует из их подписей.`` Вставить
перед ``остальные`` оборот про новые коды, чтобы перечисление не врало:

```
`activeTextStyle` — списком стилей, который читается с диска (см. ниже), семь кодов `llm*` —
обращением к прокси заказчика (см. «Три действия с текстом части»), остальные — тем, что следует
из их подписей.
```

Гейт: `grep -c 'семь кодов `llm\*`' README.md` даёт `1`, а `grep -c 'остальные — тем, что следует'
README.md` по-прежнему `1` — хвост не удвоился.

- [ ] **Step 3: README — новый пункт про два типа поля**

После пункта, начинающегося с `- Числа читаются через \`all()\`, \`value()\` и \`intValue()\``
(:707 до правок этого задания; после шага 1 — :714), вставить один пункт — про то, что страница научилась принимать не только
числа и выбор:

```
- Два новых типа поля пришли в ту же цепочку `save()`: `text` держит строку в границах схемы и, если у кода стоит признак `secret`, пустой ввод означает «не менять» — значение, которое уже лежит в базе, сохраняется, а в разметку оно не попадает, потому что ветка `secret` печатает `value=""` и placeholder «Оставлено пустым = не менять»; `textarea` держит многострочный текст тех же границ и отдаётся полем `rows="8"`. Промты поэтому редактируются на странице, а формат ответа модели — нет: его блок дописывает код, и ни испортить, ни убрать его из поля нельзя.
```

Гейт: `grep -c 'Оставлено пустым = не менять' README.md` даёт `1`, `grep -c 'rows="8"' README.md`
даёт `1`.

- [ ] **Step 4: README — строка «только хранится» становится правдой**

Пункт :712 (до правок этого задания; шаги 1 и 3 сдвинули его ниже) начинается с `- Пока настройка только хранится: на текст публикаций она
не влияет, и подсказка под полем говорит об этом прямо.` — эти 114 знака (от `- ` до точки вместе с
пробелом перед `В форму`) меняются на то, что делает настройка теперь; весь хвост пункта (про `type ===
'style'`, `select2` и `TextStyleSelectAsset`) остаётся дословно тем, чем был:

```
- Выбор стиля читается: имя выбранной папки уходит в промт стилизации (`{style}`) и в заголовок разбора образца, а сам образец берётся с диска и едет в теле запроса — без выбранного стиля ручка «Стилизовать» гаснет, и подсказка под полем говорит об этом прямо.
```

Гейт: `grep -c 'Пока настройка только хранится' README.md` даёт `0`, а
`grep -c 'Инициализация в этом файле привязана к id поля' README.md` всё ещё `1` — хвост цел.

- [ ] **Step 5: README — раздел «Три действия с текстом части»**

Вставить новый `###`-раздел перед `### Стили и скрипты страниц` (:729 до правок этого задания) — уровень
тот же, что у соседских `### Страницы настроек` и `### Метки времени в списках`:

```
### Три действия с текстом части

Форма публикации обращается к OpenAI-совместимому прокси заказчика — адресу, ключу и модели, которые названы на странице настроек. Целью всех трёх действий остаётся часть, редактор которой последним получил фокус, — то же правило, которым говорит нижняя «Разделить». Ключ в этих предложениях отсутствует по той же причине, по какой его нет в `.env.example`: он живёт одной строкой `llmApiKey` и в доку не цитируется.

**Ручка «Проверить орфографию»** отвечает сразу: страница отправляет POST на `/llm-spelling`, ждёт одним HTTP-запросом и кладёт ответ прямо в поле части. Один вызов модели, ничего не предлагается, ничего не подтверждается.

**Ручка «Стилизовать»** короткой не бывает: текст части и выбранный стиль встают строкой в таблицу `llm_job`, а очередь дренирует отдельный контейнер `llm` с шагом в минуту — на одну часть приходится два вызова модели, образец стиля и ответ. Страница крутит опрос `/llm-style-status` (`LLM_POLL_MS` между попытками, `LLM_WAIT_MS` — потолок ожидания) и на готовом ответе показывает ряд с двумя словами, «Принять» и «Отклонить»; в поле ложится только «Принять».

**Блок «Диалог с ИИ»** — свободная команда той же части («перепиши этот абзац», «сократи текст», «убери упоминания»). Реплика уходит на `/llm-chat` вместе с текстом и метками цели, ответ приходит тем же запросом и, есть в нём что ставить вместо части, получает те же «Принять»/«Отклонить»; история лежит в `llm_chat_message`, на экране держится окном последних 50 реплик этой части, а модель видит из них последние 12 — оба числа константы кода, не настройки.

Ни одно из трёх действий не трогает канал: меняется поле формы, а публикует его по-прежнему человек кнопкой «Опубликовать». Гаснут ручки и отправка диалога (`disabled`, а не снятие; причина — в `title`) по пяти общим причинам: нет ключа или адреса, стиль не выбран, часть пуста, такая же работа уже идёт; у «Стилизовать» есть шестая, своя — папки выбранного стиля с образцом нет на диске. Ответ модели обязан быть строгим: блок контракта дописывается к промту кодом, метки ответа сверяются с его же текстом (`assertFitsText`), и при несовпадении в поле не пишется ни знака — полуразмеченный текст в форму не попадает ни при каком условии.
```

Вставляемый блок кладётся каждой своей строкой как один физический ряд: абзацы и пункты этого файла
не переносятся вручную, и мягкий перенос внутри абзаца оставил бы в доке разорванную строку. То же
касается блоков шагов 3 и 4 и строк таблицы шага 1.

Гейт: `grep -c '^### Три действия с текстом части$' README.md` даёт `1`, а `grep -n '^### Стили и
скрипты страниц$' README.md` называет строку ровно на две ниже последней строки вставленного блока —
пустая строка между блоками в этом файле обязана остаться.

- [ ] **Step 6: README — пять адресов в список маршрутов**

В предложении «Явные короткие пути в `app/config/web.php`: …» (:766 до правок этого задания) список кончается
на ``/login`, `/logout`, `/contact`, `/about`, `/captcha` ``. Вставить пять llm-адресов перед
`/login` — тем же плоским видом, что держат соседние ручки этой страницы:

```
`/llm-spelling`, `/llm-style`, `/llm-style-status`, `/llm-chat`, `/llm-chat-history`,
```

Гейт: `grep -Fc '`/llm-spelling`,' README.md` даёт `1` — адрес назван ровно один раз в этом
списке. `grep -o '/llm-[a-z-]*' README.md | wc -l` даёт не меньше `7`: пять адресов списка плюс два,
которые называет раздел шага 5; если шаг 5 назовёт их ещё раз, число станет больше — это не ошибка
гейта, он смотрит только что список маршрутов не удвоился.

- [ ] **Step 7: README — пункт «Направления развития» сужается**

Пункт `- Хранить примеры стилей и позволять выбрать конкретный пост-образец для преобразования
структуры/текста с помощью AI.` (:796 до правок этого задания) наполовину выполнен: образцы хранятся, стиль
выбирается папкой, текст преобразуется. Оставшаяся половина — образец уровня отдельного поста, а не
папки. Переписать строку так:

```
- Выбирать образцом стиля не папку, а конкретный пост: подсистема умеет нести в запросе блок образца, но где он берётся — решает человек, который кладёт `result.json` в папку.
```

Гейт: `grep -c 'Хранить примеры стилей' README.md` даёт `0`. Строка остаётся одним физическим
рядом, как и соседние пункты этого списка. `AWD.md` при этом не трогается ни
шагами плана, ни словом доки: его список живёт своей жизнью, и расхождение с ним — дело заказчика,
а не этого задания.

- [ ] **Step 8: PUBLICATIONS.md — модуль `app/shared/Llm`**

Вставить блок после последней строки «Telegram Module» (:52, `- \`PublishedDescriptionRepositoryInterface\`
- Repository for channel description history`) и до пустой строки перед `#### Application Layer`:

```
#### LLM Module (`app/shared/Llm`)
- `LlmClientInterface` (`Contract/LlmClientInterface.php`) - The one outbound call: `chat(array $messages, int $timeout): LlmReply`. Tests replace it with a fixture, so no unit test reaches the network.
- `LlmJobQueueInterface` (`Contract/LlmJobQueueInterface.php`) - The queue of background work: enqueue, claim one row, finish it, return what a killed worker left behind, sweep, and the lock that keeps two drainers apart.
- `LlmChatRepositoryInterface` (`Contract/LlmChatRepositoryInterface.php`) - `append()` and `latest()` of the conversation of one part.
- `LlmReply` / `LlmAnswer` / `StyleSampleData` / `LlmJobData` / `LlmChatMessageData` (`Dto/`) - The reply of the proxy with its stop reason and its usage; a text with its marks, ready to be put into a field; one style sample as the request sees it; one queue row while it is worked; one line of the conversation.
- `LlmRequestException` / `StyleSampleUnavailableException` (`Infrastructure/`) - Two refusals that name their reason: the transport would not answer, and the chosen style has no sample to read.
- `OpenAiCompatibleClient` (`Infrastructure/OpenAiCompatibleClient.php`) - cURL to `POST {base}/chat/completions`, modeled on `ForumHttpClient`: timeout, retries, a delay with jitter. The key is read as the request is built and goes nowhere else — not into a log, not into a message of an exception.
- `LlmJobRepository` / `LlmChatRepository` (`Infrastructure/`) - `llm_job` under a PostgreSQL advisory lock, and `llm_chat_message`.
- `TextStyleSampleReader` (`Service/TextStyleSampleReader.php`) - Reads `result.json` from the folder the settings page named and returns the sample block, cutting at a message boundary and never past `llmStyleSampleChars`.
- `LlmTextService` (`Service/LlmTextService.php`) - The three operations: the prompt taken from the settings, the reply contract appended by code, and the parse that refuses an answer whose marks do not fit its own text.
- `LlmJobProcessor` (`Service/LlmJobProcessor.php`) - One drain of the queue under the lock: claim a row, call the service twice for a stylization, write back what was offered.
```

Гейт: `grep -n '^#### LLM Module' PUBLICATIONS.md` даёт ровно одну строку, и она ниже :52;
`grep -c '^- \`Llm' PUBLICATIONS.md` не меньше `9`. Каждое имя обязано существовать в дереве —
проверить `ls app/shared/Llm/*/` и, если код называет метод иначе, править строку доки по коду.

- [ ] **Step 9: PUBLICATIONS.md — контроллер в Application Layer**

После строки `- \`TelegramController\` (\`app/commands/TelegramController.php\`) - Console commands
for Telegram operations` (:55 до правок этого задания; блок шага 8 лег над ним)
вставить один пункт:

```
- `LlmController` (`app/commands/LlmController.php`) - The console verb `llm/process-jobs`: drains the stylization queue once, and is what the `llm` container runs every minute.
```

Гейт: `grep -c 'llm/process-jobs' PUBLICATIONS.md` даёт не меньше `3` — этот пункт, строка консоли
шага 11 и cron-строка шага 12.

- [ ] **Step 10: PUBLICATIONS.md — две таблицы в Database Schema**

Раздел «Database Schema» кончает абзац про таблицы форума на :100 до правок этого задания («Both
tables are written only through `ForumPublicationMapGatewayInterface` …»). Вставить после него,
через пустую строку, ещё один абзац:

```
The LLM subsystem adds two tables of its own. `llm_job` (`m261104_000001_create_llm_job`) is the queue of background work: `status` and `kind` say what a row is doing and what it was asked to do, `part_group_id` and `part_order` say which part of which publication it belongs to, `style_name` keeps the style it was queued with, `source_text` and `source_formatting` keep the part as the handle saw it, `result_text` and `result_formatting` carry what the model offered, `error` carries why it did not, and `created_at`, `started_at` and `finished_at` say when. Its single index, `idx_llm_job_status`, is the access path of the claim. `llm_chat_message` (`m261104_000002_create_llm_chat_message`) is the conversation of the free dialog: `role` and `content` are the line as the model reads it, while `replacement_text` and `replacement_formatting` stand beside a reply that has something to offer — the text and the marks «Принять» would put into the field — and `idx_llm_chat_message_part` (`part_group_id`, `part_order`, `id`) is the window of that conversation. `part_order` is not null here although the design sketch allowed a line without a part: the dialog always aims at the part whose editor had the last focus, so a line that belongs to no part has no writer, and a nullable column would only add a null branch to the reader. Neither table is read by the Telegram side: a job that finished changes nothing but a field of the form.
```

Гейт: `grep -c 'm261104_000001_create_llm_job' PUBLICATIONS.md` и `grep -c 'm261104_000002_create_llm_chat_message'
PUBLICATIONS.md` дают по `1`. Имена колонок сверяются чтением двух файлов миграций
(`app/migrations/m261104_000001_create_llm_job.php`, `app/migrations/m261104_000002_create_llm_chat_message.php`),
а не памятью: чем миграция называет столбец, тем обязана называть его и строка доки, и расхождение
правится в доке в пользу кода.

- [ ] **Step 11: PUBLICATIONS.md — глагол в таблицу консольных команд**

Последняя строка таблицы — `| \`php yii telegram/clean-temp\` | …` (:296 до правок этого задания; искать по
тексту). Вставить после неё строку того же формата:

```
| `php yii llm/process-jobs` | Executes `LlmJobProcessor::drain()` - works the stylization queue under an advisory lock: claims one row at a time, hands back what a killed container left in `running`, and sweeps rows that finished days ago. |
```

И дополнить предложение :298 («These commands are typically scheduled by **supercronic** in the
`telegram` Docker Compose service…») одним предложением перед `They can also be run manually…`:

```
The queue command is not one of them: `llm/process-jobs` lives in `LlmController` (`app/commands/LlmController.php`), not in the file this section is named for, and supercronic schedules it in the `llm` service of `docker-compose.yml`.
```

Гейт: `grep -c '^| `php yii llm/process-jobs`' PUBLICATIONS.md` даёт `1`; `grep -c 'not one of them'
PUBLICATIONS.md` даёт `1`.

- [ ] **Step 12: PUBLICATIONS.md — cron-строка и абзац про контейнер `llm`**

В таблицу «Cron Scheduling» после строки `TELEGRAM_TEMP_CLEAN_CRON_SCHEDULE` (:309 до правок этого задания)
вставить строку того же формата:

```
| `LLM_JOB_CRON_SCHEDULE` | `* * * * *` | How often `llm/process-jobs` should drain the stylization queue. |
```

И вставить новый абзац после абзаца, начинающегося с «The four jobs run in the `telegram`
container, not in the `parser` one» (:313 с его хвостом; искать по началу):

```
The queue job runs in a container of its own. `CRON_ROLE=llm` is the role that writes the single `llm/process-jobs` line in `docker/php-cli/entrypoint.sh`, and `docker-compose.yml` builds the `llm` service from the same `php-cli` image, mounting `./app` for the code and `./styles` read-only at `/var/www/styles` for the sample a job has to carry, with `extra_hosts` mapping `host.docker.internal` to the host gateway because the proxy of the customer runs on the machine that hosts the containers. One minute is the shortest step supercronic gives, which is why the status row of the form says the queue steps once a minute rather than promising less. Two drainers cannot work the same row twice: `LlmJobRepository` claims it under a PostgreSQL advisory lock, so a tick that overlaps its own slow predecessor simply finds nothing to take.
```

Гейт: `grep -c 'LLM_JOB_CRON_SCHEDULE' PUBLICATIONS.md` даёт не меньше `2` (cron-таблица и `.env`
шага 13); `grep -c 'CRON_ROLE=llm' PUBLICATIONS.md` даёт `1`.

- [ ] **Step 13: PUBLICATIONS.md — исключение про секрет и строки `.env.example`**

Странный здесь сосед :341 («None of the three is displayed on a settings page: a page that lists
tunables is not where a secret belongs.») — новая подсистема кладёт ключ ровно на такую страницу.
Не отменять предложение, а дописать за ним, в той же строке, исключение:

```
The LLM key is the one secret that does have a field on the tunables page, and it is write-only: `llmApiKey` renders an empty input with a placeholder, the stored value never returns to the markup, and an empty submit keeps what the database already holds.
```

После блока `STYLES_PATH` (:345–349 до правок этого задания; fence со `#`-комментарием и строкой
`STYLES_PATH=`) вставить ещё один блок переменных — таких, которых в коде нет и которые нужны только
compose.
Внешняя рамка этого куска — четыре обратные кавычки, потому что вставляемый текст сам несёт fence
из трёх:

````
One more line ships in `.env.example`, and it is a schedule rather than a secret:

```
# llm — the queue of stylization jobs. One minute is the shortest step
# supercronic gives, and the form of the publication says so out loud.
LLM_JOB_CRON_SCHEDULE=* * * * *
```

The proxy key is deliberately absent from that file: it is one row, `llmApiKey`, written from the settings page, and a key copied into `.env.example` is a key that reaches a repository.
````

Гейт: `grep -c 'LLM_JOB_CRON_SCHEDULE=\*' PUBLICATIONS.md` даёт `1`; `grep -c 'write-only'
PUBLICATIONS.md` даёт `1`. Проверить, что вставленный fence закрыт ровно тройной линией и не
съел следующий заголовок: `grep -n '^## Usage from the Web UI'` всё ещё называет одну строку.

- [ ] **Step 14: PUBLICATIONS.md — «activeTextStyle» перестаёт быть только хранимым**

В абзаце «Usage from the Web UI» (:357 до правок этого задания) есть предложение, которое ревизия 1.1.1
написала правдой, а теперь оно врёт: `One entry of that table reaches neither list: «Активный стиль
текста» (\`activeTextStyle\`) takes its choices off the disk — the folders under \`styles\` — and is
read back by nothing but the form that stores it, since the value changes no publication text yet
(see the revision of 2026-11-03 under «Testing»).` Заменить его целиком на:

```
One entry takes its choices off the disk rather than from code: «Активный стиль текста» (`activeTextStyle`) lists the folders under `styles`, and since 2026-11-06 its value is read back by the stylization of a part — the folder name goes into the prompt of the author, the sample it holds travels with the request, and the handle goes out of use when no style is chosen (see the revision of this date under «Testing»). Seven `llm*` codes stand beside it: three of the connection, two of the prompts, and two numbers that bound the sample and the call.
```

И вставить после абзаца, начинающегося с «The publications page (`/publications`) and its form
posts to `publication-create`» (тот же :357 идёт выше него; искать по началу), абзац про панель:

```
The same form reaches the proxy of the customer. Two handles in a row of their own — `publicationLlmSpelling` and `publicationLlmStyle` — and the dialog card (`publicationLlmCommand`, `publicationLlmSend`) all act on the part whose editor last took focus, the target rule the split handle at the seam already uses. `/llm-spelling` and `/llm-chat` answer in the request that asked; `/llm-style` only queues a row in `llm_job` and the page reads `/llm-style-status` until the container `llm` has worked it. Whatever the model returns is parsed against its own text before anything is written: a reply whose entities do not fit is refused with its reason, and a half-marked text never reaches a field. An offer — the style result and any dialog reply that has one — is taken by `Принять` and dropped by `Отклонить`, and neither word posts the form: no control of the panel carries a `name`, and every button of it is `type="button"`, so the arrays `publicationText[]` and `publicationFormatting[]` stay exactly as the parts put them there.
```

Гейт: `grep -c 'reaches neither list' PUBLICATIONS.md` даёт `0`; `grep -c 'read back by nothing'
PUBLICATIONS.md` даёт `0`; `grep -c 'publicationLlmSpelling' PUBLICATIONS.md` даёт `1`.

- [ ] **Step 15: PUBLICATIONS.md — запись о ревизии в «Testing»**

После абзаца, который кончает рассказ о ревизии 1.1.1 («What this revision does not hold, stated
rather than smoothed. The value is stored and nothing else: …», :565 до правок этого задания; искать по
началу), и до абзаца «That runtime is the Docker Compose file at the repository root», вставить
запись этой ревизии. Каждое число ниже берётся из захвата, который тут же и снимается; ни одно
число не пишется по памяти и ни одно не подгоняется под ожидание плана.

```sh
cat app/runtime/_tmp_gates.txt | grep -nE 'No syntax errors|OK \([0-9]+ tests|A TOTAL OF|PROBLEMS|passed, |mutants|lines|HTTP/[0-9.]+ [0-9]{3}|Processed'
cat app/runtime/_tmp_live.txt | grep -nE 'llmBaseUrl|llmApiKey|llmModel|filled|Processed|Ошибка|http_code'
```

Текст записи (числа вписываются из двух захватов выше; в скобках указано, откуда именно):

```
On 2026-11-06 the ask came in one sentence: «Теперь добавляем возможность работы с ИИ» — three settings of the connection, two editable prompts, a spelling handle, a stylize handle and a free dialog that rewrites the part it points at.

The subsystem is a namespace of its own, `app/shared/Llm`, floored the way `Forum` and `Telegram` are floored — `Contract/`, `Dto/`, `Infrastructure/`, `Service/` — and four singletons of it are wired in both containers (`app/config/web.php`, `app/config/console.php`). Nothing reaches the proxy but through `OpenAiCompatibleClient`, and nothing in the unit suite reaches the network at all: the fixtures of `app/tests/Unit/shared/Llm/Service/` replace the client, the queue and the history, and the reader of the sample is handed a temporary folder it builds itself.

The three operations are not three shapes of one call. Spelling is synchronous, one call, and writes the field. The dialog is synchronous too, one call per line, and offers its replacement. Stylization is queued and takes two calls — the sample first, the text second — so the row goes to `llm_job` and the `llm` container works it on its minute, while the page polls a status verb with `LLM_POLL_MS` between attempts and gives up at `LLM_WAIT_MS`. The reply contract is appended to the prompt by code, which is why an edited prompt cannot break the parse, and the marks of an answer are checked against the answer itself: `assertFitsText` refusing is the only way a half-marked text would otherwise reach a field, and it does not.

The gates were re-taken inside `app` with `postgres`, `app`, `nginx` and `llm` raised; the `telegram` container was deliberately not started, because its `*/5` ticks write, edit and delete in the real channel. `<php-l-files>` files answer `No syntax errors detected` (`_tmp_gates.txt`, the `php -l` block). `vendor/bin/codecept run Unit` over the whole `shared/Llm` tree reports `OK (<llm-tests> tests, …)`, the two page-facing classes report `OK (<panel-tests> tests, …)` and `OK (<settings-tests> tests, …)`, and the suite as a whole ends at `<suite-tests> tests, <suite-assertions> assertions` — the baseline of the same run plus exactly the new ones. PSR12 over the new code and the two views answers `A TOTAL OF 0 ERRORS`, with the long-line warnings listed by the same report. PHPStan ends the run as it began it: `<problems>` problems of the same four names, since `views/` is not in the paths it analyses. The offline harness of the publication page extracts `<page-lines>` lines from the heredoc, `node --check` is silent, `llmpanel.js` reports `<panel-passed> passed, 0 failed`, its mutant driver reports `<mutants> all killed`, and `run-all.txt` still ends at `PROBLEMS: 5` — the red of the baseline, which belongs to `partsgroup.js`, `partmove.js` and three mutant drivers with stale anchors, and is not this revision's to fix.

What the live run said. `<base-bytes>` bytes for `llmBaseUrl`, `<model-bytes>` for `llmModel`, `<sample-bytes>` for `llmStyleSampleChars`, `<timeout-bytes>` for `llmTimeoutSeconds`, `<spelling-bytes>` for `llmPromptSpelling` and `<style-bytes>` for `llmPromptStyle` are stored as the settings page wrote them, and the key row reads `filled = true` without the value itself ever being printed. A part of `<part-chars>` characters checked its spelling in `<seconds>` seconds and the field changed by the diff the sheet captured. A stylization of «Ватник в Америке» queued one row, the container answered `Processed: <processed>`, status `done`, and `Принять` put the answer into the field with its marks surviving to the preview. A dialog line — «сократи текст» — came back offering a replacement, and `llm_chat_message` holds it with `role` and `has_marks = true`. One address broken on purpose produced the refusal the page shows (`Ошибка: …`), and the same run, with the address put back, answered again.

What this revision does not hold, stated rather than smoothed. Three things. The reason `Папки стиля «…» с образцом нет — стилизовать нечем` is not measured live: both folders of the catalog carry a readable `result.json`, and only renaming one inside the mounted volume would produce it — the unit that makes a row `failed` over an unreadable sample, and the `readable` flag of the panel state, are what stand in for the click. The router alias `fusion` is not tried: one call through it costs about 660 seconds, which is past both the 110-second ceiling of `llmTimeoutSeconds` and the 120-second budget of a web request, and section 15 of the spec keeps it as a risk of the queue that no setting bounds. And the orphaned history of a publication that was never saved is not tested: a new record invents its `part_group_id` when the form loads, a reload gives it another one, and cleaning such rows is out of this revision by section 14 of the spec.
```

Гейт шага: после вставки `grep -c '^On 2026-11-06 the ask came' PUBLICATIONS.md` даёт `1`, а
`grep -oE '<[a-z][a-z-]*>' PUBLICATIONS.md | wc -l` даёт `0` — ни один угловатый слот не остался
незаполненным; дефис внутри `[a-z-]` здесь обязателен — имена слотов его носят (`<llm-tests>`,
`<page-lines>`), и без него шаблон молчал бы ровно на тех слотах, которые и надо поймать. Пустой
захват или число, разошедшееся с ожиданием плана, вписывается как есть, и об расхождении говорится
вслух в отчёте — не подгоняется.

- [ ] **Step 16: Коммит A — README + PUBLICATIONS.md**

Индекс пуст, дерево стоит:

```sh
git diff --cached --name-only
sha1sum README.md PUBLICATIONS.md CHANGELOG.md > app/runtime/_tmp_a_sha.txt
```

`PUBLICATIONS.md` чист по предполёту — он стейджится обычно. README стейджится пересборкой:

```sh
git add PUBLICATIONS.md
node app/runtime/_tmp_unstage.js README.md app/runtime/_tmp_foreign_README_md.patch app/runtime/_tmp_stage.txt || exit 1
sha=$(git hash-object -w --path README.md app/runtime/_tmp_stage.txt)
mode=$(git ls-files -s -- README.md | cut -d' ' -f1)
git update-index --cacheinfo "$mode,$sha,README.md"
git diff --cached --name-only
```

Четыре гейта перед коммитом, все читаемые вслух:

```sh
git diff --cached --numstat
git diff --cached -- README.md | grep -c forum_tag
git diff --numstat -- README.md > app/runtime/_tmp_a_num_after.txt
diff app/runtime/_tmp_docs_num_before.txt app/runtime/_tmp_a_num_after.txt && echo FOREIGN_INTACT
git status --porcelain -- README.md PUBLICATIONS.md CHANGELOG.md
```

Первый — ровно два пути (`PUBLICATIONS.md` и `README.md`), без `CHANGELOG.md` и без чужих файлов.
Второй — `0`: чужого маркера в стейдже нет. Третий — unstaged-мера README обязана совпасть с
предполётной строка в строку: чужая работа целиком осталась в дереве. Четвёртый — ровно две строки
в таком виде:

```
MM README.md
M  PUBLICATIONS.md
```

Буква `M` в первой колонке у README — стейджанный кусок, который дала пересборка; буква `M` во
второй — чужая половина, которая осталась в worktree и в индекс не попала. `M ` у PUBLICATIONS без
второй буквы — файл целый, стейджанный и не тронутый после `git add`. У CHANGELOG строки нет
вовсе: его ещё не правили. Любая другая раскладка колонок означает, что пересборка съела чужие
строки или, наоборот, не довела свои, — и тогда коммит не делается.

Тема пишется в файл (кириллица через `-m` в этой консоли неразбираема):

```sh
printf '%s\n' 'docs(llm): README считает семь кодов и три действия с текстом, PUBLICATIONS описывает модуль, две таблицы и очередь' > .git/COMMIT_MSG_A
git commit -F .git/COMMIT_MSG_A
git show --stat --format=%s HEAD
git show HEAD:README.md | grep -c forum_tag
sha1sum -c app/runtime/_tmp_a_sha.txt && echo TREE_UNTOUCHED
```

Ожидаем: `--stat` называет те же два пути; последний grep — `0` (в HEAD чужой строки нет);
`TREE_UNTOUCHED` — ни README, ни PUBLICATIONS, ни CHANGELOG коммит не переписал на диске, чужая
правка и семь новых блоков стоят в worktree вместе.

- [ ] **Step 17: CHANGELOG — секция 2.0.0**

Над `## [1.1.1] - 2026-11-04` (:7) вставить новую секцию. Слово заказчика: мажор.

```
## [2.0.0] - 2026-11-06

Публикации получили доступ к ИИ: страница настроек держит семь новых величин, форма публикации — две ручки и блок свободного диалога, а стилизация текста встаёт в очередь, которую раз в минуту дренирует отдельный контейнер.

### Добавлено

- Три настройки подключения к OpenAI-совместимому прокси — адрес, ключ и модель; ключ хранится в базе, в форму не возвращается, и пустой ввод означает «не менять»
- Два редактируемых промта — проверки орфографии и стилизации; формат ответа дописывает код, поэтому текстом в поле его ни испортить, ни убрать
- Два числа ограничивают подсистему извне: размер образца стиля (2000–200000 знаков) и таймаут одного вызова (10–110 секунд)
- Ручка «Проверить орфографию»: правит часть на месте, ответ приходит тем же запросом, который страница и ждёт
- Ручка «Стилизовать»: задача встаёт в таблицу `llm_job`, её исполняет контейнер `llm` с шагом в минуту, страница опрашивает статус и предлагает «Принять»/«Отклонить»
- Блок «Диалог с ИИ»: свободная команда про ту часть, где последним стоял редактор («перепиши этот абзац», «сократи текст», «убери упоминания»), поле со стрелкой ↑ внутри и отдельная «Отправить», история держит окно последних реплик этой части
- Образец выбранного стиля читается с диска и едет в теле запроса блоком: `result.json` из папки, срезанный по границе сообщения до настроечного числа знаков
- Ни один полуразмеченный ответ в форму не попадает: метки проверяются против текста самого ответа, несовпадение означает отказ с причиной
- Контейнер `llm` со своим cron-шагом: очередь дренируется под advisory lock, устаревшие строки возвращаются в очередь, старые — вычищаются

### Изменено

- «Активный стиль текста» перестала быть выбором, который только хранится: имя папки уходит в промт стилизации и в причину гашения ручки, и подсказка под полем говорит об этом
- Страница настроек приняла два новых типа поля: строку с признаком секрета и многострочный текст промтов
```

Гейт: `grep -n '^## \[2\.0\.0\]' CHANGELOG.md` даёт `7`; `grep -c '^- ' CHANGELOG.md` даёт `196` —
снимок предполёта (`185`) плюс девять пунктов «Добавлено» и два «Изменено». После шага 18 то же
число станет `197` (переезд «Планируется»: шесть новых пунктов минус пять снятых), поэтому считать
его здесь по сумме, а не по памяти из прошлого прогона. Само это задание не пишет в доке ни одного
числа о размере самого себя: ни строк, ни пунктов — иначе цифра устарела бы в тот момент, когда её
вписали.

- [ ] **Step 18: CHANGELOG — «Планируется» переезжает в свежую секцию**

README (:776 до правок этого задания) держит правило: «разборы „на потом“ лежат в блоке `Планируется` внутри самой свежей
секции». Поэтому блок :16–22 секции 1.1.1 удаляется целиком, а под новой секцией `## [2.0.0]`
вставляется тот же список минус первый пункт (он этой ревизией и сделан) плюс два новых разбора из
14-го раздела спека:

```
### Планируется

- Ревизия чисел, которые до сих пор держит код, а не настройки: границы канала и кнопок-ссылок, возраст зачистки temp, редиректы и пределы пагинации парсера, три числа веб-сервера
- Две страницы настроек сворачиваются в одну «Настройки»: сегодня у каждой свой пункт меню, хотя правят они один и тот же портал
- Поле «Дата и время публикации» будет двигаться вперёд после «Опубликовать» или «Сохранить», а не стартовать тем же слотом, который человек выставил для первой части
- У строки альбома есть переезд картинки в соседнюю часть, но нет стрелок порядка внутри своего альбома: очередь задаётся только порядком заполненных строк
- Право обращаться к модели и лимит стоимости: сегодня ручку жмёт любой, кто видит форму, а usage из ответа сохраняется в ряд задачи и ни во что не превращается
- Проверка орфографии сразу всех частей одной кнопкой: сейчас цель — та часть, где последним стоял редактор
```

Гейт: `grep -c '^### Планируется' CHANGELOG.md` даёт `2` (новая секция и `0.2.0` — исторические
секции не переезжают), а `awk '/^## \[2\.0\.0\]/,/^## \[1\.1\.1\]/' CHANGELOG.md | grep -c '^###
Планируется'` даёт `1`. Тот же `grep -c '^- ' CHANGELOG.md` теперь даёт `197`. Внутри новой секции
блок обязан идти после «Изменено» и перед `## [1.1.1]`. Историческое intro секции 1.1.1 (:9 до
правок этого задания — «Выбор пока только хранится — на текст публикаций он не влияет») не
правится: это запись уже вышедшего релиза, и она была правдой в свой день.

```sh
awk 'NR>=1 && NR<=60' CHANGELOG.md | grep -n '^## \|^### '
```

Ожидаемый порядок заголовков: `## [2.0.0]`, `### Добавлено`, `### Изменено`, `### Планируется`,
`## [1.1.1]`, `## [1.1.0]`.

- [ ] **Step 19: README — строка о релизе и теге**

Тег не поставлен, workflow не зван, поэтому опубликованной версией файл продолжает называть
`v1.1.1`, а самой свежей секцией — уже `2.0.0`. Здесь README правится дважды: строка 3 (шапка файла
перечисляет то, что лежит в CHANGELOG) и середина предложения в «## Релизы» (:776 до правок этого
задания).

Строка 3 меняется целиком:

```
Последний релиз, перечисленный в [CHANGELOG.md](CHANGELOG.md): **2.0.0** (2026-11-06). На GitHub Releases опубликованы `v0.2.0`, `v1.0.0`, `v1.1.0` и `v1.1.1`; последний тег собран workflow и помечен Latest, а секция `2.0.0` своего тега ещё не имеет. Разборы, которые решено делать дальше, лежат в блоке «Планируется» под секцией этого релиза; версия пакета в `app/composer.json` отдельно не задана.
```

Второе — середина предложения :776, её заменить целиком:

```
Последняя опубликованная версия — `v1.1.1` (тег на `bb7207e`, релиз собран workflow и помечен Latest); самой свежей секцией в `CHANGELOG.md` лежит `2.0.0` (2026-11-06) — она написана, но своего тега ещё нет, и пока его нет, строка про опубликованную версию остаётся прежней.
```

Гейт: `grep -c 'секции выше неё нет' README.md` даёт `0` (снятая фраза не вернулась), `grep -c
'помечен Latest' README.md` даёт `2` (оба места называют `v1.1.1` последним тегом и ни одно не
называет так `2.0.0`), `sed -n '3p' README.md | grep -c '\*\*2\.0\.0\*\*'` даёт `1`, `grep -n '^##
\[2\.0\.0\]' CHANGELOG.md` по-прежнему `7`.

- [ ] **Step 20: Коммит B — CHANGELOG + README**

```sh
git diff --cached --name-only
git add CHANGELOG.md
node app/runtime/_tmp_unstage.js README.md app/runtime/_tmp_foreign_README_md.patch app/runtime/_tmp_stage.txt || exit 1
sha=$(git hash-object -w --path README.md app/runtime/_tmp_stage.txt)
mode=$(git ls-files -s -- README.md | cut -d' ' -f1)
git update-index --cacheinfo "$mode,$sha,README.md"
git diff --cached --numstat
git diff --cached -- README.md | grep -c forum_tag
git diff --cached -- CHANGELOG.md | grep -cE 'tag-parser|TagRepository|forum-section|CountryTagExtractor'
```

Опять пусто до `git add`; ровно два пути в стейдже; `0` и `0` на двух grep'ах. CHANGELOG предполёт
записал чистым, поэтому он стейджится `git add`, а README — по-прежнему пересборкой.

```sh
printf '%s\n' 'docs(release): секция 2.0.0 собрана в changelog, «Планируется» переехала в неё, README отпустил ожидание своего тега' > .git/COMMIT_MSG_B
git commit -F .git/COMMIT_MSG_B
git show --stat --format=%s HEAD
git show HEAD:README.md | grep -c forum_tag
git show HEAD:CHANGELOG.md | grep -n '^## \[2\.0\.0\]'
git diff --numstat -- README.md > app/runtime/_tmp_b_num_after.txt
diff app/runtime/_tmp_docs_num_before.txt app/runtime/_tmp_b_num_after.txt && echo FOREIGN_INTACT
```

Ожидаем: `--stat` — два пути; `0` — чужая строка не уехала ни в один из двух коммитов; `7` — номер
секции в HEAD; `FOREIGN_INTACT` — чужая мера README осталась ровно той же, что снята в шаге 0. Если
чужая задача успела дописать в README строку между шагом 0 и этим, `diff` покажет разницу, и это
правильный ответ: переснять снимок шагом 0 и повторить пересборку, а не подгонять файл под меру.

- [ ] **Step 21: Сверить доку, снять мусор и показать историю**

Последняя сверка по всему репозиторию: нигде в индексируемом доке не должно остаться слов о
подсистеме, которой нет, и о выборе, который только хранится.

```sh
grep -rn 'Пока настройка только хранится\|reaches neither list\|read back by nothing' README.md PUBLICATIONS.md | cat
grep -c '^## \[2\.0\.0\]' CHANGELOG.md
git ls-files --error-unmatch app/runtime/_tmp_gates.txt 2>&1 | tail -1
git ls-files --modified | grep -E '_tmp|JOURNAL' | cat
rm -f app/runtime/_tmp_*
git status --short
git log --oneline -4
```

Первый grep — пусто: обе фразы жили в доке и обе отменены этой ревизией (в CHANGELOG :9 историческая
строка секции 1.1.1 остаётся и сюда не попадает). Второй — `1`. Третий обязан сказать, что файл в
индексе не числится, — захват живёт в gitignored-папке; четвёртый — пусто. `rm -f app/runtime/_tmp_*`
снимает предполётные файлы, листы и пересборки: это последний шаг плана, и после него держать их на
диске не для чего. `git status --short` в конце — те же незакоммиченные чужие строки, что и до
задания, плюс ничего своего: две темы `docs(llm)` и `docs(release)` видны в `git log --oneline`.

Пуша нет. Тег нет. README не называет `v2.0.0` опубликованным — ни одна его строка не обещает
релиз, которого не делал workflow, и слово о теге у заказчика спрашивается отдельно.
