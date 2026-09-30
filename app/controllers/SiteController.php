<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\models\ContactForm;
use app\models\LoginForm;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Forum\Service\ParserSettingsService;
use app\shared\Publications\Dto\ForumPublicationRef;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Infrastructure\TelegramApiException;
use app\shared\Telegram\Service\ChannelService;
use app\widgets\Alert;
use yii\captcha\CaptchaAction;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\base\Security;
use yii\helpers\FileHelper;
use yii\helpers\Url;
use yii\mail\MailerInterface;
use yii\web\Controller;
use yii\web\ErrorAction;
use yii\web\Response;
use yii\web\UploadedFile;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SiteController extends Controller
{
    /**
     * The orders a switch of the page can reverse. Every name is both a key of
     * the stored state and the parameter of the address (`postsOldest`), and
     * says which block has to be redrawn when it switches: the forum block
     * reads its topics and its posts in an order of their own.
     */
    private const PUBLICATIONS_SORT_BLOCKS = [
        'posts' => 'posts',
        'drafts' => 'drafts',
        'deleted' => 'deleted',
        'forumTopics' => 'forum',
        'forumPosts' => 'forum',
    ];

    /**
     * Where the files the publication form picks from a computer are kept:
     * under the web root, so the album of a part holds a plain public link to
     * the picture exactly as it does when the link was typed in by hand.
     */
    private const UPLOAD_DIR = 'uploads/publications';

    /**
     * The image formats a publication can carry: the type is read out of the
     * file itself, and the extension of the stored copy comes from it, not from
     * what the browser chose to name the file.
     */
    private const UPLOAD_IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function __construct(
        $id,
        $module,
        private readonly MailerInterface $mailer,
        private readonly Security $security,
        private readonly ChannelService $telegramChannel,
        private readonly PublicationsService $publications,
        private readonly ForumRepositoryInterface $forum,
        private readonly ?PublicationSettingsService $settings = null,
        private readonly ?ParserSettingsService $parserSettings = null,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * The tunables of the publications page as the settings page stores them.
     *
     * A block partial is rendered one record at a time and receives nothing but
     * that record, so it asks the controller for a number instead of carrying
     * it through every render call — exactly like it asks for the timezone of
     * the viewer. A controller built without the storage keeps the defaults of
     * the schema.
     *
     * @return array<string, string>
     */
    public function publicationSettings(): array
    {
        return $this->settings !== null ? $this->settings->all() : PublicationSettingsService::defaults();
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                    'publication-create' => ['post'],
                    'publication-to-draft' => ['post'],
                    'publication-publish' => ['post'],
                    'publication-delete' => ['post'],
                    'publication-schedule' => ['post'],
                    'publication-page' => ['post'],
                    'publication-sort' => ['post'],
                    'forum-viewed' => ['post'],
                    'forum-post-page' => ['post'],
                    'forum-thread' => ['post'],
                    'settings-save' => ['post'],
                    'parser-settings-save' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions(): array
    {
        return [
            'error' => [
                'class' => ErrorAction::class,
            ],
            'captcha' => [
                'class' => CaptchaAction::class,
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
                'transparent' => true,
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex(): string
    {
        $this->layout = 'dashboard';

        $channelConnected = false;
        try {
            $this->telegramChannel->channelInfo();
            $channelConnected = true;
        } catch (Throwable) {
            // Token is not configured yet or Telegram API is unreachable:
            // the dashboard still renders with the placeholder state.
        }

        return $this->render('index', [
            'channelConnected' => $channelConnected,
        ]);
    }

    /**
     * Displays the settings page: the description of the channel the posts go
     * to, the tunables of the publications page, and the protocol limits the
     * portal does not own.
     *
     * @return string
     */
    public function actionSettings(): string
    {
        $this->layout = 'dashboard';

        $channelDescription = null;
        try {
            $channelDescription = $this->telegramChannel->channelInfo()->description;
        } catch (Throwable) {
            // Token is not configured yet or Telegram API is unreachable:
            // the page still renders with the placeholder state.
        }

        $publishedDescriptions = $this->telegramChannel->publishedDescriptions();

        return $this->render('settings', [
            'channelDescription' => $channelDescription,
            'publishedDescriptions' => $publishedDescriptions,
            'settings' => $this->publicationSettings(),
            'partsOffsetMin' => $this->settings !== null ? $this->settings->minimumPartsOffsetMinutes() : 1,
            'cronSchedule' => $this->settings !== null ? $this->settings->cronSchedule() : '',
        ]);
    }

    /**
     * Stores the tunables of the publications page.
     *
     * @return Response
     */
    public function actionSettingsSave(): Response
    {
        try {
            $this->settings->save((array)$this->request->post('settings', []));
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());

            return $this->redirect(['settings']);
        }

        Yii::$app->session->setFlash('success', 'Настройки публикаций сохранены.');

        return $this->redirect(['settings']);
    }

    /**
     * Displays the settings page of the parsers: how the scan asks the forum
     * for a page and which ranges of entity ids it walks.
     *
     * @return string
     */
    public function actionParserSettings(): string
    {
        $this->layout = 'dashboard';

        return $this->render('parser-settings', [
            'tunables' => $this->parserSettings->tunables(),
            'rows' => $this->parserSettings->rows(),
        ]);
    }

    /**
     * Stores the tunables of the parsers together with the entity ranges.
     *
     * @return Response
     */
    public function actionParserSettingsSave(): Response
    {
        try {
            $this->parserSettings->save(
                (array)$this->request->post('tunables', []),
                (array)$this->request->post('rows', []),
            );
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());

            return $this->redirect(['parser-settings']);
        }

        Yii::$app->session->setFlash('success', 'Настройки парсера сохранены.');

        return $this->redirect(['parser-settings']);
    }

    /**
     * Displays the channel publications page. The session holds the forum
     * filters and the reading order of the three lists, and the address bar is
     * kept as their mirror image, so a page that was opened on a URL
     * disagreeing with the session is redirected onto the address the session
     * describes.
     *
     * @return Response|string
     */
    public function actionPublications(): Response|string
    {
        $this->layout = 'dashboard';

        $this->syncForumFilters();
        $this->syncPublicationsSort();
        if ($this->normalizeForumFilters($this->request->get()) !== $this->forumFilters()
            || $this->publicationsSortFromQuery($this->request->get()) !== $this->publicationsSort()) {
            return $this->redirect($this->publicationsUrl());
        }

        return $this->render('publications', $this->publicationsData());
    }

    /**
     * Resolves the effective forum filters. A filter that is already active in
     * the session wins, so reloading or navigating cannot switch it back on;
     * only an empty session adopts the query parameters of the request.
     */
    private function syncForumFilters(): void
    {
        $session = Yii::$app->session;
        $stored = $this->normalizeForumFilters((array)$session->get('forumFilters', []));

        if ($this->hasActiveForumFilter($stored)) {
            $session->set('forumFilters', $stored);
            return;
        }

        $session->set('forumFilters', $this->normalizeForumFilters($this->request->get()));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{withImages: bool, withPosts: bool, imagesCount: int}
     */
    private function normalizeForumFilters(array $raw): array
    {
        $imagesCount = (int)($raw['imagesCount'] ?? 0);
        $ceiling = (int)$this->publicationSettings()['imagesCountFilterMax'];

        return [
            'withImages' => (string)($raw['withImages'] ?? '') === '1',
            'withPosts' => (string)($raw['withPosts'] ?? '') === '1',
            'imagesCount' => $imagesCount > 0 ? min($imagesCount, $ceiling) : 0,
        ];
    }

    /**
     * @param array{withImages: bool, withPosts: bool, imagesCount: int} $filters
     */
    private function hasActiveForumFilter(array $filters): bool
    {
        return $filters['withImages']
            || $filters['withPosts']
            || $filters['imagesCount'] > 0;
    }

    /**
     * The publications route with the filters of the current session as its
     * query, which is the filter part of the address the bar has to show.
     *
     * @return array<int|string, string>
     */
    private function forumFilterUrl(): array
    {
        $filters = $this->forumFilters();
        $url = ['publications'];

        if ($filters['withImages']) {
            $url['withImages'] = '1';
        }
        if ($filters['withPosts']) {
            $url['withPosts'] = '1';
        }
        if ($filters['imagesCount'] > 0) {
            $url['imagesCount'] = (string)$filters['imagesCount'];
        }

        return $url;
    }

    /**
     * The address the whole view state of the page deserves: the forum filters
     * plus the reading order of every block that was switched.
     *
     * @return array<int|string, string>
     */
    private function publicationsUrl(): array
    {
        $url = $this->forumFilterUrl();
        $default = $this->oldestFirstByDefault();

        foreach ($this->publicationsSort() as $block => $oldestFirst) {
            // Only a block that reads against the order of the settings needs
            // a parameter; the value it says is the order of the block, so a
            // page whose default is «oldest» can carry a switch back.
            if ($oldestFirst !== $default) {
                $url[$block . 'Oldest'] = $oldestFirst ? '1' : '0';
            }
        }

        return $url;
    }

    /**
     * @return array{withImages: bool, withPosts: bool, imagesCount: int}
     */
    private function forumFilters(): array
    {
        return $this->normalizeForumFilters((array)Yii::$app->session->get('forumFilters', []));
    }

    /**
     * Which of the lists of the page read themselves oldest first. The order
     * belongs to the switch that set it, so the other lists keep showing what
     * the reader was looking at — including the two switches of the forum
     * block, which reverses its topics and its posts on their own.
     *
     * @return array<string, bool> every name of PUBLICATIONS_SORT_BLOCKS
     */
    private function publicationsSort(): array
    {
        return $this->normalizePublicationsSort((array)Yii::$app->session->get('publicationsSort', []));
    }

    /**
     * The order the address of the page asks for: `?postsOldest=1` and friends.
     *
     * @param array<string, mixed> $query
     * @return array<string, bool> every name of PUBLICATIONS_SORT_BLOCKS
     */
    private function publicationsSortFromQuery(array $query): array
    {
        $raw = [];

        foreach (array_keys(self::PUBLICATIONS_SORT_BLOCKS) as $block) {
            $raw[$block] = $query[$block . 'Oldest'] ?? null;
        }

        return $this->normalizePublicationsSort($raw);
    }

    /**
     * Resolves the effective reading order the way the forum filters do: an
     * order that is already active in the session wins, so reloading or
     * following a link cannot switch a block back; only a session that reads
     * every block in the order of the settings adopts the query of the request.
     */
    private function syncPublicationsSort(): void
    {
        if ($this->publicationsSort() !== $this->defaultPublicationsSort()) {
            return;
        }

        Yii::$app->session->set(
            'publicationsSort',
            $this->publicationsSortFromQuery($this->request->get()),
        );
    }

    /**
     * Whether a reader who never touched a switch of the page sees the oldest
     * records first — the order the settings page chooses for all blocks.
     */
    private function oldestFirstByDefault(): bool
    {
        return $this->settings !== null && $this->settings->isOldestFirstByDefault();
    }

    /**
     * The order of a page nobody has switched.
     *
     * @return array<string, bool> every name of PUBLICATIONS_SORT_BLOCKS
     */
    private function defaultPublicationsSort(): array
    {
        return $this->normalizePublicationsSort([]);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, bool> every name of PUBLICATIONS_SORT_BLOCKS
     */
    private function normalizePublicationsSort(array $raw): array
    {
        $oldestFirst = [];
        $default = $this->oldestFirstByDefault();

        foreach (array_keys(self::PUBLICATIONS_SORT_BLOCKS) as $block) {
            $value = $raw[$block] ?? null;
            // The session keeps real booleans, a request keeps the '1' or the
            // '0' of a switch; anything else leaves the block at the order of
            // the settings.
            $oldestFirst[$block] = $value === null
                ? $default
                : ($value === true || $value === '1');
        }

        return $oldestFirst;
    }

    /**
     * Everything the publications page and its refreshable blocks render from.
     *
     * @return array<string, mixed>
     */
    private function publicationsData(): array
    {
        $filters = $this->forumFilters();
        $sorts = $this->publicationsSort();
        $settings = $this->publicationSettings();
        $postsSize = (int)$settings['publicationPageSize'];
        $topicsSize = (int)$settings['forumTopicsPageSize'];
        $repliesSize = (int)$settings['forumPostsPageSize'];
        $totals = $this->publications->listTotals();
        $totals['forum'] = $this->forum->countTopics(
            $filters['withImages'],
            $filters['withPosts'],
            $filters['imagesCount'],
        );

        return [
            'posts' => $this->publications->posts($postsSize, 0, $sorts['posts']),
            'drafts' => $this->publications->drafts($postsSize, 0, $sorts['drafts']),
            'deleted' => $this->publications->deleted($postsSize, 0, $sorts['deleted']),
            'topics' => $this->forum->latestTopicsWithPosts(
                $topicsSize,
                $repliesSize,
                $filters['withImages'],
                $filters['withPosts'],
                $filters['imagesCount'],
                $sorts['forumTopics'],
                $sorts['forumPosts'],
            ),
            'withImagesOnly' => $filters['withImages'],
            'withPostsOnly' => $filters['withPosts'],
            'imagesCount' => $filters['imagesCount'],
            'totals' => $totals,
            'oldestFirst' => $sorts,
            'settings' => $settings,
            'now' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Re-renders the four lists the publications page updates in place. Every
     * mutation is reported against all of them because a single action can
     * move a record across the posts, drafts and deleted tables at once
     * (see PublicationsService::saveFromForm). Each of those responses carries
     * the first page only: the reader scrolls back to the top of a block that
     * has just changed anyway.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function renderPublicationBlocks(array $data): array
    {
        $blocks = [];

        foreach (['forum', 'posts', 'drafts', 'deleted'] as $block) {
            $blocks[$block] = $this->renderPartial('_block_' . $block, $data);
        }

        return $blocks;
    }

    /**
     * Ends a publications mutation: refreshed blocks as JSON for the AJAX
     * caller, the regular redirect otherwise so the page keeps working
     * without JavaScript. The totals ride along because they decide how far
     * the scroll of each block may keep loading.
     */
    private function finishPublicationsRequest(bool $ok): Response
    {
        if (!$this->request->getIsAjax()) {
            return $this->redirect($this->publicationsUrl());
        }

        $data = $this->publicationsData();

        return $this->asJson([
            'ok' => $ok,
            'blocks' => $this->renderPublicationBlocks($data),
            'totals' => $data['totals'],
            'flash' => $this->renderFlash(),
        ]);
    }

    /**
     * Renders the flash container the AJAX responses swap in place of the
     * redirect that would otherwise have carried the message to the next
     * page view.
     */
    private function renderFlash(): string
    {
        return Alert::widget();
    }

    /**
     * Saves the current forum filter state to the session via AJAX and answers
     * with the address that mirrors it, so the URL follows every session change
     * instead of being rebuilt by the caller.
     *
     * @return Response
     */
    public function actionForumFilterSave(): Response
    {
        Yii::$app->session->set(
            'forumFilters',
            $this->normalizeForumFilters($this->request->post()),
        );
        $data = $this->publicationsData();

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
            'blocks' => ['forum' => $this->renderPartial('_block_forum', $data)],
            'totals' => $data['totals'],
        ]);
    }

    /**
     * Clears the forum filter state from the session and redirects back. The
     * reading order of the blocks is not a filter, so it survives the clear and
     * stays in the address the reader lands on.
     *
     * @return Response
     */
    public function actionForumFilterClear(): Response
    {
        Yii::$app->session->remove('forumFilters');

        if ($this->request->getIsAjax()) {
            $data = $this->publicationsData();

            return $this->asJson([
                'ok' => true,
                'url' => Url::to($this->publicationsUrl()),
                'blocks' => ['forum' => $this->renderPartial('_block_forum', $data)],
                'totals' => $data['totals'],
            ]);
        }

        return $this->redirect($this->publicationsUrl());
    }

    /**
     * Stores the order a switch was set to and answers with the block that
     * switch belongs to, redrawn from the beginning: a switch reverses the
     * reading direction of one list, so the reader gets its first page in the
     * new order. Two of the switches belong to the same forum block, which is
     * why the name of a switch and the name of the block it redraws are not
     * the same thing.
     *
     * @return Response
     */
    public function actionPublicationSort(): Response
    {
        $switch = $this->request->post('block');
        $sorts = $this->publicationsSort();

        if (!is_string($switch) || !isset($sorts[$switch]) || !$this->request->getIsAjax()) {
            return $this->redirect(['publications']);
        }

        $sorts[$switch] = $this->request->post('oldest') === '1';
        Yii::$app->session->set('publicationsSort', $sorts);
        $block = self::PUBLICATIONS_SORT_BLOCKS[$switch];
        $data = $this->publicationsData();

        return $this->asJson([
            'ok' => true,
            'block' => $block,
            'url' => Url::to($this->publicationsUrl()),
            'blocks' => [$block => $this->renderPartial('_block_' . $block, $data)],
            'totals' => $data['totals'],
        ]);
    }

    /**
     * One page of a publications list, for the scroll of the block that asks
     * for it: the items alone, without the empty-state line, so the caller can
     * append them under the rows already on screen.
     *
     * @return Response
     */
    public function actionPublicationPage(): Response
    {
        $block = (string)$this->request->post('block', '');
        $offset = max(0, (int)$this->request->post('offset', 0));
        $page = $this->publicationPage($block, $offset);

        if ($page === null || !$this->request->getIsAjax()) {
            return $this->redirect(['publications']);
        }

        [$view, $records, $total] = $page;
        $now = gmdate('Y-m-d H:i:s');
        $html = '';

        foreach ($records as $record) {
            $html .= $this->renderPartial($view, ['record' => $record, 'now' => $now]);
        }

        return $this->asJson([
            'ok' => true,
            'block' => $block,
            'offset' => $offset + count($records),
            'total' => $total,
            'html' => $html,
        ]);
    }

    /**
     * The list a page of the scroll reads: the view of one item, the rows of
     * that page in the order the block is currently showing and how many rows
     * the whole list holds. A name the page does not have answers null, which
     * is how the caller's block key is checked. The forum answers with its
     * topics: each row carries the posts of its own discussion and their
     * total, which is what opens the scroller of that discussion.
     *
     * @return array{0: string, 1: list<array<string, mixed>|\app\shared\Publications\Dto\PublicationData>, 2: int}|null
     */
    private function publicationPage(string $block, int $offset): ?array
    {
        $settings = $this->publicationSettings();
        $size = (int)$settings['publicationPageSize'];
        $sorts = $this->publicationsSort();
        $filters = $this->forumFilters();

        return match ($block) {
            'posts' => ['_item_post', $this->publications->posts($size, $offset, $sorts['posts']), $this->publications->countPosts()],
            'drafts' => ['_item_draft', $this->publications->drafts($size, $offset, $sorts['drafts']), $this->publications->countDrafts()],
            'deleted' => ['_item_deleted', $this->publications->deleted($size, $offset, $sorts['deleted']), $this->publications->countDeleted()],
            'forum' => [
                '_item_topic',
                $this->forum->latestTopicsWithPosts(
                    (int)$settings['forumTopicsPageSize'],
                    (int)$settings['forumPostsPageSize'],
                    $filters['withImages'],
                    $filters['withPosts'],
                    $filters['imagesCount'],
                    $sorts['forumTopics'],
                    $sorts['forumPosts'],
                    $offset,
                ),
                $this->forum->countTopics($filters['withImages'], $filters['withPosts'], $filters['imagesCount']),
            ],
            default => null,
        };
    }

    /**
     * The next page of one discussion, for the scroll of the box that holds
     * its posts: the replies alone, appended under the ones already on
     * screen. The offset the box carries is where its first page ended,
     * because a topic reaches the page with a page of posts of its own.
     *
     * @return Response
     */
    public function actionForumPostPage(): Response
    {
        $topicId = (int)$this->request->post('topic', 0);
        $offset = max(0, (int)$this->request->post('offset', 0));

        if ($topicId <= 0 || !$this->request->getIsAjax()) {
            return $this->redirect(['publications']);
        }

        $filters = $this->forumFilters();
        $sorts = $this->publicationsSort();
        $page = $this->forum->topicPosts(
            $topicId,
            (int)$this->publicationSettings()['forumPostsPageSize'],
            $offset,
            $filters['withImages'],
            $filters['withPosts'],
            $filters['imagesCount'],
            $sorts['forumPosts'],
        );
        $html = '';

        foreach ($page['posts'] as $post) {
            $html .= $this->renderPartial('_item_reply', ['record' => $post]);
        }

        return $this->asJson([
            'ok' => true,
            'topic' => $topicId,
            'offset' => $offset + count($page['posts']),
            'total' => $page['total'],
            'html' => $html,
        ]);
    }

    /**
     * The whole discussion of one topic for the publication form: every post
     * the table holds, in the order the block shows them at this moment. The
     * filters of the header do not apply here — the form is filled from the
     * thread as it is stored, not from the page of it the reader sees. The
     * topic itself the block already carries on its own button.
     *
     * @return Response
     */
    public function actionForumThread(): Response
    {
        $topicId = (int)$this->request->post('topic', 0);

        if ($topicId <= 0 || !$this->request->getIsAjax()) {
            return $this->redirect(['publications']);
        }

        $sorts = $this->publicationsSort();
        $posts = [];

        foreach ($this->forum->topicThread($topicId, $sorts['forumPosts']) as $post) {
            $posts[] = [
                'text' => $post->contentText,
                'images' => $post->imageUrls,
            ];
        }

        return $this->asJson([
            'ok' => true,
            'topic' => $topicId,
            'posts' => $posts,
        ]);
    }

    /**
     * Marks a forum topic or post as viewed by the "Просмотрено"
     * button: inserts a publications_topic_map / publications_post_map
     * row with an empty telegram_id, so the element stops showing up
     * among the unprocessed ones.
     *
     * @return Response
     */
    public function actionForumViewed(): Response
    {
        $id = (string)($this->request->post('forumEntityId', ''));
        $type = (string)($this->request->post('forumEntityType', ''));

        $ok = false;
        try {
            if ($type === 'topic') {
                $this->forum->markTopicViewed((int)$id);
                Yii::$app->session->setFlash('success', 'Топик отмечен как просмотренный.');
            } elseif ($type === 'post') {
                $this->forum->markPostViewed((int)$id);
                Yii::$app->session->setFlash('success', 'Пост отмечен как просмотренный.');
            } else {
                throw new InvalidArgumentException('Не указан элемент форума.');
            }
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok);
    }

    /**
     * Saves a publication from the new post form: "Опубликовать" and
     * "Сохранить" either create a new record or save an opened one —
     * cross-table moves between posts, drafts and the edited archive
     * are handled by the service. Nothing is sent to Telegram directly.
     * The files the album pickers of the form bring in are stored before
     * the record is written and go into it as the links of that album.
     *
     * @return Response
     */
    public function actionPublicationCreate(): Response
    {
        // PHP throws away a body that is larger than post_max_size before the
        // application sees any of it: neither the fields nor the files of the
        // form arrive, so the request is answered here rather than as a
        // publication with an empty text.
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($this->request->getBodyParams() === [] && $contentLength > 0) {
            Yii::$app->session->setFlash(
                'error',
                'Запрос больше разрешённого сервером размера и дошёл пустым: '
                . 'уменьшите размер или количество приложенных файлов.',
            );

            return $this->finishPublicationsRequest(false);
        }

        // Browsers put CRLF into the wire form of a textarea, so the same text
        // would gain invisible chars on every save; Telegram and the block
        // rendering both count line breaks as a single "\n".
        $texts = $this->publicationTextsFromRequest();
        $formats = $this->publicationFormatsFromRequest(count($texts));
        $buttons = $this->publicationButtonsFromRequest(count($texts));
        $titles = $this->publicationTitlesFromRequest(count($texts));
        $publishedAt = (string)($this->request->post('publicationAt', ''));
        $userTz = (string)($this->request->post('publicationTz', ''));
        $action = $this->request->post('action') === 'draft' ? 'draft' : 'publish';
        $source = (string)($this->request->post('publicationSource', 'new'));
        $sourceId = $this->request->post('publicationSourceId');
        $sourceId = $sourceId === null || $sourceId === '' ? null : (int)$sourceId;
        $forumRef = $this->forumRefFromRequest();

        $ok = false;
        try {
            $imageGroups = $this->withUploadedImages($this->imageGroupsFromRequest());
            $imageUrls = $imageGroups[0];

            if ($source === 'new') {
                $this->publications->saveParts(
                    $texts,
                    $imageGroups,
                    $formats,
                    $buttons,
                    $titles,
                    $publishedAt,
                    $action,
                    $forumRef,
                    $userTz ?: null,
                );
                Yii::$app->session->setFlash('success', $action === 'draft'
                    ? 'Черновик сохранён.'
                    : 'Публикация сохранена и будет отправлена в канал в заданное время.');
            } else {
                if (count($texts) > 1) {
                    throw new InvalidArgumentException(
                        'Разбитую на части публикацию можно сохранить только как новую запись.',
                    );
                }

                $this->publications->saveFromForm(
                    $texts[0],
                    $imageUrls,
                    $formats[0],
                    $buttons[0],
                    $titles[0],
                    $publishedAt,
                    $source,
                    $sourceId,
                    $action,
                    $userTz ?: null,
                );
                Yii::$app->session->setFlash('success', 'Изменения сохранены.');
            }
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok);
    }

    /**
     * The text of the publication form as a list of parts. The form clones its
     * textarea once a text goes past the Telegram limit, so a submission
     * carries either one value or one value per part.
     *
     * @return string[]
     */
    private function publicationTextsFromRequest(): array
    {
        $raw = $this->request->post('publicationText', '');
        $fields = is_array($raw) ? array_values($raw) : [$raw];

        if ($fields === []) {
            $fields = [''];
        }

        return array_map(
            static fn ($text): string => str_replace(["\r\n", "\r"], "\n", (string)$text),
            $fields,
        );
    }

    /**
     * The highlighting of the publication form as one entity list per part: the
     * editor of a part keeps its plain text in the textarea and the spans of
     * that text it paints in the hidden publicationFormatting[] field next to
     * it, so a submission brings the two lists in the same order. A part whose
     * field is missing — a record written before the editor existed — comes
     * back plain.
     *
     * @return MessageEntities[]
     */
    private function publicationFormatsFromRequest(int $parts): array
    {
        $raw = $this->request->post('publicationFormatting', '');
        $fields = is_array($raw) ? array_values($raw) : [$raw];

        $formats = [];
        for ($index = 0; $index < $parts; $index++) {
            $formats[] = MessageEntities::fromStored($fields[$index] ?? null);
        }

        return $formats;
    }

    /**
     * The link buttons of the publication form as one button per part: the
     * shared «Кнопка-ссылка» field carries the button of the first part, the
     * cloned text blocks add one pair of fields each while the «Кнопка-ссылка в
     * каждой части» switch is on, so a submission brings a part's button in the
     * place of that part. A part whose fields are missing — its box stayed
     * switched off — goes to the channel without a button.
     *
     * @return LinkButton[]
     */
    private function publicationButtonsFromRequest(int $parts): array
    {
        $labels = $this->partFieldsFromRequest('publicationButtonText', 'publicationPartButtonText');
        $urls = $this->partFieldsFromRequest('publicationButtonUrl', 'publicationPartButtonUrl');

        $buttons = [];
        for ($index = 0; $index < $parts; $index++) {
            $buttons[] = LinkButton::fromArray([
                'text' => $labels[$index] ?? null,
                'url' => $urls[$index] ?? null,
            ]);
        }

        return $buttons;
    }

    /**
     * The headings of the publication form as one title per part: the shared
     * «Заголовок» field carries the title of the first part and the cloned text
     * blocks add one field each, so a submission brings a part's heading in the
     * place of that part. A part whose field is missing or holds nothing but
     * whitespace goes out without a heading, the way it did before the field
     * existed.
     *
     * @return string[]
     */
    private function publicationTitlesFromRequest(int $parts): array
    {
        $fields = $this->partFieldsFromRequest('publicationTitle', 'publicationPartTitle');

        $titles = [];
        for ($index = 0; $index < $parts; $index++) {
            $titles[] = (string)($fields[$index] ?? '');
        }

        return $titles;
    }

    /**
     * One kind of the per-part fields of the form: the shared field of the first
     * part followed by the cloned fields of the parts that came after it, so
     * the two lists line up with the parts the way the albums do.
     *
     * @return array<int, mixed>
     */
    private function partFieldsFromRequest(string $shared, string $perPart): array
    {
        $raw = $this->request->post($perPart, []);

        return array_merge(
            [$this->request->post($shared, '')],
            is_array($raw) ? array_values($raw) : [$raw],
        );
    }

    /**
     * The albums of the publication form as one list of URLs per part: the
     * shared «Изображения публикации» field carries the album of the first
     * part, the cloned text blocks add one field each, so a submission
     * brings a part's pictures in the place of that part.
     *
     * @return string[][]
     */
    private function imageGroupsFromRequest(): array
    {
        $groups = [$this->imageUrlsFromValue($this->request->post('publicationImages', ''))];
        $raw = $this->request->post('publicationPartImages', []);

        foreach (is_array($raw) ? array_values($raw) : [$raw] as $field) {
            $groups[] = $this->imageUrlsFromValue($field);
        }

        return $groups;
    }

    /**
     * One images field of the publication form: a URL per line with the empty
     * lines dropped, the CRLF of a browser put back into a single "\n".
     *
     * @return string[]
     */
    private function imageUrlsFromValue(mixed $raw): array
    {
        $urls = array_map(
            static fn ($line): string => trim($line),
            explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$raw)),
        );

        return array_values(array_filter($urls, static fn (string $url): bool => $url !== ''));
    }

    /**
     * The albums of the form with the files their pickers brought in: a stored
     * picture is served by the portal itself, so an album gains one more link
     * exactly like the ones typed into the field by hand. Every file is read
     * and checked before any of them leaves the temp directory, so an album
     * that refuses one file stores none of them.
     *
     * @param string[][] $groups one list of links per part
     * @return string[][]
     * @throws InvalidArgumentException when a file came broken, is bigger than
     * «Размер загружаемого файла» or is not a picture the web server serves,
     * and when an album holds more files than «Файлов в альбоме части»
     */
    private function withUploadedImages(array $groups): array
    {
        $settings = $this->publicationSettings();
        $maxMb = (int)$settings['imageUploadMaxMb'];
        $limit = (int)$settings['imageUploadLimit'];
        $albums = [];

        foreach (array_keys($groups) as $index) {
            // The picker of the first part is the shared field of the form, so
            // the named pickers of the parts run one behind the album indexes —
            // the same offset the fields of links keep.
            $files = $index === 0
                ? UploadedFile::getInstancesByName('publicationImageFiles')
                : UploadedFile::getInstancesByName('publicationPartImageFiles' . ($index - 1));

            if (count($files) > $limit) {
                throw new InvalidArgumentException(
                    'Часть принимает не больше ' . $limit . ' файлов, а к ней приложено ' . count($files) . '.',
                );
            }

            $albums[$index] = array_map(
                fn (UploadedFile $file): array => $this->validatedUpload($file, $maxMb),
                $files,
            );
        }

        foreach ($albums as $index => $uploads) {
            foreach ($uploads as [$file, $extension]) {
                $groups[$index][] = $this->storeUpload($file, $extension);
            }
        }

        return $groups;
    }

    /**
     * Checks one picked file and pairs it with the extension of the format its
     * content holds — what a browser names a file is not a fact the portal can
     * rely on, and the extension decides whether the web server serves the copy.
     *
     * @return array{0: UploadedFile, 1: string}
     * @throws InvalidArgumentException when the file is unusable
     */
    private function validatedUpload(UploadedFile $file, int $maxMb): array
    {
        $name = (string)$file->name;

        if ($file->error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Файл «' . $name . '» дошёл до сервера не полностью.');
        }

        if ($file->size > $maxMb * 1024 * 1024) {
            throw new InvalidArgumentException('Файл «' . $name . '» весит больше ' . $maxMb . ' МБ.');
        }

        $image = @getimagesize((string)$file->tempName);

        if ($image === false || !isset(self::UPLOAD_IMAGE_TYPES[$image[2]])) {
            throw new InvalidArgumentException(
                'Файл «' . $name . '» — не JPEG, PNG, GIF или WebP, а остальное канал не принимает.',
            );
        }

        return [$file, self::UPLOAD_IMAGE_TYPES[$image[2]]];
    }

    /**
     * Moves the file under the web root and answers with the address the portal
     * serves it from. The name is random: an upload is not meant to be found or
     * guessed, only read once by the channel.
     */
    private function storeUpload(UploadedFile $file, string $extension): string
    {
        $month = date('Ym');
        $directory = Yii::getAlias('@webroot') . '/' . self::UPLOAD_DIR . '/' . $month;
        $name = bin2hex(random_bytes(8)) . '.' . $extension;

        FileHelper::createDirectory($directory);

        if (!$file->saveAs($directory . '/' . $name)) {
            throw new InvalidArgumentException('Файл «' . $file->name . '» не удалось сохранить на сервере.');
        }

        return rtrim((string)Yii::$app->request->getHostInfo(), '/')
            . rtrim((string)Yii::getAlias('@web'), '/')
            . '/' . self::UPLOAD_DIR . '/' . $month . '/' . $name;
    }

    /**
     * The hidden forum source fields filled by the "Опубликовать"
     * button on a forum topic or post: tells the service which map
     * row to link the new publication with.
     */
    private function forumRefFromRequest(): ?ForumPublicationRef
    {
        $type = (string)($this->request->post('forumEntityType', ''));
        $id = (string)($this->request->post('forumEntityId', ''));

        if (!in_array($type, ['topic', 'post'], true) || $id === '') {
            return null;
        }

        return new ForumPublicationRef($type, (int)$id);
    }

    /**
     * Publishes a record immediately by the "Опубликовать" button on a
     * list item: a draft moves to the posts table, a scheduled post
     * stops being scheduled — in both cases published_at and
     * updated_at become the current time. Nothing is sent to Telegram
     * directly; the periodic task picks the record up.
     *
     * @return Response
     */
    public function actionPublicationPublish(): Response
    {
        $id = (string)($this->request->post('publicationId', ''));
        $source = (string)($this->request->post('publicationSource', ''));

        $ok = false;
        try {
            if ($source === 'draft') {
                $this->publications->publishDraft((int)$id);
            } elseif ($source === 'post') {
                $this->publications->publishPostNow((int)$id);
            } elseif ($source === 'deleted') {
                $this->publications->publishDeleted((int)$id);
            } else {
                throw new InvalidArgumentException('Не указана публикуемая запись.');
            }
            Yii::$app->session->setFlash('success', 'Публикация сохранена и будет отправлена в канал.');
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok);
    }

    /**
     * Moves a post to drafts by the "Переместить в черновик" button:
     * the record leaves the posts table and appears in the drafts
     * table with created_at preserved and updated_at set to now.
     * The same button on a soft-deleted record restores it from
     * publications_deleted back to the drafts table.
     *
     * @return Response
     */
    public function actionPublicationToDraft(): Response
    {
        $id = (string)($this->request->post('publicationId', ''));
        $source = (string)($this->request->post('publicationSource', ''));

        $ok = false;
        try {
            if ($source === 'deleted') {
                $this->publications->moveDeletedToDraft((int)$id);
                Yii::$app->session->setFlash('success', 'Удалённая запись перемещена в черновики.');
            } else {
                $this->publications->movePostToDraft((int)$id);
                Yii::$app->session->setFlash('success', 'Публикация перемещена в черновики.');
            }
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok);
    }

    /**
     * Schedules a draft or a soft-deleted record by the
     * "Запланировать публикацию" modal: the record moves to the
     * posts table with the publication time taken from the modal's
     * date-time field.
     *
     * @return Response
     */
    public function actionPublicationSchedule(): Response
    {
        $id = (string)($this->request->post('publicationId', ''));
        $publishedAt = (string)($this->request->post('publicationAt', ''));
        $userTz = (string)($this->request->post('publicationTz', ''));
        $source = (string)($this->request->post('publicationSource', 'draft'));

        $ok = false;
        try {
            if ($source === 'deleted') {
                $this->publications->scheduleDeleted($id === '' ? 0 : (int)$id, $publishedAt, $userTz ?: null);
            } else {
                $this->publications->scheduleDraft($id === '' ? 0 : (int)$id, $publishedAt, $userTz ?: null);
            }
            Yii::$app->session->setFlash('success', 'Публикация запланирована.');
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok);
    }

    /**
     * Soft-deletes a record by the "Удалить" button on a list item:
     * the record leaves its table (posts or drafts) and moves to
     * publications_deleted with created_at and published_at
     * preserved, updated_at set to now and deleted_at left empty —
     * the periodic task removes the channel message and stamps it.
     *
     * @return Response
     */
    public function actionPublicationDelete(): Response
    {
        $id = (string)($this->request->post('publicationId', ''));
        $source = (string)($this->request->post('publicationSource', ''));

        $ok = false;
        try {
            if ($source === 'draft') {
                $this->publications->deleteDraft((int)$id);
            } elseif ($source === 'post') {
                $this->publications->deletePost((int)$id);
            } else {
                throw new InvalidArgumentException('Не указана удаляемая запись.');
            }
            Yii::$app->session->setFlash('success', 'Запись удалена.');
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok);
    }

    /**
     * Updates the TRVL channel description from the settings page.
     *
     * @return Response
     */
    public function actionChannelDescription(): Response
    {
        $description = (string)($this->request->post('channelDescription', ''));

        try {
            $this->telegramChannel->updateDescription($description);
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());

            return $this->redirect(['settings']);
        } catch (TelegramApiException $e) {
            Yii::$app->session->setFlash(
                'error',
                "Telegram API error [{$e->errorCode}]: {$e->getMessage()}",
            );

            return $this->redirect(['settings']);
        } catch (RuntimeException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());

            return $this->redirect(['settings']);
        }

        Yii::$app->session->setFlash('success', 'Описание канала обновлено.');

        return $this->redirect(['settings']);
    }

    /**
     * Login action.
     *
     * @return Response|string
     */
    public function actionLogin(): Response|string
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm($this->security);

        if ($model->load($this->request->post()) && $model->login()) {
            return $this->goBack();
        }

        $model->password = '';

        return $this->render('login', ['model' => $model]);
    }

    /**
     * Logout action.
     *
     * @return Response
     */
    public function actionLogout(): Response
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    /**
     * Displays contact page.
     *
     * @return Response|string
     */
    public function actionContact(): Response|string
    {
        $model = new ContactForm();

        $contact = $model->load($this->request->post()) && $model->contact(
            $this->mailer,
            Yii::$app->params['adminEmail'],
            Yii::$app->params['senderEmail'],
            Yii::$app->params['senderName'],
        );

        if ($contact) {
            Yii::$app->session->setFlash(
                'success',
                'Thank you for contacting us. We will respond to you as soon as possible.',
            );

            return $this->refresh();
        }

        return $this->render('contact', ['model' => $model]);
    }

    /**
     * Displays about page.
     *
     * @return string
     */
    public function actionAbout(): string
    {
        return $this->render('about');
    }

    /**
     * Returns the user's preferred timezone from the cookie, falling back to
     * UTC for display purposes (consistent with client-side detection).
     *
     * @return string
     */
    public function getUserDisplayTimezone(): string
    {
        try {
            $cookie = Yii::$app->request->cookies->get('portal_tz');
            if ($cookie !== null && $cookie->value !== '') {
                new DateTimeZone($cookie->value); // validate
                return $cookie->value;
            }
        } catch (\Exception $e) {
            // invalid timezone in cookie, fall through
        }
        return 'UTC';
    }
}
