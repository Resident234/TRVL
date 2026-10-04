<?php

declare(strict_types=1);

namespace app\tests\Unit\widgets;

use app\shared\Publications\Dto\PublicationData;
use app\widgets\PublicationsUi;
use Codeception\Test\Unit;

/**
 * The status a list row carries: the channel has the message, the channel
 * does not have it yet and its time has come, or the channel does not have
 * it and its time is still ahead.
 */
final class PublicationsUiStatusBadgeTest extends Unit
{
    private const NOW = '2026-10-04 09:00:00';

    private function badge(?int $telegramId, ?string $publishedAt): string
    {
        $record = new PublicationData(
            42,
            'По нужде в различных уголках Мира',
            ['http://foto.awd.ru/data/media/15/_09_614-1.jpg'],
            $telegramId,
            $publishedAt,
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
        );

        return PublicationsUi::statusBadge($record, self::NOW);
    }

    public function testAMessageTheChannelHasIsPublished(): void
    {
        $this->assertSame(
            '<span class="badge bg-success mt-2">Опубликовано</span>',
            $this->badge(5658, '2026-10-04 08:50:00'),
        );
    }

    public function testARecordWhoseTimeCameButNeverReachedTheChannelStandsInTheQueue(): void
    {
        $badge = $this->badge(null, '2026-10-04 08:50:00');

        $this->assertSame('<span class="badge bg-warning text-dark mt-2">В очереди</span>', $badge);
        $this->assertStringNotContainsString('Опубликовано', $badge);
        $this->assertStringNotContainsString('Запланировано', $badge);
    }

    public function testARecordOfAFutureTimeIsScheduled(): void
    {
        $this->assertSame(
            '<span class="badge bg-info mt-2">Запланировано</span>',
            $this->badge(null, '2026-10-04 12:00:00'),
        );
    }

    public function testARecordWhoseTimeHasComeExactlyNowStandsInTheQueue(): void
    {
        $this->assertSame(
            '<span class="badge bg-warning text-dark mt-2">В очереди</span>',
            $this->badge(null, self::NOW),
        );
    }

    public function testARecordWithoutATimeIsScheduled(): void
    {
        $this->assertSame(
            '<span class="badge bg-info mt-2">Запланировано</span>',
            $this->badge(null, null),
        );
    }
}
