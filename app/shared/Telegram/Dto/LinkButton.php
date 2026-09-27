<?php

declare(strict_types=1);

namespace app\shared\Telegram\Dto;

use InvalidArgumentException;

/**
 * The link button of a channel message: the one button with an address Telegram
 * draws under a text, a photo or an album. A pair of a label and a URL exactly
 * as the Bot API takes them; a pair of two empty strings is a message that goes
 * out without a button.
 */
final readonly class LinkButton
{
    /**
     * Telegram counts a button label in bytes, not in characters, so a Cyrillic
     * label of 30 letters is already at the edge.
     */
    public const TEXT_MAX_LENGTH = 64;
    public const URL_MAX_LENGTH = 2048;

    public function __construct(
        public string $text = '',
        public string $url = '',
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
        $text = isset($raw['text']) ? trim((string) $raw['text']) : '';
        $url = isset($raw['url']) ? trim((string) $raw['url']) : '';

        return new self($text, $url);
    }

    /**
     * Reads the jsonb column value: a JSON object string, an already decoded
     * pair, the empty object of a record that carries no button, or null on a
     * row written before the column existed.
     */
    public static function fromStored(mixed $raw): self
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? self::fromArray($raw) : self::empty();
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['text' => $this->text, 'url' => $this->url];
    }

    public function isEmpty(): bool
    {
        return $this->text === '' && $this->url === '';
    }

    /**
     * The button as it lives in the hidden field of the form and in the jsonb
     * column, so a record opened for editing picks its button up again.
     */
    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Half a button is not a button: an address without a label gives a message
     * Telegram refuses, a label without an address gives one nothing to open.
     *
     * @throws InvalidArgumentException when the pair is half-filled, the label
     *                                  is over the byte limit or the address is
     *                                  not one a button can carry
     */
    public function assertValid(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        if ($this->text === '' || $this->url === '') {
            throw new InvalidArgumentException(
                'Кнопке-ссылке нужны и надпись, и адрес: заполните оба поля или оставьте их пустыми.',
            );
        }

        $bytes = strlen($this->text);
        if ($bytes > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Надпись кнопки не может быть длиннее %d байт (сейчас %d).', self::TEXT_MAX_LENGTH, $bytes),
            );
        }

        $length = mb_strlen($this->url);
        if ($length > self::URL_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Адрес кнопки не может быть длиннее %d символов.', self::URL_MAX_LENGTH),
            );
        }

        if (!self::isButtonUrl($this->url)) {
            throw new InvalidArgumentException(
                'Адрес кнопки должен начинаться с http://, https:// или tg://.',
            );
        }
    }

    private static function isButtonUrl(string $url): bool
    {
        if (str_starts_with($url, 'tg://')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'));
    }
}
