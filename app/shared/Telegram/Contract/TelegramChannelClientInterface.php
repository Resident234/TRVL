<?php

declare(strict_types=1);

namespace app\shared\Telegram\Contract;

use app\shared\Telegram\Dto\ChannelInfo;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Dto\PostResult;

interface TelegramChannelClientInterface
{
    public function getChannelInfo(string $channelId): ChannelInfo;

    /**
     * @throws \app\shared\Telegram\Infrastructure\TelegramApiException
     */
    public function setChannelDescription(string $channelId, string $description): void;

    /**
     * @param MessageEntities $entities inline formatting of the text; an empty
     *        list sends plain text
     * @param LinkButtons $buttons the link buttons drawn under the message, packed
     *        into the rows LinkButtons::rows() describes; an empty list sends the
     *        message without a keyboard
     * @throws \app\shared\Telegram\Infrastructure\TelegramApiException
     */
    public function sendTextMessage(
        string $channelId,
        string $text,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): PostResult;

    /**
     * @throws \app\shared\Telegram\Infrastructure\TelegramApiException
     */
    public function sendPhotoMessage(
        string $channelId,
        string $photoPath,
        string $caption,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): PostResult;

    /**
     * Sends a group of photos as a single album message; the caption
     * is attached to the first photo. Returns the first message of
     * the group.
     *
     * @param string[] $photoUrls
     * @throws \app\shared\Telegram\Infrastructure\TelegramApiException
     */
    public function sendPhotoGroupMessage(
        string $channelId,
        array $photoUrls,
        string $caption,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): PostResult;

    /**
     * @throws \app\shared\Telegram\Infrastructure\TelegramApiException
     */
    public function pinChannelMessage(string $channelId, int $messageId): void;

    /**
     * @throws \app\shared\Telegram\Infrastructure\TelegramApiException
     */
    public function deleteChannelMessage(string $channelId, int $messageId): void;

    /**
     * @throws \app\shared\Telegram\Infrastructure\TelegramApiException
     */
    public function editChannelMessageText(
        string $channelId,
        int $messageId,
        string $text,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): void;
}
