<?php

declare(strict_types=1);

namespace app\shared\Telegram\Service;

use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Dto\ChannelInfo;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Dto\PublishedDescriptionData;
use app\shared\Telegram\Infrastructure\TelegramApiException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Use-cases for the TRVL channel: description management and posting.
 *
 * Every method that talks to the Telegram API can additionally throw
 * RuntimeException when the bot token is not configured.
 */
final class ChannelService
{
    public const DESCRIPTION_MAX_LENGTH = 255;
    public const TEXT_MAX_LENGTH = 4096;
    public const CAPTION_MAX_LENGTH = 1024;
    /** Telegram takes no more than this many photos into one album. */
    public const ALBUM_MAX_PHOTOS = 10;
    /**
     * The characters the «Часть N» prefix of a split publication keeps out of
     * the text budget: "Часть " plus a three-digit number plus a blank line.
     */
    public const PARTS_NUMBERING_RESERVE = 16;

    public function __construct(
        private readonly ?TelegramChannelClientInterface $client,
        private readonly string $channelId,
        private readonly PublishedDescriptionRepositoryInterface $publishedDescriptions,
    ) {
    }

    /**
     * Whether the Telegram bot is configured (token provided).
     */
    public function isConfigured(): bool
    {
        return $this->client !== null;
    }

    /**
     * @throws TelegramApiException on API failure
     * @throws RuntimeException when the bot token is not configured
     */
    public function channelInfo(): ChannelInfo
    {
        return $this->client()->getChannelInfo($this->channelId);
    }

    /**
     * @throws TelegramApiException on API failure
     * @throws RuntimeException when the bot token is not configured
     * @throws InvalidArgumentException when the description exceeds 255 chars
     */
    public function updateDescription(string $description): void
    {
        $length = mb_strlen($description);
        if ($length > self::DESCRIPTION_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Описание канала не может быть длиннее %d символов (сейчас %d).', self::DESCRIPTION_MAX_LENGTH, $length),
            );
        }

