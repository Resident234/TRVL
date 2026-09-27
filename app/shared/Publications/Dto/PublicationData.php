<?php

declare(strict_types=1);

namespace app\shared\Publications\Dto;

use app\shared\Telegram\Dto\MessageEntities;

/**
 * A channel publication: a scheduled or published post, a draft, or
 * a soft-deleted record. telegramId is null until the post is
 * actually sent to Telegram. deletedAt is only filled for
 * publications_deleted rows: null means the record is still awaiting
 * removal from the channel (the message is alive), a value means
 * the channel message is already gone. formatting holds the inline
 * highlighting of the text, which the text column keeps plain.
 */
final readonly class PublicationData
{
    /**
     * @param string[] $imageUrls
     */
    public function __construct(
        public int $id,
        public string $text,
        public array $imageUrls,
        public ?int $telegramId,
        public ?string $publishedAt,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt = null,
        public MessageEntities $formatting = new MessageEntities(),
    ) {
    }
}
