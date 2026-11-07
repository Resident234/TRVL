<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * One row of the dialog history: who spoke, what they said, and — for a reply of
 * the model — the text and the marks it offered to put into the field. The
 * replacement half stays empty for a command, and stays a JSON string for the
 * marks, the way the hidden field of the form holds them.
 */
final class LlmChatMessageData
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public readonly int $id = 0,
        public readonly string $partGroupId = '',
        public readonly int $partOrder = 0,
        public readonly string $role = self::ROLE_USER,
        public readonly string $content = '',
        public readonly ?string $replacementText = null,
        public readonly ?string $replacementFormatting = null,
        public readonly ?string $createdAt = null,
    ) {
    }
}
