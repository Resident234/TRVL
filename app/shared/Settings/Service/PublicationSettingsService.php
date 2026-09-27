<?php

declare(strict_types=1);

namespace app\shared\Settings\Service;

use app\shared\Settings\Contract\PublicationSettingsRepositoryInterface;
use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Service\ChannelService;
use InvalidArgumentException;

/**
 * The tunables of the publications page and the settings that hold them.
 *
 * Every tunable is described once, in SCHEMA: the label the form shows, the
 * bounds the value must stay inside and the default the table is seeded
 * with. Reading always returns a usable value — an unknown number is clamped
 * and an unknown choice falls back to the default — so the callers never
 * have to check what they read. Writing is what refuses bad input: save()
 * checks every submitted value and throws before a single row is stored.
 *
 * The interval between the parts of one publication cannot be shorter than
 * the task that drains the queue, so that number comes from the environment
 * and is not editable here.
 */
final class PublicationSettingsService
{
    /**
     * Format the portal shows dates in, mapped to the tokens the date picker
     * uses for the same format.
     */
    public const DATE_FORMATS = [
        'd.m.Y H:i' => 'DD.MM.YYYY HH:mm',
        'd.m.Y H:i:s' => 'DD.MM.YYYY HH:mm:ss',
        'Y-m-d H:i' => 'YYYY-MM-DD HH:mm',
    ];

    private const ORDERS = [
        'newest' => 'Свежие сверху',
        'oldest' => 'Старые сверху',
    ];

    private const SCHEMA = [
        'publicationPageSize' => [
            'type' => 'int',
            'default' => '10',
            'min' => 1,
            'max' => 100,
            'unit' => 'записей',
            'label' => 'Записей в блоке',
            'hint' => 'Столько записей блок показывает сразу и добавляет порциями по мере прокрутки вниз.',
        ],
        'forumTopicsPageSize' => [
            'type' => 'int',
            'default' => '10',
            'min' => 1,
            'max' => 100,
            'unit' => 'тем',
            'label' => 'Тем форума в блоке',
            'hint' => 'Порция тем с ответами, которая читается из базы за один запрос.',
        ],
        'forumPostsPageSize' => [
            'type' => 'int',
            'default' => '10',
            'min' => 1,
            'max' => 200,
            'unit' => 'ответов',
            'label' => 'Ответов в теме',
            'hint' => 'Столько ответов темы блок показывает до того, как их надо досматривать прокруткой.',
        ],
        'scrollEdgePx' => [
            'type' => 'int',
            'default' => '80',
            'min' => 0,
            'max' => 400,
            'unit' => 'пикселей',
            'label' => 'Отклик прокрутки',
            'hint' => 'Как близко к низу списка надо дойти, чтобы блок запросил следующую порцию.',
        ],
        'scheduleMinuteStep' => [
            'type' => 'int',
            'default' => '10',
            'min' => 1,
            'max' => 30,
            'unit' => 'минут',
            'label' => 'Шаг минут в выборе времени',
            'hint' => 'Один и тот же шаг у списка минут и у округления предложенного слота публикации.',
        ],
        'scheduleHorizonHours' => [
            'type' => 'int',
            'default' => '32',
            'min' => 1,
            'max' => 168,
            'unit' => 'часов',
            'label' => 'Глубина выбора даты',
            'hint' => 'Сколько часов вперёд пускает выбор даты; дальше его стрелка останавливается.',
        ],
        'imageProbeTimeoutMs' => [
            'type' => 'int',
            'default' => '6000',
            'min' => 500,
            'max' => 30000,
            'unit' => 'мс',
            'label' => 'Ожидание проверки картинки',
            'hint' => 'Сколько времени форма ждёт ответ по ссылке на изображение. Ссылка, которая не ответила за этот срок, считается живой.',
        ],
        'imagesPreviewLimit' => [
            'type' => 'int',
            'default' => '10',
            'min' => 1,
            'max' => 20,
            'unit' => 'штук',
            'label' => 'Превью изображений в форме',
            'hint' => 'Сколько ссылок альбома форма показывает картинками, остальные — числом «+N».',
        ],
        'imageUploadMaxMb' => [
            'type' => 'int',
            'default' => '10',
            'min' => 1,
            'max' => 30,
            'unit' => 'МБ',
            'label' => 'Размер загружаемого файла',
            'hint' => 'Файл крупнее форма в альбом не берёт. Весь запрос с файлами веб-сервер принимает до 32 МБ, поэтому нескольких самых крупных файлов сразу запрос не вынесет.',
        ],
        'imageUploadLimit' => [
            'type' => 'int',
            'default' => '10',
            'min' => 1,
            'max' => 20,
            'unit' => 'файлов',
            'label' => 'Файлов в альбоме части',
            'hint' => 'Сколько файлов форма принимает в один альбом. Больше десяти из них в канал ушли бы вторым альбомом.',
        ],
        'imagesShownLimit' => [
            'type' => 'int',
            'default' => '4',
            'min' => 1,
            'max' => 20,
            'unit' => 'штук',
            'label' => 'Картинки в сохранённой записи',
            'hint' => 'Сколько изображений показывает элемент списка публикаций.',
        ],
        'splitSnapRangeChars' => [
            'type' => 'int',
            'default' => '600',
            'min' => 50,
            'max' => 2000,
            'unit' => 'символов',
            'label' => 'Поиск границы при ручной разбивке',
            'hint' => 'Как далеко от курсора форма ищет конец предложения или слова, чтобы разбить часть по границе, а не посередине.',
        ],
        'imagesCountFilterMax' => [
            'type' => 'int',
            'default' => '100',
            'min' => 1,
            'max' => 1000,
            'unit' => 'изображений',
            'label' => 'Верх фильтра «Кол-во изображений»',
            'hint' => 'Граница ползунка фильтра блока «Форум» и потолок, который принимает сервер.',
        ],
        'partsOffsetMinutes' => [
            'type' => 'int',
            'default' => '1',
            'min' => 1,
            'max' => 1440,
            'unit' => 'минут',
            'label' => 'Интервал между частями публикации',
            'hint' => 'Насколько позже предыдущей части уйдёт в канал каждая следующая часть разбитого текста.',
        ],
        'blockOrderDefault' => [
            'type' => 'order',
            'default' => 'newest',
            'label' => 'Порядок записей по умолчанию',
            'hint' => 'Как стоят блоки у того, кто ещё ничего не переключал. Переключатель блока в сессии важнее этой настройки.',
        ],
        'dateFormat' => [
            'type' => 'date',
            'default' => 'd.m.Y H:i',
            'label' => 'Формат даты и времени',
            'hint' => 'Один формат для дат на страницах и в выборе времени публикации.',
        ],
    ];

