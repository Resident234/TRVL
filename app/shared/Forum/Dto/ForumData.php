<?php

declare(strict_types=1);

namespace app\shared\Forum\Dto;

final readonly class ForumData
{
    public function __construct(
        public int $id,
        public string $sourceUrl,
        public string $title,
    ) {
    }
}
