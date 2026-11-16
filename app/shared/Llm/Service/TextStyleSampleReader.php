<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Dto\StyleSampleData;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use Generator;
use Random\Randomizer;

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
 * A small export is read as a whole and sampled from the newest message
 * backwards, as it always was. A large one — an export of a busy channel runs
 * into hundreds of megabytes — cannot be decoded in one piece without killing
 * the worker, so the reader indexes the byte offsets of its messages once,
 * shuffles them and decodes only the random few that fit the character budget.
 * Random draws spread over the whole file instead of repeating its tail, and
 * the offset index is cached beside the runtime files under the file's size
 * and modification time.
 *
 * The style name arrives from the settings, where `save()` has already matched
 * it against the catalog, so it is a folder that exists rather than a path a
 * caller invented.
 */
final class TextStyleSampleReader
{
    public const MIN_SAMPLE_CHARS = 200;

    /** Below this size the old whole-file decode is cheaper than an index. */
    private const STREAM_THRESHOLD_BYTES = 32 * 1024 * 1024;

    /** How many random messages may be opened to fill one sample block. */
    private const MAX_SAMPLE_MESSAGES = 1000;

    /** A guard against an unterminated object in a damaged export. */
    private const MAX_MESSAGE_LINES = 100000;

    public function __construct(
        private readonly string $path,
        private readonly ?Randomizer $randomizer = null,
    ) {
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

        $size = filesize($file);
        if ($size === false || $size === 0) {
            throw new StyleSampleUnavailableException(sprintf(
                'Файл образца папки «%s» не читается.',
                $styleName,
            ));
        }

        $random = $size >= self::STREAM_THRESHOLD_BYTES;
        if ($random) {
            $messages = $this->randomMessages($file, $ceiling);
        } else {
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

            $messages = array_reverse($decoded['messages'], true);
        }

        $taken = [];
        $chars = 0;
        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['text'])) {
                continue;
            }

            $text = $this->textOf($message['text']);
            if ($text === '') {
                continue;
            }

            $length = mb_strlen($text);
            if ($chars + $length > $ceiling) {
                // A random draw can simply try another message; the newest
                // first walk of a small export still stops on the boundary.
                if ($random) {
                    continue;
                }
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

        return new StyleSampleData($styleName, $chars, $this->block($styleName, $chars, $taken, $random));
    }

    /**
     * The messages of a large export, in a random order. Only their byte
     * offsets are held in memory; each message is decoded when its turn comes.
     *
     * @return Generator<int, array{text: string}>
     */
    private function randomMessages(string $file, int $ceiling): Generator
    {
        $offsets = $this->messageOffsets($file);
        if ($offsets === []) {
            return;
        }

        $randomizer = $this->randomizer ?? new Randomizer();
        $shuffled = $randomizer->shuffleArray($offsets);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            $yielded = 0;
            foreach ($shuffled as $offset) {
                if ($yielded >= self::MAX_SAMPLE_MESSAGES) {
                    return;
                }

                $message = $this->messageAt($handle, (int)$offset);
                if ($message === null || !isset($message['text'])) {
                    continue;
                }

                $text = $this->textOf($message['text']);
                if ($text === '' || mb_strlen($text) > $ceiling) {
                    continue;
                }

                yield ['text' => $text];
                $yielded++;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The byte offset of every message of a pretty-printed Telegram export.
     * Top-level messages open with a two-space `{`; the nested objects of a
     * message are indented deeper, so the line is an unambiguous boundary.
     * The index is written next to the runtime cache, keyed by the file's size
     * and modification time, so a second job does not walk 400 MB again.
     *
     * @return int[]
     */
    private function messageOffsets(string $file): array
    {
        $size = (int)filesize($file);
        $mtime = (int)filemtime($file);
        $cache = sys_get_temp_dir() . '/llm-style-offsets-' . md5($file) . '-' . $size . '-' . $mtime . '.json';
        if (is_file($cache)) {
            $stored = json_decode((string)file_get_contents($cache), true);
            if (is_array($stored)) {
                return array_values(array_map('intval', $stored));
            }
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $offsets = [];
        try {
            while (($line = fgets($handle)) !== false) {
                if (rtrim($line, "\r\n") === '  {') {
                    $offsets[] = (int)ftell($handle) - strlen($line);
                }
            }
        } finally {
            fclose($handle);
        }

        file_put_contents($cache, (string)json_encode($offsets), LOCK_EX);

        return $offsets;
    }

    /**
     * One message object, decoded on its own instead of decoding the export.
     * A generator only borrows the handle, so it seeks before every read.
     *
     * @return array<string, mixed>|null
     */
    private function messageAt($handle, int $offset): ?array
    {
        if (fseek($handle, $offset) !== 0) {
            return null;
        }

        $raw = '';
        $lines = 0;
        $closed = false;
        while (($line = fgets($handle)) !== false && $lines < self::MAX_MESSAGE_LINES) {
            $raw .= $line;
            $lines++;
            $end = rtrim($line, "\r\n");
            if ($end === '  }' || $end === '  },') {
                $closed = true;
                break;
            }
        }

        if (!$closed) {
            return null;
        }

        $decoded = json_decode(rtrim($raw, " \r\n\t,"), true);

        return is_array($decoded) ? $decoded : null;
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
     * @param string[] $taken
     */
    private function block(string $styleName, int $chars, array $taken, bool $random): string
    {
        $order = $random
            ? 'выбранные случайно из всего образца'
            : 'от самых свежих к самым старым';

        return "Образец стиля «{$styleName}» — {$chars} знаков.\n"
            . "Ниже — тексты этого автора, {$order}, через разделитель «---».\n\n"
            . implode("\n\n---\n\n", $taken)
            . "\n\n--- конец образца ---";
    }

    private function sampleFile(string $styleName): string
    {
        return rtrim($this->path, '/\\') . '/' . $styleName . '/result.json';
    }
}
