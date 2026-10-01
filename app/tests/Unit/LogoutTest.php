<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\controllers\SiteController;
use app\models\User;
use app\shared\Telegram\Service\ChannelService;
use Yii;
use yii\base\Security;
use yii\web\IdentityInterface;
use yii\web\View;

final class LogoutTest extends \Codeception\Test\Unit
{
    public function testRenderLogoutLinkWhenUserIsLoggedIn(): void
    {
        $user = User::findIdentity('100');

        $controller = new SiteController(
            'site',
            Yii::$app,
            Yii::$app->mailer,
            new Security(),
            new ChannelService(
                new class implements \app\shared\Telegram\Contract\TelegramChannelClientInterface {
                    public function getChannelInfo(string $channelId): \app\shared\Telegram\Dto\ChannelInfo
                    {
                        throw new \RuntimeException('not needed in this test');
                    }

                    public function setChannelDescription(string $channelId, string $description): void
                    {
                    }

                    public function sendTextMessage(
                        string $channelId,
                        string $text,
                        \app\shared\Telegram\Dto\MessageEntities $entities = new \app\shared\Telegram\Dto\MessageEntities(),
                        \app\shared\Telegram\Dto\LinkButtons $buttons = new \app\shared\Telegram\Dto\LinkButtons(),
                    ): \app\shared\Telegram\Dto\PostResult {
                        throw new \RuntimeException('not needed in this test');
                    }

                    public function sendPhotoMessage(
                        string $channelId,
                        string $photoPath,
                        string $caption,
                        \app\shared\Telegram\Dto\MessageEntities $entities = new \app\shared\Telegram\Dto\MessageEntities(),
                        \app\shared\Telegram\Dto\LinkButtons $buttons = new \app\shared\Telegram\Dto\LinkButtons(),
                    ): \app\shared\Telegram\Dto\PostResult {
                        throw new \RuntimeException('not needed in this test');
                    }

                    public function sendPhotoGroupMessage(
                        string $channelId,
                        array $photoUrls,
                        string $caption,
                        \app\shared\Telegram\Dto\MessageEntities $entities = new \app\shared\Telegram\Dto\MessageEntities(),
                        \app\shared\Telegram\Dto\LinkButtons $buttons = new \app\shared\Telegram\Dto\LinkButtons(),
                    ): \app\shared\Telegram\Dto\PostResult {
                        throw new \RuntimeException('not needed in this test');
                    }

                    public function pinChannelMessage(string $channelId, int $messageId): void
                    {
                    }

                    public function deleteChannelMessage(string $channelId, int $messageId): void
                    {
                    }

                    public function editChannelMessageText(
                        string $channelId,
                        int $messageId,
                        string $text,
                        \app\shared\Telegram\Dto\MessageEntities $entities = new \app\shared\Telegram\Dto\MessageEntities(),
                        \app\shared\Telegram\Dto\LinkButtons $buttons = new \app\shared\Telegram\Dto\LinkButtons(),
                    ): void {
                    }
                },
                '@gsu_travels',
                new \app\shared\Telegram\Infrastructure\PublishedDescriptionRepository(\Yii::$app->getDb()),
            ),
            \Yii::createObject(\app\shared\Publications\Service\PublicationsService::class),
            new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
        );

        $view = new View(['context' => $controller]);

        self::assertNotNull(
            $user,
            "Failed asserting that the user identity with ID '100' exists.",
        );
        self::assertInstanceOf(
            IdentityInterface::class,
            $user,
            "Failed asserting that the identity is an instance of 'Identity' class.",
        );

        Yii::$app->user->login($user);

        $html = $view->render('//layouts/main.php', ['content' => 'Hello World°']);

        self::assertStringContainsString(
            'Logout (admin)',
            $html,
            'Failed asserting that the logout link is rendered for a logged-in user.',
        );
        self::assertStringContainsString(
            'data-method="post"',
            $html,
            'Failed asserting that the logout link uses POST method.',
        );

        $controller->actionLogout();

        $html = $view->render('//layouts/main.php', ['content' => 'Hello World°']);

        self::assertStringNotContainsString(
            'Logout (admin)',
            $html,
            'Failed asserting that the logout link is not rendered after logout.',
        );
    }
}
