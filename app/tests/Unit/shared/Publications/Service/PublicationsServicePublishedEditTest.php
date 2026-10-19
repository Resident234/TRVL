<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;

/**
 * Editing a record that already reached the channel: the text the form brings is
 * the text the channel is told to show, and the row of the publications block
 * keeps standing there with it — the record the reader rewrote neither loses his
 * work nor leaves the block.
 */
final class PublicationsServicePublishedEditTest extends Unit
{
    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private ScriptedChannelStub $_channel;

    /** @var array<string, array<int, mixed>> */
    private array $_calls = [];

    private function service(): PublicationsService
    {
        return new PublicationsService($this->_repository, null, $this->_channel);
    }

    /**
     * @return array<int, mixed>
     */
    private function asked(string $method): array
    {
        return $this->_calls[$method] ?? [];
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
        $this->_channel = new ScriptedChannelStub([]);
        $this->_calls = [];

        $this->_repository
            ->method('findPost')
            ->willReturnCallback(
                static fn (int $id): PublicationData => new PublicationData(
                    $id,
                    'Старый текст',
                    [],
                    5658,
                    '2026-10-04 08:00:00',
                    '2026-10-04 07:00:00',
                    '2026-10-04 07:30:00',
                ),
            );

        $this->_repository
            ->method('archiveEdited')
            ->willReturnCallback(
                function (PublicationData $record, string $now): void {
                    $this->_calls['archiveEdited'][] = $record;
                },
            );

        $this->_repository
            ->method('updatePost')
            ->willReturnCallback(
                function (
                    int $id,
                    string $text,
                    array $images,
                    string $publishedAt,
                    string $now,
                ): void {
                    $this->_calls['updatePost'][] = [$id, $text, $publishedAt];
                },
            );

        $this->_repository
            ->method('deletePost')
            ->willReturnCallback(
                function (int $id): PublicationData {
                    $this->_calls['deletePost'][] = $id;

                    return new PublicationData(
                        $id,
                        'Старый текст',
                        [],
                        5658,
                        '2026-10-04 08:00:00',
                        '2026-10-04 07:00:00',
                        '2026-10-04 07:30:00',
                    );
                },
            );
    }

    public function testEditingAPublishedRecordArchivesTheTextTheFormBrought(): void
    {
        $this->service()->saveFromForm(
            'Новый текст',
            [],
            new MessageEntities(),
            new LinkButtons(),
            '',
            '2026-10-04 12:00:00',
            'post',
            5,
            'publish',
            'UTC',
        );

        $archived = $this->asked('archiveEdited');
        $this->assertCount(1, $archived, 'The channel is told to edit this message.');
        $this->assertSame('Новый текст', $archived[0]->text);
        $this->assertSame(5658, $archived[0]->telegramId);
        $this->assertSame(
            '2026-10-04 08:00:00',
            $archived[0]->publishedAt,
            'The message keeps the moment it went out at.',
        );
    }

    public function testEditingAPublishedRecordKeepsItsRowInThePublicationsBlock(): void
    {
        $this->service()->saveFromForm(
            'Новый текст',
            [],
            new MessageEntities(),
            new LinkButtons(),
            '',
            '2026-10-04 12:00:00',
            'post',
            5,
            'publish',
            'UTC',
        );

        $this->assertSame(
            [[5, 'Новый текст', '2026-10-04 08:00:00']],
            $this->asked('updatePost'),
        );
        $this->assertSame(
            [],
            $this->asked('deletePost'),
            'The block the record lies in keeps showing it as a published one.',
        );
    }
}
