<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\controllers\SiteController;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Llm\Service\LlmPromptTemplateService;
use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Service\ChannelService;
use app\tests\Unit\shared\Llm\Service\InMemoryLlmPromptTemplateRepository;
use Codeception\Test\Unit;
use Yii;
use yii\base\Security;
use yii\mail\MailerInterface;
use yii\web\Request;
use yii\web\Response;

/**
 * Saving a line of the dialog as a prompt template is a local write and does
 * not need the model: the same action answers even when the proxy is dark.
 */
final class SiteControllerLlmTemplateSaveTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    private function controller(InMemoryLlmPromptTemplateRepository $repository): SiteController
    {
        $request = new Request();
        $request->setBodyParams(['llmCommand' => 'Сократи текст']);

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
            null,
            null,
            null,
            null,
            null,
            null,
            new LlmPromptTemplateService($repository),
        );
        $controller->request = $request;

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = $response->data;

        return $data;
    }

    public function testTheFirstSaveWritesATemplateAndTheSecondOneIsANoOp(): void
    {
        $repository = new InMemoryLlmPromptTemplateRepository();
        $controller = $this->controller($repository);

        $first = $this->data($controller->actionLlmTemplateSave());
        $second = $this->data($this->controller($repository)->actionLlmTemplateSave());

        $this->assertTrue($first['ok']);
        $this->assertTrue($first['created']);
        $this->assertTrue($second['ok']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['code'], $second['code']);
        $this->assertSame('Сократи текст', $repository->values[$first['code']]);
    }

    public function testAnEmptyLineIsRefused(): void
    {
        $repository = new InMemoryLlmPromptTemplateRepository();
        $controller = $this->controller($repository);
        $controller->request->setBodyParams(['llmCommand' => '   ']);

        $data = $this->data($controller->actionLlmTemplateSave());

        $this->assertFalse($data['ok']);
        $this->assertSame('Текст шаблона не написан.', $data['error']);
    }
}
