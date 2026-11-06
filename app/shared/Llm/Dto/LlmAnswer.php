<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

use app\shared\Telegram\Dto\MessageEntities;

/**
 * What one operation hands back to the form: the text and the marks that belong to
 * it. The entities are already checked against this text — an answer whose marks
 * stick out of it never becomes an `LlmAnswer` at all.
 */
final readonly class LlmAnswer
{
    public function __construct(
        public string $text,
        public MessageEntities $entities,
    ) {
    }

    /**
     * The marks in the shape the hidden field of a part holds, so the answer is
     * written into the very field the form already serialises.
     */
    public function formattingJson(): string
    {
        return $this->entities->toJson();
    }
}