    /** @var array<string, string>|null the values read once, so one request does not query per tunable */
    private ?array $cached = null;

    public function __construct(
        private readonly PublicationSettingsRepositoryInterface $repository,
        private readonly string $publishCronSchedule = '',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::SCHEMA as $code => $definition) {
            $defaults[$code] = $definition['default'];
        }

        return $defaults;
    }

    /**
     * The whole description of the tunables, for the settings form.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return self::SCHEMA;
    }

    /**
     * Every tunable as it is stored now, with the defaults filling in the
     * codes the table does not hold yet.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $stored = $this->repository->all();
        $values = [];
        foreach (self::SCHEMA as $code => $definition) {
            $raw = isset($stored[$code]) ? $stored[$code] : $definition['default'];
            $values[$code] = $this->normalize($code, $raw);
        }

        return $this->cached = $values;
    }

    public function value(string $code): string
    {
        $values = $this->all();

        return $values[$code];
    }

    public function intValue(string $code): int
    {
        return (int)$this->value($code);
    }

    public function isOldestFirstByDefault(): bool
    {
        return $this->value('blockOrderDefault') === 'oldest';
    }

    public function jsDateFormat(): string
    {
        return self::DATE_FORMATS[$this->value('dateFormat')];
    }

    /**
     * The date formats offered to the form, labelled with a fixed moment
     * rendered in each of them.
     *
     * @return array<string, string>
     */
    public static function dateOptions(): array
    {
        $sample = new \DateTimeImmutable('2026-09-01 14:30:05');
        $options = [];
        foreach (array_keys(self::DATE_FORMATS) as $format) {
            $options[$format] = $sample->format($format);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function orderOptions(): array
    {
        return self::ORDERS;
    }

    /**
     * Checks the submitted form and stores every value it accepted.
     *
     * @param array<string, mixed> $submitted
     * @throws InvalidArgumentException on the first value that does not fit
     */
    public function save(array $submitted): void
    {
        $values = [];
        foreach (self::SCHEMA as $code => $definition) {
            $label = $definition['label'];
            if (!isset($submitted[$code])) {
                throw new InvalidArgumentException("Настройка «{$label}» не пришла в форме.");
            }

            $raw = trim((string)$submitted[$code]);

            if ($definition['type'] === 'int') {
                $min = $this->minimumOf($code);
                $max = (int)$definition['max'];
                if (!preg_match('/^\d+$/', $raw)) {
                    throw new InvalidArgumentException("«{$label}» надо задать целым числом.");
                }

                $number = (int)$raw;
                if ($number < $min || $number > $max) {
                    throw new InvalidArgumentException(
                        "«{$label}» принимает значения от {$min} до {$max}, передано {$number}.",
                    );
                }
            } else {
                $options = $definition['type'] === 'date'
                    ? self::dateOptions()
                    : self::ORDERS;
                if (!isset($options[$raw])) {
                    throw new InvalidArgumentException("Вариант настройки «{$label}» надо выбрать из списка.");
                }
            }

            $values[$code] = $raw;
        }

        $this->repository->saveMany($values, gmdate('Y-m-d H:i:s'));
        $this->cached = null;
    }

    /**
     * The limits Telegram itself imposes. The portal shows them and does not
     * let anyone edit them: a text longer than the API accepts is refused by
     * the API, not by this application.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function telegramLimits(): array
    {
        return [
            ['label' => 'Текст одного сообщения', 'value' => ChannelService::TEXT_MAX_LENGTH, 'unit' => 'символов'],
            ['label' => 'Подпись под изображением', 'value' => ChannelService::CAPTION_MAX_LENGTH, 'unit' => 'символов'],
            ['label' => 'Описание канала', 'value' => ChannelService::DESCRIPTION_MAX_LENGTH, 'unit' => 'символов'],
            ['label' => 'Изображений в одном альбоме', 'value' => ChannelService::ALBUM_MAX_PHOTOS, 'unit' => 'штук'],
            ['label' => 'Резерв под строку «Часть N»', 'value' => ChannelService::PARTS_NUMBERING_RESERVE, 'unit' => 'символов'],
            ['label' => 'Надпись кнопки-ссылки', 'value' => LinkButton::TEXT_MAX_LENGTH, 'unit' => 'байт'],
            ['label' => 'Адрес кнопки-ссылки', 'value' => LinkButton::URL_MAX_LENGTH, 'unit' => 'символов'],
        ];
    }

    /**
     * The schedule of the publishing task exactly as the environment gives it.
     * The portal shows it and never edits it: the cron of the container is not
     * something a page can change.
     */
    public function cronSchedule(): string
    {
        return trim($this->publishCronSchedule);
    }

    /**
     * How often the queue of due publications is drained, in minutes: the
     * floor for the interval between the parts of one publication. An
     * unrecognisable schedule gives no floor at all, so the portal does not
     * invent a limit it cannot see.
     */
    public function cronIntervalMinutes(): int
    {
        $schedule = trim($this->publishCronSchedule);
        if ($schedule === '') {
            return 1;
        }

        $fields = preg_split('/\s+/', $schedule);
        if ($fields === false || count($fields) !== 5) {
            return 1;
        }

        [$minutes, $hour] = $fields;

        if (preg_match('/^\*\/(\d{1,3})$/', $minutes, $matched) === 1) {
            return max(1, (int)$matched[1]);
        }

        if ($minutes === '*') {
            return 1;
        }

        if (preg_match('/^\d{1,2}(,\d{1,2})*$/', $minutes) === 1) {
            $points = array_map(static fn (string $point): int => (int)$point, explode(',', $minutes));
            sort($points);
            if (count($points) === 1) {
                return $hour === '*' ? 60 : 1440;
            }

            $gap = 60;
            foreach ($points as $index => $point) {
                $next = $points[($index + 1) % count($points)];
                $distance = $index + 1 === count($points) ? $next + 60 - $point : $next - $point;
                $gap = min($gap, $distance);
            }

            return max(1, $gap);
        }

        return 1;
    }

    public function minimumPartsOffsetMinutes(): int
    {
        return max(1, $this->cronIntervalMinutes());
    }

    /**
     * The lower bound of a tunable: for the gap between the parts it is the
     * cron interval, not the number written in the schema.
     */
    private function minimumOf(string $code): int
    {
        if ($code === 'partsOffsetMinutes') {
            return $this->minimumPartsOffsetMinutes();
        }

        return (int)self::SCHEMA[$code]['min'];
    }

    /**
     * Brings a stored value inside the bounds of its own definition.
     */
    private function normalize(string $code, string $raw): string
    {
        $definition = self::SCHEMA[$code];

        if ($definition['type'] === 'int') {
            $number = (int)$raw;
            $min = $this->minimumOf($code);
            $max = (int)$definition['max'];
            if ($number < $min) {
                return (string)$min;
            }

            if ($number > $max) {
                return (string)$max;
            }

            return (string)$number;
        }

        $options = $definition['type'] === 'date' ? self::dateOptions() : self::ORDERS;
        if (isset($options[$raw])) {
            return $raw;
        }

        return $definition['default'];
    }
}
