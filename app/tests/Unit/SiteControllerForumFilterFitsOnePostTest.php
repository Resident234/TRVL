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
use yii\web\Controller;
use yii\web\Request;

/**
 * The new filter of the forum block follows the same path as its four
 * neighbours: the session is the state, the address is its mirror, and the
 * character bound handed to the repository is the channel's own limit.
 */
final class SiteControllerForumFilterFitsOnePostTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    private ?Controller $_activeController = null;

    protected function _before(): void
    {
        Yii::$app->session->remove('forumFilters');

        $this->_activeController = Yii::$app->controller;
        Yii::$app->controller = new Controller('site', Yii::$app);
    }

    protected function _after(): void
    {
        Yii::$app->controller = $this->_activeController;
        Yii::$app->session->remove('forumFilters');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    private function controller(array $body = [], array $query = []): SiteController
    {
        $request = new Request();
        $request->setBodyParams($body);
        $request->setQueryParams($query);

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

    private function ask(SiteController $controller, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(SiteController::class, $method))->invoke($controller, ...$args);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->ask($this->controller(), 'normalizeForumFilters', $raw);

        return $filters;
    }

    /**
     * @return array<int|string, string>
     */
    private function url(): array
    {
        /** @var array<int|string, string> $url */
        $url = $this->ask($this->controller(), 'publicationsUrl');

        return $url;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function disagrees(array $query): bool
    {
        return $this->ask($this->controller(), 'viewStateDisagreesWithSession', $query) === true;
    }

    public function testTheFilterIsOffUntilTheAddressAsksForIt(): void
    {
        $this->assertFalse($this->normalize([])['fitsOnePost']);
        $this->assertTrue($this->normalize(['fitsOnePost' => '1'])['fitsOnePost']);
        $this->assertFalse($this->normalize(['fitsOnePost' => '0'])['fitsOnePost']);
    }

    public function testThisFilterAloneMakesTheSessionActive(): void
    {
        $filters = $this->normalize(['fitsOnePost' => '1']);

        $this->assertTrue($this->ask($this->controller(), 'hasActiveForumFilter', $filters));
    }

    public function testTheAddressMirrorsTheSession(): void
    {
        $this->assertArrayNotHasKey('fitsOnePost', $this->url());

        Yii::$app->session->set('forumFilters', ['fitsOnePost' => '1']);

        $this->assertSame('1', $this->url()['fitsOnePost']);
    }

    public function testAnAddressThatDisagreesWithTheSessionIsCaught(): void
    {
        $this->assertTrue($this->disagrees(['fitsOnePost' => '1']));

        Yii::$app->session->set('forumFilters', ['fitsOnePost' => '1']);

        $this->assertFalse($this->disagrees(['fitsOnePost' => '1']));
        $this->assertTrue($this->disagrees([]));
    }

    public function testTheBoundIsTheChannelLimitOnlyWhileTheFilterIsOn(): void
    {
        $this->assertSame(0, $this->ask($this->controller(), 'forumTextLimit'));

        Yii::$app->session->set('forumFilters', ['fitsOnePost' => '1']);

        $this->assertSame(ChannelService::TEXT_MAX_LENGTH, $this->ask($this->controller(), 'forumTextLimit'));
    }
}
