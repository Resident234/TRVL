<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Telegram\Service;

use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Dto\PostResult;
use app\shared\Telegram\Service\ChannelService;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class ChannelServicePublishPhotosTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    private TelegramChannelClientInterface&\PHPUnit\Framework\MockObject\MockObject $_client;
    private ChannelService $_service;

    protected function _before(): void
    {
        parent::_before();

        $descriptions = $this->createMock(PublishedDescriptionRepositoryInterface::class);
        $this->_client = $this->createMock(TelegramChannelClientInterface::class);
        $this->_service = new ChannelService($this->_client, self::CHANNEL_ID, $descriptions);
    }

    public function testSinglePhotoIsSentAsPhotoMessageWithCaption(): void
    {
        $this->_client
            ->expects($this->once())
            ->method('sendPhotoMessage')
            ->with(self::CHANNEL_ID, 'https://example.com/a.jpg', 'Текст', new MessageEntities(), new LinkButtons())
            ->willReturn(new PostResult(11, 123));

        $this->_client->expects($this->never())->method('sendPhotoGroupMessage');
        $this->_client->expects($this->never())->method('sendTextMessage');

        $this->assertSame(11, $this->_service->publishPhotos('Текст', ['https://example.com/a.jpg']));
    }

    public function testTwoToTenPhotosAreSentAsMediaGroup(): void
    {
        $urls = [
            'https://example.com/a.jpg',
            'https://example.com/b.jpg',
            'https://example.com/c.jpg',
        ];

        $this->_client
            ->expects($this->once())
            ->method('sendPhotoGroupMessage')
            ->with(self::CHANNEL_ID, $urls, 'Текст', new MessageEntities(), new LinkButtons())
            ->willReturn(new PostResult(12, 123));

        $this->_client->expects($this->never())->method('sendPhotoMessage');

        $this->assertSame(12, $this->_service->publishPhotos('Текст', $urls));
    }

    public function testMoreThanTenPhotosAreSplitIntoAlbums(): void
    {
        $urls = [];
        for ($i = 1; $i <= 12; $i++) {
            $urls[] = "https://example.com/{$i}.jpg";
        }

        $this->_client
            ->expects($this->exactly(2))
            ->method('sendPhotoGroupMessage')
            ->willReturnCallback(
                static function (string $channelId, array $photoUrls, string $caption): PostResult {
                    return new PostResult(100 + count($photoUrls), 123);
                },
            );

        $this->assertSame(110, $this->_service->publishPhotos('Текст', $urls));
    }

    public function testKeyboardTravelsWithTheAlbumCarryingTheCaption(): void
    {
        $buttons = LinkButtons::fromPairs(
            ['Пройти опрос', 'Открыть'],
            ['https://example.com/poll', 'https://example.com/open'],
        );
        $urls = [];
        for ($i = 1; $i <= 12; $i++) {
            $urls[] = "https://example.com/{$i}.jpg";
        }

        $sent = [];
        $this->_client
            ->expects($this->exactly(2))
            ->method('sendPhotoGroupMessage')
            ->willReturnCallback(
                static function (
                    string $channelId,
                    array $photoUrls,
                    string $caption,
                    MessageEntities $entities,
                    LinkButtons $buttons,
                ) use (&$sent): PostResult {
                    $sent[] = $buttons->toArray();

                    return new PostResult(100 + count($photoUrls), 123);
                },
            );

        $this->assertSame(110, $this->_service->publishPhotos('Текст', $urls, new MessageEntities(), $buttons));
        $this->assertSame(
            [
                [
                    ['text' => 'Пройти опрос', 'url' => 'https://example.com/poll'],
                    ['text' => 'Открыть', 'url' => 'https://example.com/open'],
                ],
                [],
            ],
            $sent,
            'Only the first album carries the caption, so only that one gets the keyboard.',
        );
    }

    public function testLongTextCaptionEndsAtLineBreakAndRestSentAsContinuation(): void
    {
        $line = str_repeat('а', 100);
        $text = implode("\n", array_fill(0, 12, $line));

        $this->_client
            ->expects($this->once())
            ->method('sendPhotoMessage')
            ->with(
                self::CHANNEL_ID,
                'https://example.com/a.jpg',
                implode("\n", array_fill(0, 10, $line)),
                new MessageEntities(),
                new LinkButtons(),
            )
            ->willReturn(new PostResult(13, 123));
        $this->_client
            ->expects($this->once())
            ->method('sendTextMessage')
            ->with(
                self::CHANNEL_ID,
                implode("\n", array_fill(0, 2, $line)),
                new MessageEntities(),
                new LinkButtons(),
            )
            ->willReturn(new PostResult(14, 123));

        $this->assertSame(13, $this->_service->publishPhotos($text, ['https://example.com/a.jpg']));
    }

    public function testFormattingIsCutTogetherWithTheCaption(): void
    {
        $line = str_repeat('а', 100);
        $text = implode("\n", array_fill(0, 12, $line));

        $formatting = new MessageEntities([
            ['type' => 'bold', 'offset' => 50, 'length' => 100],
            ['type' => 'code', 'offset' => 1005, 'length' => 20],
            ['type' => 'italic', 'offset' => 1015, 'length' => 5],
        ]);

        $this->_client
            ->expects($this->once())
            ->method('sendPhotoMessage')
            ->with(
                self::CHANNEL_ID,
                'https://example.com/a.jpg',
                implode("\n", array_fill(0, 10, $line)),
                new MessageEntities([
                    ['type' => 'bold', 'offset' => 50, 'length' => 100],
                    ['type' => 'code', 'offset' => 1005, 'length' => 4],
                ]),
                new LinkButtons(),
            )
            ->willReturn(new PostResult(13, 123));
        $this->_client
            ->expects($this->once())
            ->method('sendTextMessage')
            ->with(
                self::CHANNEL_ID,
                implode("\n", array_fill(0, 2, $line)),
                new MessageEntities([
                    ['type' => 'code', 'offset' => 0, 'length' => 15],
                    ['type' => 'italic', 'offset' => 5, 'length' => 5],
                ]),
                new LinkButtons(),
            )
            ->willReturn(new PostResult(14, 123));

        $this->assertSame(13, $this->_service->publishPhotos($text, ['https://example.com/a.jpg'], $formatting));
    }

    public function testLongTextWithoutLineBreaksIsCutAtCaptionLimit(): void
    {
        $text = str_repeat('а', 2000);

        $this->_client
            ->expects($this->once())
            ->method('sendPhotoMessage')
            ->with(
                self::CHANNEL_ID,
                'https://example.com/a.jpg',
                str_repeat('а', 1024),
                new MessageEntities(),
                new LinkButtons(),
            )
            ->willReturn(new PostResult(13, 123));
        $this->_client
            ->expects($this->once())
            ->method('sendTextMessage')
            ->with(self::CHANNEL_ID, str_repeat('а', 976), new MessageEntities(), new LinkButtons())
            ->willReturn(new PostResult(14, 123));

        $this->assertSame(13, $this->_service->publishPhotos($text, ['https://example.com/a.jpg']));
    }

    public function testEmptyTextSendsThePhotoWithoutACaption(): void
    {
        $this->_client
            ->expects($this->once())
            ->method('sendPhotoMessage')
            ->with(self::CHANNEL_ID, 'https://example.com/a.jpg', '', new MessageEntities(), new LinkButtons())
            ->willReturn(new PostResult(16, 123));

        $this->assertSame(16, $this->_service->publishPhotos('', ['https://example.com/a.jpg']));
    }

    public function testEmptyTextSendsAnAlbumWithoutACaption(): void
    {
        $urls = [
            'https://example.com/a.jpg',
            'https://example.com/b.jpg',
        ];

        $this->_client
            ->expects($this->once())
            ->method('sendPhotoGroupMessage')
            ->with(self::CHANNEL_ID, $urls, '', new MessageEntities(), new LinkButtons())
            ->willReturn(new PostResult(17, 123));

        $this->assertSame(17, $this->_service->publishPhotos('', $urls));
    }

    public function testEmptyTextWithoutPhotosThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->_service->publishPhotos('', []);
    }

    public function testEmptyPhotoListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->_service->publishPhotos('Текст', []);
    }

    public function testEmptyPhotoStringsAreFiltered(): void
    {
        $this->_client
            ->expects($this->once())
            ->method('sendPhotoMessage')
            ->with(self::CHANNEL_ID, 'https://example.com/a.jpg', 'Текст', new MessageEntities(), new LinkButtons())
            ->willReturn(new PostResult(15, 123));

        $this->assertSame(
            15,
            $this->_service->publishPhotos('Текст', ['', 'https://example.com/a.jpg', '']),
        );
    }
}
