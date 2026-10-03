<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Service\PublicationsService;
use Codeception\Test\Unit;
use InvalidArgumentException;
use stdClass;

final class PublicationsServiceEmptyTextTest extends Unit
{
    private const TZ = 'Europe/Moscow';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private PublicationsService $_service;

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
        $this->_service = new PublicationsService(
            $this->_repository,
            null,
            new stdClass(),
        );
    }

    public function testEmptyTextWithAnAlbumIsSavedAsDraft(): void
    {
        $this->_repository
            ->expects($this->once())
            ->method('createDraft')
            ->with('', ['https://example.com/a.jpg'], $this->anything(), $this->anything(), $this->anything(), '')
            ->willReturn(7);

        $this->_service->saveParts(
            [''],
            [['https://example.com/a.jpg']],
            [],
            [],
            [],
            '',
            'draft',
            null,
            self::TZ,
        );
    }

    public function testEmptyTextWithoutAnAlbumIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Публикации нужны текст или хотя бы одно изображение.');

        $this->_repository->expects($this->never())->method('createDraft');

        $this->_service->saveParts([''], [[]], [], [], [], '', 'draft', null, self::TZ);
    }

    public function testEmptyTextWithAHeadingButNoAlbumIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->_repository->expects($this->never())->method('createDraft');

        $this->_service->saveParts([''], [[]], [], [], ['Заголовок'], '', 'draft', null, self::TZ);
    }

    public function testAPartWithoutTextKeepsItsOwnAlbum(): void
    {
        $this->_repository
            ->expects($this->once())
            ->method('createDrafts')
            ->with(
                $this->callback(static fn (array $rows): bool => [
                    [$rows[0]['text'], $rows[0]['imageUrls']],
                    [$rows[1]['text'], $rows[1]['imageUrls']],
                ] === [
                    ['Первая часть', ['https://example.com/a.jpg']],
                    ['', ['https://example.com/b.jpg', 'https://example.com/c.jpg']],
                ]),
                $this->anything(),
            )
            ->willReturn(11);

        $this->_service->saveParts(
            ['Первая часть', ''],
            [['https://example.com/a.jpg'], ['https://example.com/b.jpg', 'https://example.com/c.jpg']],
            [],
            [],
            [],
            '',
            'draft',
            null,
            self::TZ,
        );
    }

    public function testAPartWithoutTextAndWithoutAlbumIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->_repository->expects($this->never())->method('createDrafts');

        $this->_service->saveParts(
            ['Первая часть', ''],
            [['https://example.com/a.jpg'], []],
            [],
            [],
            [],
            '',
            'draft',
            null,
            self::TZ,
        );
    }
}
