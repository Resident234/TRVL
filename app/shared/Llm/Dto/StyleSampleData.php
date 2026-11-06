<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * The block of the sample, already assembled: whose style it is, how many
 * characters it carries, and the text with its own heading. The service pastes
 * `block` behind the prompt; the heading is part of it, so the model is told
 * what it is looking at even when the writer removed `{style}` from the prompt.
 */
final readonly class StyleSampleData
{
    public function __construct(
        public string $styleName,
        public int $chars,
        public string $block,
    ) {
    }
}
