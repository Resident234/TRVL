<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\controllers\SiteController;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Llm\Dto\LlmChatMessageData;
use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Contract\PublishedDescriptionRepositoryInterface;
use app\shared\Telegram\Contract\TelegramChannelClientInterface;
use app\shared\Telegram\Service\ChannelService;
use app\tests\Unit\shared\Llm\Service\FakeChatHistory;
use app\tests\Unit\shared\Llm\Service\FakeJobQueue;
use app\tests\Unit\shared\Llm\Service\LlmClientStub;
use app\tests\Unit\shared\Llm\Service\StyleSampleFolder;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;
use Yii;
use yii\base\Security;
use yii\mail\MailerInterface;
use yii\web\Request;
use yii\web\Response;

/**
 * The server half of the panel: what the page is told about the storage before it
 * lets the reader press anything, and what the five verbs answer once he has.
 * Nothing here writes a record — the answer of a verb is the text of a part, and
 * the form decides whether it goes into the field.
 */
final class SiteControllerLlmPanelTest extends Unit
{
    private const CHANNEL_ID = '@gsu_travels';

    private const GROUP = '0123456789abcdef0123456789abcdef';

    private const STYLE = 'Ватник в Америке';

    private const KEY = 'sk-ключ-из-строки-базы';

    private const MARKS = '[{"type":"bold","offset":15,"length":8}]';

    private const ANSWER = '{"text":"Ну здравствуй, братишка.","entities":[{"type":"bold","offset":15,"length":8}]}';

    private StyleSampleFolder $_folder;

    private FakeJobQueue $_queue;

    private FakeChatHistory $_history;

    private LlmClientStub $_stub;

    protected function _before(): void
    {
        parent::_before();

        $this->_folder = StyleSampleFolder::create(self::STYLE);
        $this->_queue = new FakeJobQueue();
        $this->_history = new FakeChatHistory();
    }

    protected function _after(): void
    {
        $this->_folder->purge();
    }

    /**
     * The part the panel works on, as the form sends it: the group it invented,
     * the index of the part and its two fields.
     *
     * @return array<string, mixed>
     */
    private function part(string $text = 'Привет мир.', string $formatting = '[]', int $order = 0): array
    {
        return [
            'llmPartGroupId' => self::GROUP,
            'llmPartOrder' => $order,
            'llmText' => $text,
            'llmFormatting' => $formatting,
        ];
    }

