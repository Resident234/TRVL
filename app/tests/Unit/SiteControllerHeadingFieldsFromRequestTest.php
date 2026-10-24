<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\controllers\SiteController;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Service\ChannelService;
use Codeception\Test\Unit;
use ReflectionMethod;
use Yii;
use yii\base\Security;
use yii\mail\MailerInterface;
use yii\web\Request;

/**
 * The heading of a part answers the submission in the shape the markup writes
 * it in: its own text comes under a plain name, so the browser brings one
 * string, while its marks come under a bracketed name, the way the marks of the
 * body do, so the browser brings a list of the one field. A read that treats
 * both names as plain brings the heading safely home and drops its marks in
 * silence — the road this class watches, since everything below the controller
 * is handed the lists the controller has already chosen.
 */
final class SiteControllerHeadingFieldsFromRequestTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    private const FIRST_FIELD = '[{"type":"bold","offset":0,"length":4}]';

    private const FIRST_MARKS = [['type' => 'bold', 'offset' => 0, 'length' => 4]];

    private const SECOND_FIELD = '[{"type":"italic","offset":2,"length":3}]';

    private const SECOND_MARKS = [['type' => 'italic', 'offset' => 2, 'length' => 3]];

    private function controller(array $body): SiteController
    {
        $request = new Request();
        $request->setBodyParams($body);

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

    /**
     * @param array<string, mixed> $body
     *
     * @return MessageEntities[]
     */
    private function titleMarks(array $body, int $parts): array
    {
        $read = new ReflectionMethod(SiteController::class, 'publicationTitleFormatsFromRequest');

        return $read->invoke($this->controller($body), $parts);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return string[]
     */
    private function titles(array $body, int $parts): array
    {
        $read = new ReflectionMethod(SiteController::class, 'publicationTitlesFromRequest');

        return $read->invoke($this->controller($body), $parts);
    }

    public function testTheMarksOfTheFirstHeadingComeOutOfTheBracketedField(): void
    {
        $marks = $this->titleMarks(['publicationTitleFormatting' => [self::FIRST_FIELD]], 1);

        $this->assertSame(
            self::FIRST_MARKS,
            $marks[0]->toArray(),
            'The first part is served by the shared field, and the browser brings that '
            . 'field as a list holding it alone.',
        );
    }

    public function testTheMarksOfEveryHeadingComeOutOfItsOwnField(): void
    {
        $marks = $this->titleMarks(
            [
                'publicationTitleFormatting' => [self::FIRST_FIELD],
                'publicationPartTitleFormatting' => [self::SECOND_FIELD],
            ],
            2,
        );

        $this->assertSame(
            [self::FIRST_MARKS, self::SECOND_MARKS],
            [$marks[0]->toArray(), $marks[1]->toArray()],
            'A part the form cloned answers under its own name, in the place of that part.',
        );
    }

    public function testAHeadingThatBroughtNoFieldOfItsOwnComesBackWithoutMarks(): void
    {
        $marks = $this->titleMarks([], 2);

        $this->assertSame([[], []], [$marks[0]->toArray(), $marks[1]->toArray()]);
    }

    public function testAHeadingMarkedWithNothingStillComesBackWithNothing(): void
    {
        $marks = $this->titleMarks(['publicationTitleFormatting' => ['[]']], 1);

        $this->assertSame([], $marks[0]->toArray());
    }

    public function testTheHeadingsThemselvesStillComeInOnePerPart(): void
    {
        $titles = $this->titles(
            [
                'publicationTitle' => 'Первая часть',
                'publicationPartTitle' => ['Вторая часть'],
            ],
            2,
        );

        $this->assertSame(
            ['Первая часть', 'Вторая часть'],
            $titles,
            'The heading of the first part wears no brackets, so the shared field that '
            . 'carries it stays the single string it has always been.',
        );
    }

    public function testAHeadingThatBroughtNoTextOfItsOwnComesBackEmpty(): void
    {
        $titles = $this->titles(['publicationPartTitle' => ['Вторая часть']], 2);

        $this->assertSame('', $titles[0], 'A part whose shared field is missing is read as no heading.');
    }
}