        $this->client()->setChannelDescription($this->channelId, $description);

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->publishedDescriptions->archiveAndStart($description, $now);
    }

    /**
     * @return PublishedDescriptionData[] published descriptions, newest first
     */
    public function publishedDescriptions(): array
    {
        return $this->publishedDescriptions->all();
    }

    /**
     * @throws TelegramApiException on API failure
     * @throws RuntimeException when the bot token is not configured
     * @throws InvalidArgumentException when the text is empty or longer than 4096 chars
     */
    public function publishText(
        string $text,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): int {
        if (mb_strlen($text) === 0 || mb_strlen($text) > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Текст поста должен быть от 1 до %d символов.', self::TEXT_MAX_LENGTH),
            );
        }

        return $this->client()->sendTextMessage($this->channelId, $text, $entities, $buttons)->messageId;
    }

    /**
     * @throws TelegramApiException on API failure
     * @throws InvalidArgumentException when the photo file is missing or the caption exceeds 1024 chars
     */
    public function publishPhoto(
        string $photoPath,
        string $caption,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): int {
        if (!is_file($photoPath)) {
            throw new InvalidArgumentException(sprintf('Файл изображения не найден: %s', $photoPath));
        }

        if (mb_strlen($caption) > self::CAPTION_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Подпись к изображению не может быть длиннее %d символов.', self::CAPTION_MAX_LENGTH),
            );
        }

        return $this->client()
            ->sendPhotoMessage($this->channelId, $photoPath, $caption, $entities, $buttons)
            ->messageId;
    }

    /**
     * Publishes a post with its photos: a single photo becomes a
     * photo message with the caption, two to ten photos become an
     * album (the caption goes on the first photo). The caption is
     * cropped to CAPTION_MAX_LENGTH, preferring the last line break that
     * still fits so a line is never cut in the middle. What does not fit
     * into the caption is sent once more after the photos as a
     * continuation message, never as a repeat of the whole text. The
     * formatting follows the same cut: entities are clipped to whatever
     * survived in the caption and re-based on the continuation. The link
     * buttons go under the message that carries the caption, so a part
     * gets its whole keyboard whatever the album is made of. The
     * message id of the first photo message is returned.
     *
     * @param string[] $photoUrls
     * @throws TelegramApiException on API failure
     * @throws InvalidArgumentException when the text is empty or the photo list is empty
     */
    public function publishPhotos(
        string $text,
        array $photoUrls,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): int {
        if (mb_strlen($text) === 0) {
            throw new InvalidArgumentException('Текст поста не может быть пустым.');
        }

        $photoUrls = array_values(array_filter(
            $photoUrls,
            static fn (string $url): bool => $url !== '',
        ));
        if ($photoUrls === []) {
            throw new InvalidArgumentException('Список изображений пуст.');
        }

        $caption = mb_substr($text, 0, self::CAPTION_MAX_LENGTH);
        $continuation = null;
        if (mb_strlen($text) > self::CAPTION_MAX_LENGTH) {
            $lastBreak = mb_strrpos($caption, "\n");
            if ($lastBreak !== false && $lastBreak > 0) {
                $caption = mb_substr($caption, 0, $lastBreak);
            }

            $rest = ltrim(mb_substr($text, mb_strlen($caption)), "\n");
            if ($rest !== '') {
                $continuation = $rest;
            }
        }

        $captionEntities = $entities->slice(
            0,
            MessageEntities::utf16Length($caption),
        );
        // The continuation is always a tail of the text, so its entities
        // start where the tail starts and run to the end.
        $continuationEntities = $continuation === null
            ? new MessageEntities()
            : $entities->slice(
                MessageEntities::utf16Length($text) - MessageEntities::utf16Length($continuation),
            );

        $firstMessageId = 0;

        foreach (array_chunk($photoUrls, self::ALBUM_MAX_PHOTOS) as $chunk) {
            // The keyboard belongs to the message the caption went into, which is
            // the first photo of the first album: a publication gets its buttons
            // once, not once per ten pictures.
            $chunkButtons = $firstMessageId === 0 ? $buttons : LinkButtons::empty();

            if (count($chunk) === 1) {
                $messageId = $this->client()
                    ->sendPhotoMessage($this->channelId, $chunk[0], $caption, $captionEntities, $chunkButtons)
                    ->messageId;
            } else {
                $messageId = $this->client()
                    ->sendPhotoGroupMessage($this->channelId, $chunk, $caption, $captionEntities, $chunkButtons)
                    ->messageId;
            }

            if ($firstMessageId === 0) {
                $firstMessageId = $messageId;
            }
        }

        if ($continuation !== null) {
            $this->client()->sendTextMessage(
                $this->channelId,
                $continuation,
                $continuationEntities,
                LinkButtons::empty(),
            );
        }

        return $firstMessageId;
    }

    /**
     * @throws TelegramApiException on API failure
     */
    public function pinPost(int $messageId): void
    {
        $this->client()->pinChannelMessage($this->channelId, $messageId);
    }

    /**
     * @throws TelegramApiException on API failure
     * @throws RuntimeException when the bot token is not configured
     */
    public function deletePost(int $messageId): void
    {
        $this->client()->deleteChannelMessage($this->channelId, $messageId);
    }

    /**
     * @throws TelegramApiException on API failure
     * @throws RuntimeException when the bot token is not configured
     * @throws InvalidArgumentException when the text is empty or longer than 4096 chars
     */
    public function editPostText(
        int $messageId,
        string $text,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): void {
        if (mb_strlen($text) === 0 || mb_strlen($text) > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Текст поста должен быть от 1 до %d символов.', self::TEXT_MAX_LENGTH),
            );
        }

        $this->client()->editChannelMessageText($this->channelId, $messageId, $text, $entities, $buttons);
    }

    /**
     * @throws RuntimeException when the bot token is not configured
     */
    private function client(): TelegramChannelClientInterface
    {
        if ($this->client === null) {
            throw new RuntimeException(
                'Telegram-бот не сконфигурирован: задайте TELEGRAM_BOT_TOKEN в .env и перезапустите стек.',
            );
        }

        return $this->client;
    }
}
