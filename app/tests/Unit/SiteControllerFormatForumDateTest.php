<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\controllers\SiteController;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Service\ChannelService;
use Codeception\Test\Unit;
use ReflectionMethod;
use Yii;
use yii\base\Security;
use yii\mail\MailerInterface;
use yii\web\Request;

/**
 * A topic and a post of the same discussion must print their date the same
 * way. Both rows keep UTC in the database, and the page has one format for
 * every date, so the stamp is converted once, in the controller.
 */
final class SiteControllerFormatForumDateTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    private function controller(): SiteController
    {
        $request = new Request();

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
        $controller->request = $request;

        return $controller;
    }

    private function format(?string $utc): string
    {
        /** @var string $value */
        $value = (new ReflectionMethod(SiteController::class, 'formatForumDate'))
            ->invoke($this->controller(), $utc);

        return $value;
    }

    public function testAForumRowIsPrintedInThePortalFormat(): void
    {
        $this->assertSame('12.03.2008 20:51', $this->format('2008-03-12 20:51:00'));
    }

    public function testAnEmptyStampStaysEmpty(): void
    {
        $this->assertSame('', $this->format(null));
        $this->assertSame('', $this->format(''));
    }

    public function testAnUnreadableStampIsShownAsItCame(): void
    {
        $this->assertSame('12.03.2008', $this->format('12.03.2008'));
    }
}
