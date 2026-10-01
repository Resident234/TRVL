<?php

declare(strict_types=1);

namespace app\shared\Telegram\Infrastructure;

use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Dto\ChannelInfo;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Dto\PostResult;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\Message\MessageEntity;
use Throwable;

/**
 * Nutgram-based adapter. All SDK types stay inside: upper layers
 * receive and return DTOs only.
 */
final class NutgramChannelClient implements TelegramChannelClientInterface
{
    /**
     * Nutgram asks for five seconds, which is not the time the API answers in
     * but the time the connection to it takes: the first call of a run spends
     * most of it negotiating the route, measured here at fifteen seconds. A
     * task that never gets past that is a task that fails every five minutes.
     */
    private const REQUEST_TIMEOUT = 30;

    private Nutgram $_bot;

    public function __construct(string $token)
    {
        $this->_bot = new Nutgram(
            $token,
            Configuration::fromArray(['timeout' => self::REQUEST_TIMEOUT]),
        );
    }

    public function getChannelInfo(string $channelId): ChannelInfo
    {
        $chat = $this->call(static fn (Nutgram $bot): ?Chat => $bot->getChat($channelId));

        return new ChannelInfo(
            $chat->id,
            is_string($chat->type) ? $chat->type : $chat->type->value,
            $chat->title,
            $chat->username,
            $chat->description,
        );
    }

    public function setChannelDescription(string $channelId, string $description): void
    {
        $this->call(static fn (Nutgram $bot): ?bool => $bot->setChatDescription($channelId, $description));
    }

    public function sendTextMessage(
        string $channelId,
        string $text,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): PostResult {
        $message = $this->call(
            static fn (Nutgram $bot): ?Message => $bot->sendMessage(
                chat_id: $channelId,
                text: $text,
                entities: self::toMessageEntities($entities),
                reply_markup: self::toKeyboard($buttons),
            ),
        );

        return new PostResult($message->message_id, $message->date);
    }

    public function sendPhotoMessage(
        string $channelId,
        string $photoPath,
        string $caption,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): PostResult {
        // Check if it's a local file path
        $photo = $this->isLocalFile($photoPath) ? new InputFile($photoPath) : $photoPath;

        $message = $this->call(
            static fn (Nutgram $bot): ?Message => $bot->sendPhoto(
                chat_id: $channelId,
                photo: $photo,
                caption: $caption,
                caption_entities: self::toMessageEntities($entities),
                reply_markup: self::toKeyboard($buttons),
            ),
        );

        return new PostResult($message->message_id, $message->date);
    }

    /**
     * An album carries no keyboard of its own: sendMediaGroup takes no
     * reply_markup, so the button of a part with photos is attached to the
     * first message of the group right after the group is sent.
     */
    public function sendPhotoGroupMessage(
        string $channelId,
        array $photoUrls,
        string $caption,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): PostResult {
        $media = [];
        foreach (array_values($photoUrls) as $index => $url) {
            // Check if it's a local file path
            $mediaUrl = $this->isLocalFile($url) ? new InputFile($url) : $url;
            $media[] = new InputMediaPhoto(
                media: $mediaUrl,
                caption: $index === 0 ? $caption : null,
                caption_entities: $index === 0 ? self::toMessageEntities($entities) : null,
            );
        }

        $keyboard = self::toKeyboard($buttons);

        $messages = $this->call(
            static fn (Nutgram $bot): ?array => $bot->sendMediaGroup(
                media: $media,
                chat_id: $channelId,
            ),
        );

        $first = $messages[0] ?? null;
        if ($first === null) {
            throw new TelegramApiException('Telegram API returned an empty media group.');
        }

        if ($keyboard !== null) {
            $this->call(
                static fn (Nutgram $bot): bool => $bot->editMessageReplyMarkup(
                    chat_id: $channelId,
                    message_id: $first->message_id,
                    reply_markup: $keyboard,
                ) !== null,
            );
        }

        return new PostResult($first->message_id, $first->date);
    }

    /**
     * Check if the given path is a local file.
     */
    private function isLocalFile(string $path): bool
    {
        // Check if it's an absolute path or relative path that exists
        return file_exists($path) || (str_starts_with($path, '/') && file_exists($path));
    }

    public function pinChannelMessage(string $channelId, int $messageId): void
    {
        $this->call(
            static fn (Nutgram $bot): ?bool => $bot->pinChatMessage(
                chat_id: $channelId,
                message_id: $messageId,
            ),
        );
    }

    public function deleteChannelMessage(string $channelId, int $messageId): void
    {
        $this->call(
            static fn (Nutgram $bot): ?bool => $bot->deleteMessage(
                chat_id: $channelId,
                message_id: $messageId,
            ),
        );
    }

    public function editChannelMessageText(
        string $channelId,
        int $messageId,
        string $text,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): void {
        $this->call(
            static fn (Nutgram $bot): bool => $bot->editMessageText(
                text: $text,
                chat_id: $channelId,
                message_id: $messageId,
                entities: self::toMessageEntities($entities),
                reply_markup: self::toReplacementKeyboard($buttons),
            ) !== null,
        );
    }

    /**
     * An edit replaces the keyboard of the message, so a post whose buttons
     * were removed has to send an empty markup: Telegram takes it as "drop
     * the keyboard", while an omitted parameter leaves the old one in place.
     */
    private static function toReplacementKeyboard(LinkButtons $buttons): InlineKeyboardMarkup
    {
        return self::toKeyboard($buttons) ?? InlineKeyboardMarkup::make();
    }

    /**
     * Our DTO in, SDK types out: no Nutgram class leaks above this adapter.
     * An empty list gives no keyboard at all, which is how a message without
     * buttons reaches the API. Every button takes a row of its own: a client
     * draws a row across the whole width of the message, which is how the one
     * button of a message used to look.
     */
    private static function toKeyboard(LinkButtons $buttons): ?InlineKeyboardMarkup
    {
        if ($buttons->isEmpty()) {
            return null;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($buttons->all() as $button) {
            $keyboard->addRow(InlineKeyboardButton::make(text: $button->text, url: $button->url));
        }

        return $keyboard;
    }

    /**
     * Our DTO in, SDK types out: no Nutgram class leaks above this adapter.
     * Nutgram omits a null parameter, which is how "no formatting" reaches
     * the API.
     *
     * @return MessageEntity[]|null
     */
    private static function toMessageEntities(MessageEntities $entities): ?array
    {
        if ($entities->isEmpty()) {
            return null;
        }

        return array_map(
            static fn (array $entity): MessageEntity => new MessageEntity(
                type: (string) $entity['type'],
                offset: (int) $entity['offset'],
                length: (int) $entity['length'],
                url: isset($entity['url']) ? (string) $entity['url'] : null,
            ),
            $entities->toArray(),
        );
    }

    /**
     * @template T
     * @param callable(Nutgram): T $operation
     * @return T
     * @throws TelegramApiException
     */
    private function call(callable $operation): mixed
    {
        try {
            return $operation($this->_bot);
        } catch (TelegramException $e) {
            throw TelegramApiException::fromTelegramException($e);
        } catch (Throwable $e) {
            throw new TelegramApiException('Telegram API transport failure: ' . $e->getMessage(), 0, null, $e);
        }
    }
}
