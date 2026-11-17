<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Settings\Service;

use app\shared\Settings\Service\PublicationSettingsService;
use Codeception\Test\Unit;
use InvalidArgumentException;

/**
 * A line of the dialog kept as a prompt template is a row of the settings
 * table with its own prefix. It must stay out of the tunables and out of the
 * form, and keeping the same words twice must not make a second row.
 */
final class PublicationSettingsServiceTemplateTest extends Unit
{
    private function service(InMemorySettingsRepository $repository): PublicationSettingsService
    {
        return new PublicationSettingsService($repository, new ListedStylesStub([]), '*/5 * * * *');
    }

    public function testTheCodeIsStableAndFitsTheColumn(): void
    {
        $code = PublicationSettingsService::promptTemplateCode('  Сократи текст  ');

        $this->assertStringStartsWith(PublicationSettingsService::PROMPT_TEMPLATE_PREFIX, $code);
        $this->assertLessThanOrEqual(64, strlen($code));
        $this->assertSame(
            PublicationSettingsService::promptTemplateCode('Сократи текст'),
            $code,
        );
        $this->assertNotSame(
            PublicationSettingsService::promptTemplateCode('Сократи текст.'),
            $code,
        );
    }

    public function testTheFirstSaveCreatesTheRowAndTheSecondOneDoesNot(): void
    {
        $repository = new InMemorySettingsRepository();
        $service = $this->service($repository);

        $first = $service->savePromptTemplate('  Сократи текст  ');
        $second = $service->savePromptTemplate('Сократи текст');

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['code'], $second['code']);
        $this->assertSame('Сократи текст', $repository->values[$first['code']]);
        $this->assertSame(1, $repository->saves);
        $this->assertTrue($service->hasPromptTemplate('Сократи текст'));
    }

    public function testATemplateIsNotOneOfTheTunables(): void
    {
        $repository = new InMemorySettingsRepository();
        $service = $this->service($repository);
        $saved = $service->savePromptTemplate('Сократи текст');

        $this->assertArrayHasKey($saved['code'], $service->promptTemplates());
        $this->assertArrayNotHasKey($saved['code'], $service->all());
        $this->assertArrayNotHasKey($saved['code'], PublicationSettingsService::defaults());

        // Saving the whole form of SCHEMA tunables leaves the template alone.
        $submitted = PublicationSettingsService::defaults();
        $submitted['partsOffsetMinutes'] = '5';
        $service->save($submitted);
        $this->assertSame('Сократи текст', $repository->values[$saved['code']]);
    }

    public function testOnlyRowsWithThePrefixAreTemplates(): void
    {
        $repository = new InMemorySettingsRepository(['foreignCode' => 'не шаблон']);
        $service = $this->service($repository);
        $saved = $service->savePromptTemplate('Сократи текст');

        $this->assertSame(
            [$saved['code'] => 'Сократи текст'],
            $service->promptTemplates(),
        );
    }

    public function testAnEmptyOrTooLongTemplateIsRefused(): void
    {
        $repository = new InMemorySettingsRepository();
        $service = $this->service($repository);

        try {
            $service->savePromptTemplate('   ');
            $this->fail('Пустой шаблон должен отвергаться.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $repository->saves);
        }

        try {
            $service->savePromptTemplate(str_repeat('а', PublicationSettingsService::PROMPT_TEMPLATE_MAX_CHARS + 1));
            $this->fail('Слишком длинный шаблон должен отвергаться.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $repository->saves);
        }
    }
}
