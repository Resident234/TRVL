<?php

declare(strict_types=1);

namespace app\shared\Telegram\Dto;

use InvalidArgumentException;

/**
 * The inline formatting of a channel message: a list of Telegram message
 * entities, each ['type' => ..., 'offset' => ..., 'length' => ...] plus 'url'
 * for a text_link. Offsets and lengths are counted in UTF-16 code units, which
 * is what the Telegram API asks for and what the browser already produces from
 * String#length.
 */
final readonly class MessageEntities
{
    /** Telegram renders these and nothing else out of what the editor offers. */
    public const TYPES = ['bold', 'italic', 'underline', 'strikethrough', 'code', 'text_link'];

    /**
     * @param array<int, array<string, mixed>> $entities
     */
    public function __construct(
        public array $entities = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param array<mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $entities = [];
        foreach ($raw as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type = isset($entity['type']) ? (string) $entity['type'] : '';
            if (!in_array($type, self::TYPES, true)) {
                continue;
            }
            $normalized = [
                'type' => $type,
                'offset' => isset($entity['offset']) ? (int) $entity['offset'] : 0,
                'length' => isset($entity['length']) ? (int) $entity['length'] : 0,
            ];
            if ($type === 'text_link') {
                $normalized['url'] = isset($entity['url']) ? (string) $entity['url'] : '';
            }
            $entities[] = $normalized;
        }

        return new self($entities);
    }

    /**
     * Reads the jsonb column value: a JSON array string, an already decoded
     * list, or null on rows written before the column existed.
     */
    public static function fromStored(mixed $raw): self
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? self::fromArray($raw) : self::empty();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->entities;
    }

    public function isEmpty(): bool
    {
        return $this->entities === [];
    }

    /**
     * The list as it lives in the jsonb column and in the hidden field of the
     * form, so the browser can pick a record's formatting up again.
     */
    public function toJson(): string
    {
        return (string)json_encode($this->entities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The entities of the substring [$start, $start + $length) counted in
     * UTF-16 code units, re-based on the beginning of that substring. Spans
     * that stick out of it are cut down, spans fully outside are dropped.
     */
    public function slice(int $start, ?int $length = null): self
    {
        $end = $length === null ? null : $start + $length;
        $entities = [];
        foreach ($this->entities as $entity) {
            $offset = (int) $entity['offset'];
            $spanEnd = $offset + (int) $entity['length'];
            $from = max($offset, $start);
            $to = $end === null ? $spanEnd : min($spanEnd, $end);
            if ($to - $from < 1) {
                continue;
            }
            $clipped = [
                'type' => (string) $entity['type'],
                'offset' => $from - $start,
                'length' => $to - $from,
            ];
            if (isset($entity['url'])) {
                $clipped['url'] = (string) $entity['url'];
            }
            $entities[] = $clipped;
        }

        return new self($entities);
    }

    /**
     * Spans may lie over one another — a bold run inside an italic one is how the
     * editor nests the formats it offers — but the list has to keep the order of
     * the offsets, which is what the channel and the cut of a caption both walk.
     *
     * @throws InvalidArgumentException when an entity does not describe a real
     *                                  span of $text, or when the list is out of
     *                                  order
     */
    public function assertFitsText(string $text): void
    {
        $units = self::utf16Length($text);
        $previousOffset = 0;
        foreach ($this->entities as $index => $entity) {
            $offset = (int) $entity['offset'];
            $length = (int) $entity['length'];
            if ($offset < $previousOffset) {
                throw new InvalidArgumentException(
                    sprintf('Сущность %d идёт раньше предыдущей.', $index),
                );
            }
            if ($length < 1) {
                throw new InvalidArgumentException(
                    sprintf('Сущность %d пуста.', $index),
                );
            }
            if ($offset + $length > $units) {
                throw new InvalidArgumentException(
                    sprintf('Сущность %d выходит за границы текста.', $index),
                );
            }
            if ($entity['type'] === 'text_link' && !self::isHttpUrl((string) $entity['url'])) {
                throw new InvalidArgumentException(
                    sprintf('Ссылке сущности %d не хватает http(s)-адреса.', $index),
                );
            }
            $previousOffset = $offset;
        }
    }

    public static function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $bytes = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return (int) (strlen($bytes) / 2);
    }

    private static function isHttpUrl(string $url): bool
    {
        return $url !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'));
    }
}
