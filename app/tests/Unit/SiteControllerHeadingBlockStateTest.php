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
 * The four boxes of the heading block stand with one state of the form, so the
 * page keeps it in the session like its neighbours and the address of the page
 * mirrors it. A state of four boxes is a list, and a list reaches the controller
 * in three shapes: the session holds the list itself, the address and the post of
 * the block carry the names joined by a comma, and a state that was never given at
 * all answers the single bold mark every heading went out with before the block
 * existed. An empty string is not that state — it is the reader taking every box
 * off, and the address has to say so, or a reload would quietly put the bold back.
 */
final class SiteControllerHeadingBlockStateTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    /**
     * Every state the publications page keeps, so a test reads the whole address
     * of a form that was never asked instead of the address a neighbour test left.
     */
    private const SESSION_KEYS = [
        'forumFilters',
        'publicationsSort',
        'forumTextOrder',
        'titleFromFirstLine',
        'linksToButtons',
        'albumRows',
        'partsPublication',
        'distributeImages',
        'numberParts',
        'headingNumberTemplate',
        'headingNumberCustom',
        'headingFormatting',
    ];

    private ?Controller $_activeController = null;

    protected function _before(): void
    {
        $session = Yii::$app->session;

        foreach (self::SESSION_KEYS as $key) {
            $session->remove($key);
        }

        // A relative route is built against the controller that is running, and a
        // unit test has none until one is put there.
        $this->_activeController = Yii::$app->controller;
        Yii::$app->controller = new Controller('site', Yii::$app);
    }

    protected function _after(): void
    {
        Yii::$app->controller = $this->_activeController;
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
     * A state as it comes from the session, from the address or from the post.
     *
     * @return string[]
     */
    private function marks(mixed $state): array
    {
        /** @var string[] $marks */
        $marks = $this->ask($this->controller(), 'normalizeHeadingFormatting', $state);

        return $marks;
    }

    /**
     * @return string[]
     */
    private function state(): array
    {
        /** @var string[] $marks */
        $marks = $this->ask($this->controller(), 'headingFormatting');

        return $marks;
    }

    /**
     * The marks the address of the page asks for.
     *
     * @param array<string, mixed> $query
     *
     * @return string[]
     */
    private function stateFromQuery(array $query): array
    {
        /** @var string[] $marks */
        $marks = $this->ask($this->controller(), 'headingFormattingFromQuery', $query);

        return $marks;
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

    public function testAStateTheSessionNeverHeldStandsWithTheBoldMark(): void
    {
        $this->assertSame(
            ['bold'],
            $this->state(),
            'Every heading went out bold before the block existed, so a form that was never '
            . 'asked answers with the one box that used to be implicit.',
        );
    }

    public function testAStateTheAddressNeverAskedStandsWithTheBoldMark(): void
    {
        $this->assertSame(['bold'], $this->stateFromQuery([]));
    }

    public function testTheMarksOfACommaStringComeBackInTheOrderOfTheBoxes(): void
    {
        $this->assertSame(
            ['bold', 'italic'],
            $this->marks('italic,bold'),
            'Two addresses that say the same marks are one state, so the list is read in the '
            . 'order of the boxes and not in the order it was typed in.',
        );
    }

    public function testAHeadingWithoutMarksIsAStateTheReaderChose(): void
    {
        $this->assertSame(
            [],
            $this->marks(''),
            'The post of the block always carries its parameter, and an empty one says every '
            . 'box was taken off — not that nothing was ever said.',
        );
    }

    public function testAMarkTheBlockHasNoBoxForIsDropped(): void
    {
        $this->assertSame(['bold'], $this->marks('bold,code'));
    }

    public function testAMarkSaidTwiceStandsOnce(): void
    {
        $this->assertSame(['bold'], $this->marks('bold,bold'));
    }

    public function testANameSpacedAroundIsStillTheNameOfABox(): void
    {
        $this->assertSame(['bold', 'italic'], $this->marks('bold, italic'));
    }

    public function testAStateThatIsNeitherANameNorAListIsNotAState(): void
    {
        $this->assertSame(
            ['bold'],
            $this->marks(42),
            'Only the boxes of the block can reach the session, and a state that is not one of '
            . 'them answers the shape the page had before any of them existed.',
        );
    }

    public function testTheSessionStateIsReadBackAsItWasLeft(): void
    {
        Yii::$app->session->set('headingFormatting', ['italic', 'strike']);

        $this->assertSame(['italic', 'strike'], $this->state());
    }

    public function testTheAddressOfAFreshFormChoosesTheMarksItStandsWith(): void
    {
        $controller = $this->controller([], ['headingFormatting' => 'strike']);

        $this->ask($controller, 'syncHeadingFormatting');

        $this->assertSame(['strike'], $this->state());
    }

    public function testAFormThatAlreadyStandsWithMarksIgnoresTheAddress(): void
    {
        Yii::$app->session->set('headingFormatting', ['bold']);
        $controller = $this->controller([], ['headingFormatting' => 'strike']);

        $this->ask($controller, 'syncHeadingFormatting');

        $this->assertSame(
            ['bold'],
            $this->state(),
            'Reloading a page cannot switch a box back on behind the reader.',
        );
    }

    public function testTheAddressOfTheDefaultMarksSaysNothing(): void
    {
        $this->assertArrayNotHasKey(
            'headingFormatting',
            $this->url(),
            'The bold mark is the shape every address was written with before the block existed.',
        );
    }

    public function testTheAddressOfTheBlockSaysTheMarksItStandsWith(): void
    {
        Yii::$app->session->set('headingFormatting', ['italic', 'underline']);

        $this->assertSame('italic,underline', $this->url()['headingFormatting']);
    }

    public function testTheAddressOfAHeadingWithoutMarksSaysSo(): void
    {
        Yii::$app->session->set('headingFormatting', []);

        $this->assertSame(
            '',
            $this->url()['headingFormatting'],
            'An empty list is a state of the block, so it needs a parameter of its own — the '
            . 'absence of one answers the bold mark.',
        );
    }

    public function testAnAddressThatSaysOtherMarksThanTheSessionIsCaught(): void
    {
        $this->assertTrue($this->disagrees(['headingFormatting' => '']));
    }

    public function testTheAddressTheSessionDescribesIsNotCaught(): void
    {
        $this->assertFalse($this->disagrees([]));
    }

    public function testAnAddressOfNoMarksAgreesWithASessionOfNoMarks(): void
    {
        Yii::$app->session->set('headingFormatting', []);

        $this->assertFalse($this->disagrees(['headingFormatting' => '']));
    }

    public function testTheSaveActionKeepsTheMarksItWasPosted(): void
    {
        $this->controller(['headingFormatting' => 'italic,strike'])->actionHeadingFormattingSave();

        $this->assertSame(['italic', 'strike'], $this->state());
    }

    public function testTheSaveActionAnswersWithTheAddressThatMirrorsThem(): void
    {
        $response = $this->controller(['headingFormatting' => 'italic,strike'])
            ->actionHeadingFormattingSave();

        /** @var array<string, string> $data */
        $data = $response->data;
        $this->assertStringContainsString('headingFormatting=italic%2Cstrike', $data['url']);
    }

    public function testTheSaveActionKeepsAnEmptyPostAsNoMarks(): void
    {
        $response = $this->controller(['headingFormatting' => ''])->actionHeadingFormattingSave();

        /** @var array<string, string> $data */
        $data = $response->data;
        $this->assertSame([], $this->state());
        $this->assertStringContainsString('headingFormatting=', $data['url']);
    }
}