    /**
     * The page of a controller built without the services of the panel — the last
     * argument is false there, and the answer must say so instead of a fatal.
     *
     * @param string[] $answers
     * @param array<string, string> $values
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    private function controller(
        array $answers = [],
        array $values = [],
        array $body = [],
        array $query = [],
        bool $wired = true,
    ): SiteController {
        $this->_stub = new LlmClientStub($answers);

        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository(
                $values + ['llmApiKey' => self::KEY, 'activeTextStyle' => self::STYLE],
            ),
            new ListedStylesStub([self::STYLE, 'Совсем другой']),
        );

        $request = new Request();
        $request->setBodyParams($body);
        $request->setQueryParams($query);

        $reader = $this->_folder->reader();

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
            $settings,
            null,
            $wired ? new LlmTextService($this->_stub, $settings, $reader) : null,
            $wired ? $this->_queue : null,
            $wired ? $this->_history : null,
            $wired ? $reader : null,
        );
        $controller->request = $request;

        return $controller;
    }

    /**
     * The payload of a verb, taken at once: asJson() edits the one response of the
     * application, so a second verb would rewrite what the first answered.
     *
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = $response->data;

        return $data;
    }

    public function testThePanelStateSaysWhatTheStorageHolds(): void
    {
        $this->assertSame(
            ['configured' => true, 'style' => self::STYLE, 'readable' => true],
            $this->controller()->llmPanelState(),
        );
    }

    public function testThePanelStateNeverCarriesTheKey(): void
    {
        $state = $this->controller()->llmPanelState();

        $this->assertTrue($state['configured']);
        $this->assertFalse(in_array(self::KEY, $state, true));
        $this->assertStringNotContainsString(
            self::KEY,
            (string) json_encode($state, JSON_UNESCAPED_UNICODE),
        );
    }

    public function testThePanelStateCallsAStyleWithoutItsFolderUnreadable(): void
    {
        $state = $this->controller([], ['activeTextStyle' => 'Совсем другой'])->llmPanelState();

        $this->assertSame('Совсем другой', $state['style']);
        $this->assertTrue($state['configured']);
        $this->assertFalse($state['readable']);
    }

    public function testThePanelStateCallsAnEmptyStyleUnreadable(): void
    {
        $state = $this->controller([], ['activeTextStyle' => ''])->llmPanelState();

        $this->assertSame('', $state['style']);
        $this->assertFalse($state['readable']);
    }

    public function testThePanelStateOfAFolderWhoseSampleWasDeleted(): void
    {
        unlink($this->_folder->path . '/Ватник в Америке/result.json');

        $this->assertFalse($this->controller()->llmPanelState()['readable']);
    }

    public function testThePanelWithoutTheWiringRefusesThePageAndTheVerb(): void
    {
        $controller = $this->controller([], [], [], [], false);

        $this->assertFalse($controller->llmPanelState()['configured']);

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertSame('За ИИ ничего не заведено.', $data['error']);
    }

    public function testSpellingWritesTheAnswerAndTheMarksIntoThePart(): void
    {
        $controller = $this->controller([self::ANSWER], [], $this->part('Привет мир.', '[]', 2));

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertTrue($data['ok']);
        $this->assertSame(2, $data['partOrder']);
        $this->assertSame('Ну здравствуй, братишка.', $data['text']);
        $this->assertSame(self::MARKS, $data['formatting']);
        $this->assertSame(1, $this->_stub->calls);
    }

    public function testSpellingRefusesAnEmptyPart(): void
    {
        $controller = $this->controller([self::ANSWER], [], $this->part("  \n"));

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertSame('Часть пуста — править нечего.', $data['error']);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testSpellingRefusesWithoutAStoredKey(): void
    {
        $controller = $this->controller([self::ANSWER], ['llmApiKey' => ''], $this->part());

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertSame('Нет ключа или адреса прокси — ИИ не настроен.', $data['error']);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testSpellingAnswersTheRefusalOfTheProxy(): void
    {
        $controller = $this->controller(['текст, а не JSON'], [], $this->part());

        $data = $this->data($controller->actionLlmSpelling());

        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('не разбирается', $data['error']);
    }

    public function testStyleQueuesTheChosenStyleAndAnswersItsNumber(): void
    {
        $controller = $this->controller([], [], $this->part('Привет мир.', '[]', 1));

        $data = $this->data($controller->actionLlmStyle());

        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['jobId']);
        $this->assertSame(1, $data['partOrder']);
        $this->assertSame(self::STYLE, $data['style']);
        $this->assertSame(['enqueue'], $this->_queue->calls);
        $this->assertSame(0, $this->_stub->calls);

        $row = $this->_queue->queued[0];
        $this->assertSame(LlmJobData::QUEUED, $row->status);
        $this->assertSame(LlmJobData::KIND_STYLE, $row->kind);
        $this->assertSame(self::GROUP, $row->partGroupId);
        $this->assertSame(1, $row->partOrder);
        $this->assertSame(self::STYLE, $row->styleName);
        $this->assertSame('Привет мир.', $row->sourceText);
        $this->assertSame('[]', $row->sourceFormatting);
    }

    public function testStyleRefusesWhenNoStyleIsChosen(): void
    {
        $controller = $this->controller([], ['activeTextStyle' => ''], $this->part());

        $data = $this->data($controller->actionLlmStyle());

        $this->assertFalse($data['ok']);
        $this->assertSame('Стиль не выбран, стилизовать нечем.', $data['error']);
        $this->assertSame([], $this->_queue->calls);
    }

    public function testStyleRefusesWhenTheFolderOfTheStyleIsGone(): void
    {
        unlink($this->_folder->path . '/Ватник в Америке/result.json');

        $controller = $this->controller([], [], $this->part());

        $data = $this->data($controller->actionLlmStyle());

        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('Ватник в Америке', $data['error']);
        $this->assertSame([], $this->_queue->calls);
    }

    public function testStyleRefusesAGroupThatIsNotNamed(): void
    {
        $body = $this->part();
        $body['llmPartGroupId'] = 'не-тридцать-два-символа';

        $data = $this->data($this->controller([], [], $body)->actionLlmStyle());

        $this->assertFalse($data['ok']);
        $this->assertSame('Группа частей не названа.', $data['error']);
        $this->assertSame([], $this->_queue->calls);
    }

    public function testStatusAnswersTheRowOfTheQueue(): void
    {
        $this->_queue->queued[] = new LlmJobData(
            7,
            LlmJobData::DONE,
            LlmJobData::KIND_STYLE,
            self::GROUP,
            0,
            self::STYLE,
            'Привет мир.',
            '[]',
            'Ну здравствуй, братишка.',
            self::MARKS,
        );

        $controller = $this->controller([], [], [], ['llmJobId' => '7']);

        $data = $this->data($controller->actionLlmStyleStatus());

        $this->assertTrue($data['ok']);
        $this->assertSame('done', $data['status']);
        $this->assertSame('Ну здравствуй, братишка.', $data['text']);
        $this->assertSame(self::MARKS, $data['formatting']);
        $this->assertNull($data['error']);
    }

    public function testStatusSaysWhenThereIsNoSuchJob(): void
    {
        $controller = $this->controller([], [], [], ['llmJobId' => '99']);

        $data = $this->data($controller->actionLlmStyleStatus());

        $this->assertFalse($data['ok']);
        $this->assertSame('Задачи очереди с номером 99 нет.', $data['error']);
    }

    public function testStatusRefusesAnUnnumberedAsk(): void
    {
        $data = $this->data($this->controller()->actionLlmStyleStatus());

        $this->assertFalse($data['ok']);
        $this->assertSame('Задача очереди не названа.', $data['error']);
    }

    public function testChatKeepsTheCommandAndTheAnswerInTheHistory(): void
    {
        $body = array_merge($this->part(), ['llmCommand' => 'сократи текст']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $data = $this->data($controller->actionLlmChat());

        $this->assertTrue($data['ok']);
        $this->assertSame('Ну здравствуй, братишка.', $data['text']);
        $this->assertSame(self::MARKS, $data['formatting']);
        $this->assertSame(1, $data['userId']);
        $this->assertSame(2, $data['assistantId']);

        $this->assertSame(['user', 'assistant'], $this->_history->appended);
        $this->assertSame('сократи текст', $this->_history->rows[0]->content);
        $this->assertNull($this->_history->rows[0]->replacementText);
        $this->assertSame('Ну здравствуй, братишка.', $this->_history->rows[1]->replacementText);
        $this->assertSame(self::MARKS, $this->_history->rows[1]->replacementFormatting);
    }

    public function testChatReadsTheWindowBeforeItWritesTheCommand(): void
    {
        $body = array_merge($this->part(), ['llmCommand' => 'сократи текст']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $controller->actionLlmChat();

        $this->assertSame(['latest', 'append', 'append'], $this->_history->calls);
        $this->assertSame(LlmTextService::HISTORY_SHOWN, $this->_history->lastLimit);
    }

    public function testChatShowsTheModelTheCommandOnlyOnce(): void
    {
        $this->_history->rows[] = new LlmChatMessageData(
            1,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_ASSISTANT,
            'Прошлый ответ.',
        );

        $body = array_merge($this->part(), ['llmCommand' => 'сократи текст']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $controller->actionLlmChat();

        $user = $this->_stub->seen[0][1]['content'];

        $this->assertSame(1, substr_count($user, 'сократи текст'));
        $this->assertStringContainsString('Прошлый ответ.', $user);
    }

    public function testChatRefusesAnEmptyCommand(): void
    {
        $body = array_merge($this->part(), ['llmCommand' => '   ']);
        $controller = $this->controller([self::ANSWER], [], $body);

        $data = $this->data($controller->actionLlmChat());

        $this->assertFalse($data['ok']);
        $this->assertSame('Команда не написана.', $data['error']);
        $this->assertSame([], $this->_history->calls);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testHistoryAnswersTheRowsOfThePart(): void
    {
        $this->_history->rows[] = new LlmChatMessageData(
            1,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_USER,
            'сократи текст',
        );
        $this->_history->rows[] = new LlmChatMessageData(
            2,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_ASSISTANT,
            'Коротко.',
            'Коротко.',
            self::MARKS,
        );

        $controller = $this->controller([], [], [], [
            'llmPartGroupId' => self::GROUP,
            'llmPartOrder' => '0',
        ]);

        $data = $this->data($controller->actionLlmChatHistory());

        /** @var array<int, array<string, mixed>> $messages */
        $messages = $data['messages'];
        $keys = array_keys($messages[1]);

        $this->assertTrue($data['ok']);
        $this->assertCount(2, $messages);
        $this->assertSame(
            ['id', 'role', 'content', 'replacementText', 'replacementFormatting', 'createdAt'],
            $keys,
        );
        $this->assertSame('Коротко.', $messages[1]['content']);
        $this->assertSame(self::MARKS, $messages[1]['replacementFormatting']);
    }

    public function testHistoryWorksAfterTheKeyIsGone(): void
    {
        $this->_history->rows[] = new LlmChatMessageData(
            1,
            self::GROUP,
            0,
            LlmChatMessageData::ROLE_USER,
            'сократи текст',
        );

        $controller = $this->controller([], ['llmApiKey' => ''], [], [
            'llmPartGroupId' => self::GROUP,
            'llmPartOrder' => '0',
        ]);

        $data = $this->data($controller->actionLlmChatHistory());

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['messages']);
    }
}
