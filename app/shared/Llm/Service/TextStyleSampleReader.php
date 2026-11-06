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

    public function has(string $styleName): bool
    {
        return is_file($this->sampleFile($styleName));
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
                'В образце папки «%s» всего %d знаков — разобрать по ним стиль нельзя.',
                $styleName,
                $chars,
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
