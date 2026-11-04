<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Settings\Service;

use app\shared\Settings\Service\PublicationSettingsService;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class PublicationSettingsServiceTextStyleTest extends Unit
{
    /** @var string[] the two folders one test stands on */
    private const FOLDERS = ['g_jobbot', 'Ватник в Америке'];

    /**
     * @param string[] $folders
     */
    private function service(
        InMemorySettingsRepository $repository,
        array $folders = self::FOLDERS,
    ): PublicationSettingsService {
        return new PublicationSettingsService($repository, new ListedStylesStub($folders), '*/5 * * * *');
    }

    /**
     * The whole tunables form, with the defaults of the schema and whatever the
     * test cares about written over them. The gap between the parts stands at the
     * cron of this service, which is its lower bound, not at the default of the
     * schema: a form that failed there never reaches the style.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function submitted(array $overrides): array
    {
        return array_merge(
            PublicationSettingsService::defaults(),
            ['partsOffsetMinutes' => '5'],
            $overrides,
        );
    }

    public function testFormAcceptsTheEmptyChoice(): void
    {
        $repository = new InMemorySettingsRepository();

        $this->service($repository)->save($this->submitted(['activeTextStyle' => '']));

        $this->assertSame('', $repository->values['activeTextStyle']);
    }

    public function testFormStoresAStyleOfTheCatalog(): void
    {
        $repository = new InMemorySettingsRepository();

        $this->service($repository)->save($this->submitted(['activeTextStyle' => 'Ватник в Америке']));

        $this->assertSame('Ватник в Америке', $repository->values['activeTextStyle']);
    }

    public function testFormStoresAStyleWhoseNameNeedsNoTrimming(): void
    {
        $repository = new InMemorySettingsRepository();

        $this->service($repository)->save($this->submitted(['activeTextStyle' => "  g_jobbot\n"]));

        $this->assertSame('g_jobbot', $repository->values['activeTextStyle']);
    }

    public function testFormRefusesAStyleTheCatalogDoesNotHave(): void
    {
        $repository = new InMemorySettingsRepository();
        $repository->values['activeTextStyle'] = 'g_jobbot';

        try {
            $this->service($repository)->save($this->submitted(['activeTextStyle' => 'Снятый со диска']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                'Вариант настройки «Активный стиль текста» надо выбрать из списка.',
                $e->getMessage(),
            );
        }

        $this->assertSame(0, $repository->saves);
        $this->assertSame('g_jobbot', $repository->values['activeTextStyle']);
    }

    public function testFormWithoutTheStyleIsRefused(): void
    {
        $repository = new InMemorySettingsRepository();
        $submitted = $this->submitted([]);
        unset($submitted['activeTextStyle']);

        try {
            $this->service($repository)->save($submitted);
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Настройка «Активный стиль текста» не пришла в форме.', $e->getMessage());
        }

        $this->assertSame(0, $repository->saves);
    }

    public function testReadKeepsAStyleTheCatalogStillHas(): void
    {
        $repository = new InMemorySettingsRepository(['activeTextStyle' => 'g_jobbot']);

        $this->assertSame('g_jobbot', $this->service($repository)->value('activeTextStyle'));
    }

    public function testReadOfAStyleThatLeftTheCatalogIsTheEmptyChoice(): void
    {
        $repository = new InMemorySettingsRepository(['activeTextStyle' => 'Снятый со диска']);

        $this->assertSame('', $this->service($repository)->value('activeTextStyle'));
    }

    public function testReadWithoutAStoredRowIsTheEmptyChoice(): void
    {
        $repository = new InMemorySettingsRepository();

        $this->assertSame('', $this->service($repository)->value('activeTextStyle'));
    }

    public function testOptionsPutTheEmptyChoiceBeforeTheCatalog(): void
    {
        $options = $this->service(new InMemorySettingsRepository())->styleOptions();

        $this->assertSame(
            [
                '' => 'Стиль не выбран',
                'g_jobbot' => 'g_jobbot',
                'Ватник в Америке' => 'Ватник в Америке',
            ],
            $options,
        );
        $this->assertSame('', array_key_first($options));
    }

    public function testOptionsOfAnEmptyCatalogAreOnlyTheEmptyChoice(): void
    {
        $this->assertSame(
            ['' => 'Стиль не выбран'],
            $this->service(new InMemorySettingsRepository(), [])->styleOptions(),
        );
    }
}
