<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\shared\Forum\Service\CountryTagExtractor;
use Codeception\Test\Unit;

final class CountryTagExtractorTest extends Unit
{
    public function testExtractsCountryFromForumTitle(): void
    {
        $extractor = new CountryTagExtractor();

        $this->assertSame(
            ['испания'],
            $extractor->extract('ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании'),
        );
    }

    public function testExtractsEveryCountryOfACombinedForum(): void
    {
        $extractor = new CountryTagExtractor();

        $this->assertSame(
            ['швеция', 'дания'],
            $extractor->extract('ШВЕЦИЯ и ДАНИЯ — форум путешественников по странам Скандинавии'),
        );
        $this->assertSame(
            ['сербия', 'северная македония', 'босния и герцеговина', 'косово'],
            $extractor->extract('СЕРБИЯ МАКЕДОНИЯ БОСНИЯ и ГЕРЦЕГОВИНА, КОСОВО форум'),
        );
        $this->assertSame(
            ['коста-рика', 'панама', 'никарагуа'],
            $extractor->extract('Коста Рика, Панама, Никарагуа форум'),
        );
    }

    public function testFoldsAliasesAndCaseIntoOneTag(): void
    {
        $extractor = new CountryTagExtractor();

        $this->assertSame(
            ['беларусь'],
            $extractor->extract('Белоруссия (Беларусь) форум от А до Я'),
        );
        $this->assertSame(
            ['великобритания'],
            $extractor->extract('АНГЛИЯ и ШОТЛАНДИЯ — форум путешественников'),
        );
        $this->assertSame(
            ['доминиканская республика'],
            $extractor->extract('ДОМИНИКАНА форум — путешествия, отдых и пляжи'),
        );
    }

    public function testDoesNotTagRegions(): void
    {
        $extractor = new CountryTagExtractor();

        $this->assertSame([], $extractor->extract('ЕВРОПА форум'));
        $this->assertSame([], $extractor->extract('Западная и Центральная Африка форум — путешествия'));
        $this->assertSame([], $extractor->extract('Общие вопросы о путешествиях — советы и подготовка'));
    }

    public function testReadsOnlyTheLabelBeforeTheDescription(): void
    {
        $extractor = new CountryTagExtractor();

        $this->assertSame(
            ['австралия'],
            $extractor->extract('АВСТРАЛИЯ форум — путешествия по Новой Зеландии и Японии'),
        );
    }

    public function testDoesNotRepeatTheSameTag(): void
    {
        $extractor = new CountryTagExtractor();

        $this->assertSame(
            ['испания'],
            $extractor->extract('ИСПАНИЯ форум — отдых в Испании, города и пляжи Испании'),
        );
    }
}
