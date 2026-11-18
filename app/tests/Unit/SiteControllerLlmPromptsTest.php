<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\controllers\SiteController;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Llm\Service\LlmPromptTemplateService;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Service\ChannelService;
use app\tests\Unit\shared\Llm\Service\InMemoryLlmPromptTemplateRepository;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;
use ReflectionMethod;
use Yii;
use yii\base\Security;
use yii\mail\MailerInterface;
use yii\web\Request;

/**
 * The board of prompts reads each source from its own place: the two
 * editable prompts stay with the settings, the saved ones with the template
 * service, and no row of one side leaks into the other.
 */
final class SiteControllerLlmPromptsTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    /**
     * @param array<string, string> $settings
     * @param array<string, string> $templates
     */
    private function controller(array $settings = [], array $templates = []): SiteController
    {
        $controller = new SiteController(
            'site',
            Yii::$app,
            $this->createMock(MailerInterface::class),
            new Security(),
            new ChannelService(
                $this->createMock(TelegramChannelClientInterface::class),
                self::CHANNEL_ID,
                $this->createMock(PublishedDescriptionRepositoryInterface::class),
            ),
            Yii::createObject(PublicationsService::class),
            $this->createMock(ForumRepositoryInterface::class),
            new PublicationSettingsService(
                new InMemorySettingsRepository($settings),
                new ListedStylesStub([]),
                '*/5 * * * *',
            ),
            null,
            null,
            null,
            null,
            null,
            new LlmPromptTemplateService(new InMemoryLlmPromptTemplateRepository($templates)),
        );
        $controller->request = new Request();

        return $controller;
    }

    /**
     * @return array{settingsPrompts: array<int, array{code: string, label: string, hint: string, text: string}>, templates: array<string, string>}
     */
    private function data(SiteController $controller): array
    {
        /** @var array{settingsPrompts: array<int, array{code: string, label: string, hint: string, text: string}>, templates: array<string, string>} $data */
        $data = (new ReflectionMethod(SiteController::class, 'llmPromptsData'))->invoke($controller);

        return $data;
    }

    public function testTheBoardHoldsTheTwoSettingsPromptsWithTheirLabels(): void
    {
        $data = $this->data($this->controller([
            'llmPromptSpelling' => 'Текст корректора',
            'llmPromptStyle' => 'Текст стилизации',
        ]));

        $this->assertCount(2, $data['settingsPrompts']);
        $this->assertSame('llmPromptSpelling', $data['settingsPrompts'][0]['code']);
        $this->assertSame('Промт проверки орфографии', $data['settingsPrompts'][0]['label']);
        $this->assertSame('Текст корректора', $data['settingsPrompts'][0]['text']);
        $this->assertSame('llmPromptStyle', $data['settingsPrompts'][1]['code']);
        $this->assertSame('Промт стилизации', $data['settingsPrompts'][1]['label']);
        $this->assertSame('Текст стилизации', $data['settingsPrompts'][1]['text']);
    }

    public function testTheBoardShowsOnlySavedTemplatesInTheSecondColumn(): void
    {
        $data = $this->data($this->controller(
            ['llmPromptSpelling' => 'Текст корректора'],
            ['llmPromptTemplate_abc' => 'Шаблон из диалога'],
        ));

        $this->assertSame(['llmPromptTemplate_abc' => 'Шаблон из диалога'], $data['templates']);
    }

    public function testTheBoardWorksWithoutTheTemplateService(): void
    {
        $controller = new SiteController(
            'site',
            Yii::$app,
            $this->createMock(MailerInterface::class),
            new Security(),
            new ChannelService(
                $this->createMock(TelegramChannelClientInterface::class),
                self::CHANNEL_ID,
                $this->createMock(PublishedDescriptionRepositoryInterface::class),
            ),
            Yii::createObject(PublicationsService::class),
            $this->createMock(ForumRepositoryInterface::class),
        );

        $this->assertSame([], $this->data($controller)['templates']);
    }
}
