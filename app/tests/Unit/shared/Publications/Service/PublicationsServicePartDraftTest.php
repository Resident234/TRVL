<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;
use InvalidArgumentException;
use stdClass;

/**
 * A part the form invented carries no row of its own, and the «Сохранить» of its
 * corner gives it one: a draft that joins the group of the form behind the parts
 * the group already holds, and a plain draft when the form carries no group.
 */
final class PublicationsServicePartDraftTest extends Unit
{
    private const GROUP = 'abababababababababababababababab';

    private const NEW_PART = 'Новая часть';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private PublicationsService $_service;

    /**
     * One part of the group as any of the three tables hands it over. Only its
     * place matters here: the new draft is numbered behind the last of them.
     */
    private function part(int $id, int $order): PublicationData
    {
        return new PublicationData(
            $id,
            'Часть ' . $order,
            [],
            null,
            '2026-10-04 08:50:00',
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
            partGroupId: self::GROUP,
            partOrder: $order,
        );
    }

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

    public function testANewPartOfAGroupStartsBehindThePartsItHolds(): void
    {
        $this->_repository
            ->method('findGroupRows')
            ->willReturn([$this->part(4, 1), $this->part(9, 3)]);

        $this->_repository
            ->expects($this->once())
            ->method('createDraft')
            ->with(
                self::NEW_PART,
                [],
                $this->anything(),
                $this->anything(),
                $this->anything(),
                '',
                self::GROUP,
                4,
            )
            ->willReturn(14);

        $id = $this->_service->savePartAsDraft(
            self::NEW_PART,
            [],
            new MessageEntities(),
            new LinkButtons(),
            '',
            self::GROUP,
        );

        $this->assertSame(
            14,
            $id,
            'The form must learn the id of the row it has just made, or its part stays an invented one.',
        );
    }

    public function testANewPartOutsideAGroupIsStoredAsAPlainDraft(): void
    {
        $this->_repository->expects($this->never())->method('findGroupRows');

        $this->_repository
            ->expects($this->once())
            ->method('createDraft')
            ->with(
                self::NEW_PART,
                [],
                $this->anything(),
                $this->anything(),
                $this->anything(),
                '',
                '',
                0,
            )
            ->willReturn(15);

        $id = $this->_service->savePartAsDraft(
            self::NEW_PART,
            [],
            new MessageEntities(),
            new LinkButtons(),
            '',
            '',
        );

        $this->assertSame(15, $id);
    }

    public function testANewPartWithNeitherTextNorPictureIsRefused(): void
    {
        $this->_repository->expects($this->never())->method('createDraft');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Публикации нужны текст или хотя бы одно изображение.');

        $this->_service->savePartAsDraft(
            '',
            [],
            new MessageEntities(),
            new LinkButtons(),
            '',
            self::GROUP,
        );
    }
}
