<?php

declare(strict_types=1);

namespace app\shared\Telegram\Dto;

use InvalidArgumentException;

/**
 * The keyboard of a channel message: the link buttons Telegram draws under a
 * text, a photo or an album, in the order the form holds them. Every button
 * takes a row of its own, the way the one button of a message used to. An empty
 * list is a message that goes out without a keyboard.
 */
final readonly class LinkButtons
{
    /**
     * The bound of the portal, not of the API: a client draws a row of an inline
     * keyboard across the whole width of the message, so past this point the
     * buttons of a publication stop being an accent and become a menu.
     */
    public const MAX_BUTTONS = 10;

    /**
     * @param LinkButton[] $buttons
     */
    public function __construct(
        public array $buttons = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * The rows of the form: the labels and the addresses of one part in the order
     * the rows stand in, so the two lists pair up by position. A row that holds
     * nothing in either field is not a button and drops out; a half row stays, so
     * that assertValid() can be the one to refuse it.
     *
     * @param array<mixed> $texts
     * @param array<mixed> $urls
     */
    public static function fromPairs(array $texts, array $urls): self
    {
        $texts = array_values($texts);
        $urls = array_values($urls);
        $buttons = [];

        for ($index = 0, $rows = max(count($texts), count($urls)); $index < $rows; $index++) {
            $button = new LinkButton(
                isset($texts[$index]) ? trim((string) $texts[$index]) : '',
                isset($urls[$index]) ? trim((string) $urls[$index]) : '',
            );

            if (!$button->isEmpty()) {
                $buttons[] = $button;
            }
        }

        return new self($buttons);
    }

    /**
     * @param array<mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $buttons = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $button = LinkButton::fromArray($item);
            if (!$button->isEmpty()) {
                $buttons[] = $button;
            }
        }

        return new self($buttons);
    }

    /**
     * Reads the jsonb column value: a JSON array of pairs, the single JSON object
     * a row written before the list existed holds, the empty object of a record
     * that carries no button, or null on a row written before the column existed.
     */
    public static function fromStored(mixed $raw): self
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }

        if (!is_array($raw)) {
            return self::empty();
        }

        if (array_key_exists('text', $raw) || array_key_exists('url', $raw)) {
            return self::fromArray([$raw]);
        }

        return self::fromArray($raw);
    }

    /**
     * @return LinkButton[]
     */
    public function all(): array
    {
        return $this->buttons;
    }

    public function count(): int
    {
        return count($this->buttons);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (LinkButton $button): array => $button->toArray(),
            $this->buttons,
        );
    }

    public function isEmpty(): bool
    {
        return $this->buttons === [];
    }

    /**
     * The list as it lives in the hidden field of the form and in the jsonb
     * column, so a record opened for editing picks its buttons up again.
     */
    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws InvalidArgumentException when the message carries more buttons than
     *                                  the portal gives it, or when one of them
     *                                  is half-filled or malformed
     */
    public function assertValid(): void
    {
        $count = $this->count();
        if ($count > self::MAX_BUTTONS) {
            throw new InvalidArgumentException(
                sprintf('Под одним сообщением не больше %d кнопок-ссылок, а их %d.', self::MAX_BUTTONS, $count),
            );
        }

        foreach ($this->buttons as $button) {
            $button->assertValid();
        }
    }
}
