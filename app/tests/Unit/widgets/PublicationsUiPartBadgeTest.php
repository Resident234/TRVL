<?php

declare(strict_types=1);

namespace app\tests\Unit\widgets;

use app\shared\Publications\Dto\PublicationData;
use app\widgets\PublicationsUi;
use Codeception\Test\Unit;

/**
 * The group a list row belongs to: a part says which part of how many it is,
 * while a whole publication and the last part alive of a group say nothing of
 * the kind.
 */
final class PublicationsUiPartBadgeTest extends Unit
{
    private const GROUP = 'abababababababababababababababab';

    private function badge(
        ?string $groupId,
        ?int $order,
        ?int $total,
    ): string {
        $record = new PublicationData(
            42,
            'По нужде в различных уголках Мира',
            [],
            null,
            '2026-10-04 08:50:00',
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
            null,
            partGroupId: $groupId,
            partOrder: $order,
            partGroupTotal: $total,
        );

        return PublicationsUi::partBadge($record);
    }

    public function testAPartOfAGroupWearsItsNumberAndHowManyTheGroupHas(): void
    {
        $this->assertSame(
            '<span class="badge bg-primary-subtle text-primary mt-2">Часть 2 из 3</span>',
            $this->badge(self::GROUP, 2, 3),
        );
    }

    public function testAPublicationOfNoGroupWearsNothing(): void
    {
        $this->assertSame('', $this->badge(null, null, null));
    }

    public function testTheLastPartAliveOfAGroupWearsNothing(): void
    {
        $this->assertSame('', $this->badge(self::GROUP, 3, 1));
    }

    public function testAPartWhoseGroupWasNeverCountedWearsNothing(): void
    {
        $this->assertSame('', $this->badge(self::GROUP, 1, null));
    }
}
