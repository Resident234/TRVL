<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Infrastructure;

use app\shared\Llm\Infrastructure\OpenAiCompatibleClient;
use Codeception\Test\Unit;

/**
 * The proxy answers on POST {base}/chat/completions. Everything the settings
 * page can hold as a base has to land there: with the version segment, with a
 * trailing slash, and without either.
 */
final class OpenAiCompatibleClientUrlTest extends Unit
{
    public function testTheDefaultAddressOfTheSetting(): void
    {
        $this->assertSame(
            'http://host.docker.internal:31415/v1/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('http://host.docker.internal:31415/v1'),
        );
    }

    public function testATrailingSlashDoesNotDoubleTheSegment(): void
    {
        $this->assertSame(
            'http://host.docker.internal:31415/v1/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('http://host.docker.internal:31415/v1/'),
        );
    }

    public function testAnAddressWithoutAVersionSegment(): void
    {
        $this->assertSame(
            'http://127.0.0.1:31415/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('http://127.0.0.1:31415'),
        );
    }

    public function testTheSegmentIsAppendedOnceOnly(): void
    {
        $this->assertSame(
            'https://proxy.internal/v1/chat/completions',
            OpenAiCompatibleClient::chatCompletionsUrl('https://proxy.internal/v1'),
        );
    }
}
