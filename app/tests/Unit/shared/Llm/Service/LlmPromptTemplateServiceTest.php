<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Service\LlmPromptTemplateService;
use Codeception\Test\Unit;
use InvalidArgumentException;

/**
 * The templates a reader saves from the dialog live in a table of their own.
 * Their codes are stable, repeating a line writes nothing, and none of this
 * touches the tunables or the two prompts edited on the settings page.
 */
final class LlmPromptTemplateServiceTest extends Unit
{
    private function service(InMemoryLlmPromptTemplateRepository $repository): LlmPromptTemplateService
    {
        return new LlmPromptTemplateService($repository);
    }

    public function testTheCodeIsStableAndFitsTheColumn(): void
    {
        $code = LlmPromptTemplateService::promptTemplateCode('  Сократи текст  ');

        $this->assertStringStartsWith(LlmPromptTemplateService::PROMPT_TEMPLATE_PREFIX, $code);
        $this->assertLessThanOrEqual(64, strlen($code));
        $this->assertSame(
            LlmPromptTemplateService::promptTemplateCode('Сократи текст'),
            $code,
        );
        $this->assertNotSame(
            LlmPromptTemplateService::promptTemplateCode('Сократи текст.'),
            $code,
        );
    }

    public function testTheFirstSaveCreatesTheRowAndTheSecondOneDoesNot(): void
    {
        $repository = new InMemoryLlmPromptTemplateRepository();
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

    public function testTheListComesFromTheTemplateTable(): void
    {
        $repository = new InMemoryLlmPromptTemplateRepository(['anotherCode' => 'другой шаблон']);
        $service = $this->service($repository);
        $saved = $service->savePromptTemplate('Сократи текст');

        $this->assertSame(
            [
                'anotherCode' => 'другой шаблон',
                $saved['code'] => 'Сократи текст',
            ],
            $service->promptTemplates(),
        );
    }

    public function testAnEmptyOrTooLongTemplateIsRefused(): void
    {
        $repository = new InMemoryLlmPromptTemplateRepository();
        $service = $this->service($repository);

        try {
            $service->savePromptTemplate('   ');
            $this->fail('Пустой шаблон должен отвергаться.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $repository->saves);
        }

        try {
            $service->savePromptTemplate(str_repeat('а', LlmPromptTemplateService::PROMPT_TEMPLATE_MAX_CHARS + 1));
            $this->fail('Слишком длинный шаблон должен отвергаться.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $repository->saves);
        }
    }
}
