<?php

declare(strict_types=1);

namespace app\shared\Forum\Contract;

/**
 * Write gateway to the forum-to-Telegram map tables, used by the
 * publications flow when a publication created from a forum topic or
 * post reaches the Telegram channel.
 */
interface ForumPublicationMapGatewayInterface
{
    /**
     * Inserts a publications_topic_map row with an empty telegram_id,
     * or stamps the telegram_id of an existing row. The post that
     * repeats the topic — the one numbered 1 of its thread — takes the
     * same state, whose own telegram_id is never overwritten.
     */
    public function storeTopicMapTelegramId(int $topicId, ?int $telegramId): void;

    /**
     * Inserts a publications_post_map row with an empty telegram_id,
     * or stamps the telegram_id of an existing row. When the post is
     * the one that repeats its topic, the topic gets the same state,
     * again without overwriting a telegram_id it already has.
     */
    public function storePostMapTelegramId(int $postId, ?int $telegramId): void;
}
