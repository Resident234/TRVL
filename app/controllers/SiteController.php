<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\models\ContactForm;
use app\models\LoginForm;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Forum\Service\ParserSettingsService;
use app\shared\Publications\Dto\ForumPublicationRef;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Dto\LinkButtons;
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

    /**
     * The two tokens the mask of an album is made of: one per picture, naming
     * which of the two fields of the form that picture came out of. A link typed
     * into «Изображения публикации» and a file stored from «Файлы публикации»
     * travel as separate lists, so without this the album could only ever be the
     * links first and the pictures picked behind them.
     */
    private const ALBUM_LINK_TOKEN = 'l';
    private const ALBUM_FILE_TOKEN = 'f';

    /**
     * The four marks the block of the heading has a box for, in the order the
     * boxes stand in the form. The block lays one of them over the whole line of
     * a heading that carries no marks of its own, so these names are the names of
     * the boxes, not the names of the entities: the script of the form translates
     * «strike» into the strikethrough of a message the same way the toolbar of a
     * heading field does.
     */
    private const HEADING_MARKS = ['bold', 'italic', 'strike', 'underline'];

    /**
     * The marks a form that was never asked stands with. Every heading went out
     * bold over its whole line before the block existed, so the bold box is the
     * one that arrives checked.
     */
    private const HEADING_MARKS_DEFAULT = ['bold'];

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
                    'title-from-first-line-save' => ['post'],
                    'links-to-buttons-save' => ['post'],
                    'album-rows-save' => ['post'],
                    'parts-publication-save' => ['post'],
                    'distribute-images-save' => ['post'],
                    'number-parts-save' => ['post'],
                    'heading-number-template-save' => ['post'],
                    'heading-formatting-save' => ['post'],
                    'publication-part-schedule' => ['post'],
                    'publication-part-group' => ['post'],
                    'publication-part-draft' => ['post'],
                    'forum-text-order-save' => ['post'],
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
     * filters, the reading order of the lists, the length order of the topics
     * and the two switches of the form, and the address bar is kept as their
     * mirror image, so a page that was opened on a URL disagreeing with the
     * session is redirected onto the address the session describes.
     *
     * @return Response|string
     */
    public function actionPublications(): Response|string
    {
        $this->layout = 'dashboard';

        $this->syncForumFilters();
        $this->syncPublicationsSort();
        $this->syncForumTextOrder();
        $this->syncTitleFromFirstLine();
        $this->syncLinksToButtons();
        $this->syncAlbumRows();
        $this->syncPartsPublication();
        $this->syncDistributeImages();
        $this->syncNumberParts();
        $this->syncHeadingNumberTemplate();
        $this->syncHeadingNumberCustom();
        $this->syncHeadingFormatting();
        if ($this->viewStateDisagreesWithSession($this->request->get())) {
            return $this->redirect($this->publicationsUrl());
        }

        return $this->render('publications', $this->publicationsData());
    }

    /**
     * Whether the address the page was opened on says something else than the
     * session does — of the forum filters, of the reading order of the lists,
     * of the length order of the topics or of a switch of the form.
     *
     * @param array<string, mixed> $query
     */
    private function viewStateDisagreesWithSession(array $query): bool
    {
        return $this->normalizeForumFilters($query) !== $this->forumFilters()
            || $this->publicationsSortFromQuery($query) !== $this->publicationsSort()
            || $this->forumTextOrderFromQuery($query) !== $this->forumTextOrder()
            || $this->titleFromFirstLineFromQuery($query) !== $this->titleFromFirstLine()
            || $this->linksToButtonsFromQuery($query) !== $this->linksToButtons()
            || $this->albumRowsFromQuery($query) !== $this->albumRows()
            || $this->partsPublicationFromQuery($query) !== $this->partsPublication()
            || $this->distributeImagesFromQuery($query) !== $this->distributeImages()
            || $this->numberPartsFromQuery($query) !== $this->numberParts()
            || $this->headingNumberTemplateFromQuery($query) !== $this->headingNumberTemplate()
            || $this->headingNumberCustomFromQuery($query) !== $this->headingNumberCustom()
            || $this->headingFormattingFromQuery($query) !== $this->headingFormatting();
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
     * @return array{withImages: bool, withPosts: bool, imagesCount: int, withLinks: bool, linksCount: int}
     */
    private function normalizeForumFilters(array $raw): array
    {
        $imagesCount = (int)($raw['imagesCount'] ?? 0);
        $linksCount = (int)($raw['linksCount'] ?? 0);
        $ceiling = (int)$this->publicationSettings()['imagesCountFilterMax'];
        $linksCeiling = (int)$this->publicationSettings()['linksCountFilterMax'];

        return [
            'withImages' => (string)($raw['withImages'] ?? '') === '1',
            'withPosts' => (string)($raw['withPosts'] ?? '') === '1',
            'imagesCount' => $imagesCount > 0 ? min($imagesCount, $ceiling) : 0,
            'withLinks' => (string)($raw['withLinks'] ?? '') === '1',
            'linksCount' => $linksCount > 0 ? min($linksCount, $linksCeiling) : 0,
        ];
    }

    /**
     * @param array{withImages: bool, withPosts: bool, imagesCount: int, withLinks: bool, linksCount: int} $filters
     */
    private function hasActiveForumFilter(array $filters): bool
    {
        return $filters['withImages']
            || $filters['withPosts']
            || $filters['imagesCount'] > 0
            || $filters['withLinks']
            || $filters['linksCount'] > 0;
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
        if ($filters['withLinks']) {
            $url['withLinks'] = '1';
        }
        if ($filters['linksCount'] > 0) {
            $url['linksCount'] = (string)$filters['linksCount'];
        }

        return $url;
    }

    /**
     * The address the whole view state of the page deserves: the forum filters,
     * the reading order of every block that was switched, the length order of
     * the topics and the switches of the form.
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

        if ($this->forumTextOrder() !== '') {
            $url['forumTextOrder'] = $this->forumTextOrder();
        }

        if ($this->titleFromFirstLine()) {
            $url['titleFromFirstLine'] = '1';
        }

        if ($this->linksToButtons()) {
            $url['linksToButtons'] = '1';
        }

        if ($this->albumRows()) {
            $url['albumRows'] = '1';
        }

        if ($this->partsPublication()) {
            $url['partsPublication'] = '1';
        }

        if ($this->distributeImages()) {
            $url['distributeImages'] = '1';
        }

        if ($this->numberParts()) {
            $url['numberParts'] = '1';
        }

        // The head with a dot behind the number is the shape a page was written with
        // before either row existed, so mirroring it would hang a parameter over every
        // address of the form for nothing. The field of the reader is the one state that
        // has no shape of its own without a word in it, so the whole string travels.
        if ($this->headingNumberTemplate() !== PublicationSettingsService::HEADING_NUMBER_DEFAULT) {
            $url['headingNumberTemplate'] = $this->headingNumberTemplate();
        }

        // A gate standing off is the shape every form was drawn with: the two rows answer
        // the number on their own.
        if ($this->headingNumberCustom()) {
            $url['headingNumberCustom'] = '1';
        }

        // The bold mark is the shape every heading was written with before the
        // block existed, so mirroring it would hang a parameter over every address
        // of the form for nothing. An empty list is not that shape: it is the
        // reader having taken every box off, and it needs a parameter of its own —
        // the absence of one answers the bold mark.
        if ($this->headingFormatting() !== self::HEADING_MARKS_DEFAULT) {
            $url['headingFormatting'] = implode(',', $this->headingFormatting());
        }

        return $url;
    }

    /**
     * @return array{withImages: bool, withPosts: bool, imagesCount: int, withLinks: bool, linksCount: int}
     */
    private function forumFilters(): array
    {
        return $this->normalizeForumFilters((array)Yii::$app->session->get('forumFilters', []));
    }

    /**
     * Whether the fill of the forum block cuts the first line of its text into
     * the heading field — the state of the switch of the form header, kept in
     * the session so a reload reads the form the way the reader left it.
     */
    private function titleFromFirstLine(): bool
    {
        return $this->normalizeTitleFromFirstLine(Yii::$app->session->get('titleFromFirstLine'));
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
     * Which of the two buttons of the forum header stands the topics up by the
     * length of their own text: 'asc' the shortest first, 'desc' the longest,
     * '' the dates.
     */
    private function forumTextOrder(): string
    {
        return $this->normalizeForumTextOrder(Yii::$app->session->get('forumTextOrder'));
    }

    /**
     * The length order the address of the page asks for: `?forumTextOrder=asc`
     * or `?forumTextOrder=desc`.
     *
     * @param array<string, mixed> $query
     */
    private function forumTextOrderFromQuery(array $query): string
    {
        return $this->normalizeForumTextOrder($query['forumTextOrder'] ?? null);
    }

    /**
     * A state the session keeps is the word it was stored with, a state the
     * address carries is its parameter. Anything else, and the absence of both,
     * is the empty string: the topics read themselves by their dates.
     *
     * @param mixed $state
     */
    private function normalizeForumTextOrder(mixed $state): string
    {
        return is_string($state) && in_array($state, ['asc', 'desc'], true) ? $state : '';
    }

    /**
     * Resolves the length order the way the heading switch of the form does: a
     * list can honestly be left unsorted, so it is a session that has never
     * held an order which adopts the parameter of the request — reloading or
     * following a link cannot take the sort away from the reader who set it.
     */
    private function syncForumTextOrder(): void
    {
        $session = Yii::$app->session;

        if ($session->has('forumTextOrder')) {
            return;
        }

        $session->set('forumTextOrder', $this->forumTextOrderFromQuery($this->request->get()));
    }

    /**
     * The state of the heading switch the address of the page asks for:
     * `?titleFromFirstLine=1`.
     *
     * @param array<string, mixed> $query
     */
    private function titleFromFirstLineFromQuery(array $query): bool
    {
        return $this->normalizeTitleFromFirstLine($query['titleFromFirstLine'] ?? null);
    }

    /**
     * Resolves the heading switch the way the forum filters do, with one change:
     * a switch has a state of its own when it is off, so it is a session that
     * has never held one which adopts the parameter of the request — reloading
     * or following a link cannot change what the reader left.
     */
    private function syncTitleFromFirstLine(): void
    {
        $session = Yii::$app->session;

        if ($session->has('titleFromFirstLine')) {
            return;
        }

        $session->set('titleFromFirstLine', $this->titleFromFirstLineFromQuery($this->request->get()));
    }

    /**
     * A state the session keeps is a real boolean, a state a request or a
     * switch carries is the '1' of its parameter.
     *
     * @param mixed $state
     */
    private function normalizeTitleFromFirstLine(mixed $state): bool
    {
        return $state === true || $state === '1';
    }

    /**
     * Whether the switch of the form turns the addresses of a text into link
     * buttons on its own — the state of that switch, kept in the session so a
     * reload opens the form with the automation the reader left running.
     */
    private function linksToButtons(): bool
    {
        return $this->normalizeLinksToButtons(Yii::$app->session->get('linksToButtons'));
    }

    /**
     * The state of the links switch the address of the page asks for:
     * `?linksToButtons=1`.
     *
     * @param array<string, mixed> $query
     */
    private function linksToButtonsFromQuery(array $query): bool
    {
        return $this->normalizeLinksToButtons($query['linksToButtons'] ?? null);
    }

    /**
     * Resolves the links switch the way the heading switch does: the automation
     * has a state of its own when it is off, so it is a session that has never
     * held one which adopts the parameter of the request — reloading or
     * following a link cannot change what the reader left.
     */
    private function syncLinksToButtons(): void
    {
        $session = Yii::$app->session;

        if ($session->has('linksToButtons')) {
            return;
        }

        $session->set('linksToButtons', $this->linksToButtonsFromQuery($this->request->get()));
    }

    /**
     * A state the session keeps is a real boolean, a state a request or a
     * switch carries is the '1' of its parameter.
     *
     * @param mixed $state
     */
    private function normalizeLinksToButtons(mixed $state): bool
    {
        return $state === true || $state === '1';
    }

    /**
     * Whether the album of the form lays its pictures out in rows the way a post
     * of the channel does — the state of the switch above the pictures of the
     * whole form, kept in the session so a reload opens the album the way the
     * reader left it. Only that one switch is remembered: the ones a part carries
     * are set for that part alone and start off every time the page is read.
     */
    private function albumRows(): bool
    {
        return $this->normalizeAlbumRows(Yii::$app->session->get('albumRows'));
    }

    /**
     * The state of the album switch the address of the page asks for:
     * `?albumRows=1`.
     *
     * @param array<string, mixed> $query
     */
    private function albumRowsFromQuery(array $query): bool
    {
        return $this->normalizeAlbumRows($query['albumRows'] ?? null);
    }

    /**
     * Resolves the album switch the way the two switches of the form header do:
     * the layout has a state of its own when it is off, so it is a session that
     * has never held one which adopts the parameter of the request — reloading or
     * following a link cannot change what the reader left.
     */
    private function syncAlbumRows(): void
    {
        $session = Yii::$app->session;

        if ($session->has('albumRows')) {
            return;
        }

        $session->set('albumRows', $this->albumRowsFromQuery($this->request->get()));
    }

    /**
     * A state the session keeps is a real boolean, a state a request or a
     * switch carries is the '1' of its parameter.
     *
     * @param mixed $state
     */
    private function normalizeAlbumRows(mixed $state): bool
    {
        return $state === true || $state === '1';
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
        $textOrder = $this->forumTextOrder();
        $settings = $this->publicationSettings();
        $postsSize = (int)$settings['publicationPageSize'];
        $topicsSize = (int)$settings['forumTopicsPageSize'];
        $repliesSize = (int)$settings['forumPostsPageSize'];
        $totals = $this->publications->listTotals();
        $totals['forum'] = $this->forum->countTopics(
            $filters['withImages'],
            $filters['withPosts'],
            $filters['imagesCount'],
            $filters['withLinks'],
            $filters['linksCount'],
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
                $filters['withLinks'],
                $filters['linksCount'],
                $sorts['forumTopics'],
                $sorts['forumPosts'],
                0,
                $textOrder,
            ),
            'withImagesOnly' => $filters['withImages'],
            'withPostsOnly' => $filters['withPosts'],
            'imagesCount' => $filters['imagesCount'],
            'withLinksOnly' => $filters['withLinks'],
            'linksCount' => $filters['linksCount'],
            'totals' => $totals,
            'oldestFirst' => $sorts,
            'forumTextOrder' => $textOrder,
            'titleFromFirstLine' => $this->titleFromFirstLine(),
            'linksToButtons' => $this->linksToButtons(),
            'albumRows' => $this->albumRows(),
            'partsPublication' => $this->partsPublication(),
            'distributeImages' => $this->distributeImages(),
            'numberParts' => $this->numberParts(),
            'headingNumberTemplate' => $this->headingNumberTemplate(),
            'headingNumberCustom' => $this->headingNumberCustom(),
            'headingFormatting' => $this->headingFormatting(),
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
     *
     * $extra carries what only the caller that asked for it reads — the id of
     * a row a form part has just been written under. Nothing of it reaches the
     * redirect: a page reload reads the blocks, not the answer of one button.
     *
     * @param array<string, mixed> $extra
     */
    private function finishPublicationsRequest(bool $ok, array $extra = []): Response
    {
        if (!$this->request->getIsAjax()) {
            return $this->redirect($this->publicationsUrl());
        }

        $data = $this->publicationsData();

        return $this->asJson(array_merge([
            'ok' => $ok,
            'blocks' => $this->renderPublicationBlocks($data),
            'totals' => $data['totals'],
            'flash' => $this->renderFlash(),
        ], $extra));
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
     * Stores the heading switch of the form and answers with the address that
     * mirrors it. Nothing is redrawn along with it: the switch says how a fill
     * of the forum block reads, which is a thing of the form, not of a list.
     *
     * @return Response
     */
    public function actionTitleFromFirstLineSave(): Response
    {
        Yii::$app->session->set(
            'titleFromFirstLine',
            $this->normalizeTitleFromFirstLine($this->request->post('titleFromFirstLine')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Stores the switch that turns the addresses of a text into link buttons and
     * answers with the address that mirrors it. Like the heading switch it
     * redraws nothing: the automation works over the fields of the form, which
     * are none of the server's business until the record is saved.
     *
     * @return Response
     */
    public function actionLinksToButtonsSave(): Response
    {
        Yii::$app->session->set(
            'linksToButtons',
            $this->normalizeLinksToButtons($this->request->post('linksToButtons')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Stores the switch that lays the album of the form out in rows and answers
     * with the address that mirrors it. It redraws nothing either: the album it
     * speaks of lives in the fields of the form, and the page that opens after it
     * renders the switch the state it was left in.
     *
     * @return Response
     */
    public function actionAlbumRowsSave(): Response
    {
        Yii::$app->session->set(
            'albumRows',
            $this->normalizeAlbumRows($this->request->post('albumRows')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Whether the parts of a split publication each get their own publish button
     * and their own publication time — the state of the switch next to
     * «Нумерация частей», kept in the session so a reload reads the form the way
     * the reader left it.
     */
    private function partsPublication(): bool
    {
        return $this->normalizePartsPublication(Yii::$app->session->get('partsPublication'));
    }

    /**
     * The state of the switch of the parts the address of the page asks for:
     * `?partsPublication=1`.
     *
     * @param array<string, mixed> $query
     */
    private function partsPublicationFromQuery(array $query): bool
    {
        return $this->normalizePartsPublication($query['partsPublication'] ?? null);
    }

    /**
     * Resolves the switch of the parts the way the album switch does: it is a
     * session that has never held a state which adopts the parameter of the
     * request, so reloading cannot change what the reader left.
     */
    private function syncPartsPublication(): void
    {
        $session = Yii::$app->session;

        if ($session->has('partsPublication')) {
            return;
        }

        $session->set('partsPublication', $this->partsPublicationFromQuery($this->request->get()));
    }

    /**
     * A state the session keeps is a real boolean, a state a request or a
     * switch carries is the '1' of its parameter.
     *
     * @param mixed $state
     */
    private function normalizePartsPublication(mixed $state): bool
    {
        return $state === true || $state === '1';
    }

    /**
     * Whether the albums of the form are handed out over its parts on their own —
     * the state of the switch of the images, kept in the session so a reload opens
     * the form in the mode the reader left it. While it stands, the page cuts the
     * pictures again whenever a fill of the forum brings an album or whenever the
     * number of the parts changes; the cut itself is none of the server's business.
     */
    private function distributeImages(): bool
    {
        return $this->normalizeDistributeImages(Yii::$app->session->get('distributeImages'));
    }

    /**
     * The state of the switch of the distribution the address of the page asks for:
     * `?distributeImages=1`.
     *
     * @param array<string, mixed> $query
     */
    private function distributeImagesFromQuery(array $query): bool
    {
        return $this->normalizeDistributeImages($query['distributeImages'] ?? null);
    }

    /**
     * Resolves the switch of the distribution the way the switch of the parts does:
     * it is a session that has never held a state which adopts the parameter of the
     * request, so reloading or following a link cannot change what the reader left.
     */
    private function syncDistributeImages(): void
    {
        $session = Yii::$app->session;

        if ($session->has('distributeImages')) {
            return;
        }

        $session->set('distributeImages', $this->distributeImagesFromQuery($this->request->get()));
    }

    /**
     * A state the session keeps is a real boolean, a state a request or a
     * switch carries is the '1' of its parameter.
     *
     * @param mixed $state
     */
    private function normalizeDistributeImages(mixed $state): bool
    {
        return $state === true || $state === '1';
    }

    /**
     * Whether the number of a part is written at all — the state of the switch of
     * the numbering, kept in the session so a reload opens the form with the
     * numbering the reader left. The switch used to live only in the browser, and
     * a hand that cleared the form threw it back off; since 2026-10-23 it is a
     * state like its neighbours, and the pair of radios under it is read only
     * while it stands.
     */
    private function numberParts(): bool
    {
        return $this->normalizeNumberParts(Yii::$app->session->get('numberParts'));
    }

    /**
     * The state of the switch of the numbering the address of the page asks for:
     * `?numberParts=1`.
     *
     * @param array<string, mixed> $query
     */
    private function numberPartsFromQuery(array $query): bool
    {
        return $this->normalizeNumberParts($query['numberParts'] ?? null);
    }

    /**
     * Resolves the switch of the numbering the way the switch of the distribution
     * does: it is a session that has never held a state which adopts the parameter
     * of the request, so reloading cannot change what the reader left.
     */
    private function syncNumberParts(): void
    {
        $session = Yii::$app->session;

        if ($session->has('numberParts')) {
            return;
        }

        $session->set('numberParts', $this->numberPartsFromQuery($this->request->get()));
    }

    /**
     * A state the session keeps is a real boolean, a state a request or a
     * switch carries is the '1' of its parameter.
     *
     * @param mixed $state
     */
    private function normalizeNumberParts(mixed $state): bool
    {
        return $state === true || $state === '1';
    }

    /**
     * The shape a part heading wears its number in — one string of the two tokens the
     * field of the form holds, whether it is a preset the two rows stand on or the words
     * of the reader. The state of the whole shape, kept in the session like the switch of
     * the numbering under it. The preset of the head and the dot is the shape the page
     * wrote before the rows existed, so a session that has never been asked answers with
     * it, and so does a state that is not a shape at all.
     */
    private function headingNumberTemplate(): string
    {
        return PublicationSettingsService::normalizeHeadingNumberTemplate(
            Yii::$app->session->get('headingNumberTemplate'),
        );
    }

    /**
     * The shape the address of the page asks for:
     * `?headingNumberTemplate=Текст%20-%20Часть%201`.
     *
     * @param array<string, mixed> $query
     */
    private function headingNumberTemplateFromQuery(array $query): string
    {
        return PublicationSettingsService::normalizeHeadingNumberTemplate(
            $query['headingNumberTemplate'] ?? null,
        );
    }

    /**
     * Resolves the shape of the number the way the switch of the numbering does: it is a
     * session that has never held a shape which adopts the parameter of the request, so
     * reloading cannot change what the reader left.
     */
    private function syncHeadingNumberTemplate(): void
    {
        $session = Yii::$app->session;

        if ($session->has('headingNumberTemplate')) {
            return;
        }

        $session->set('headingNumberTemplate', $this->headingNumberTemplateFromQuery($this->request->get()));
    }

    /**
     * Whether the field of the shape owns it — the state of the checkbox that hands the
     * two rows over to the words of the reader, kept in the session beside the shape it
     * belongs to. A gate standing off leaves the rows to answer the number, and the field
     * only mirrors what they stand on.
     */
    private function headingNumberCustom(): bool
    {
        return $this->normalizeHeadingNumberCustom(Yii::$app->session->get('headingNumberCustom'));
    }

    /**
     * The gate the address of the page asks for: `?headingNumberCustom=1`.
     *
     * @param array<string, mixed> $query
     */
    private function headingNumberCustomFromQuery(array $query): bool
    {
        return $this->normalizeHeadingNumberCustom($query['headingNumberCustom'] ?? null);
    }

    /**
     * Resolves the gate the way the shape it hands over is resolved: a session that has
     * never held one adopts the parameter of the request.
     */
    private function syncHeadingNumberCustom(): void
    {
        $session = Yii::$app->session;

        if ($session->has('headingNumberCustom')) {
            return;
        }

        $session->set('headingNumberCustom', $this->headingNumberCustomFromQuery($this->request->get()));
    }

    /**
     * A state the session keeps is a real boolean, a state a request or a switch carries
     * is the '1' of its parameter — and nothing else is a gate standing on.
     *
     * @param mixed $state
     */
    private function normalizeHeadingNumberCustom(mixed $state): bool
    {
        return $state === true || $state === '1';
    }

    /**
     * Which marks the block of the heading stands with — the state of its four
     * boxes, kept in the session like the switches above them so a reload opens
     * the form with the marks the reader left. A heading that carries marks of
     * its own answers nothing here: the block lays its mark over a bare heading
     * only, and the page puts it there at the moment the heading leaves the form.
     *
     * @return string[]
     */
    private function headingFormatting(): array
    {
        return $this->normalizeHeadingFormatting(Yii::$app->session->get('headingFormatting'));
    }

    /**
     * The marks the address of the page asks for: `?headingFormatting=bold,italic`
     * for the marks of the boxes and `?headingFormatting=` for a heading that
     * stands without any of them.
     *
     * @param array<string, mixed> $query
     *
     * @return string[]
     */
    private function headingFormattingFromQuery(array $query): array
    {
        return $this->normalizeHeadingFormatting($query['headingFormatting'] ?? null);
    }

    /**
     * Resolves the boxes of the heading block the way the pair of radios under the
     * numbering does: it is a session that has never held a state which adopts the
     * parameter of the request, so reloading cannot change what the reader left.
     */
    private function syncHeadingFormatting(): void
    {
        $session = Yii::$app->session;

        if ($session->has('headingFormatting')) {
            return;
        }

        $session->set('headingFormatting', $this->headingFormattingFromQuery($this->request->get()));
    }

    /**
     * Reads the state of the four boxes in whichever shape it arrived. The session
     * keeps the list itself; the address and the post of the block carry the names
     * joined by a comma, and a name spaced over the comma is still that name. An
     * empty string is a real state — every box taken off — while a state that was
     * never given at all answers the bold mark, and a name the block has no box for
     * is dropped. The list comes back in the order of the boxes, so two addresses
     * that say the same marks are one state however the names were typed in.
     *
     * @param mixed $state
     *
     * @return string[]
     */
    private function normalizeHeadingFormatting(mixed $state): array
    {
        $asked = null;

        if (is_string($state)) {
            $asked = array_map('trim', explode(',', $state));
        }

        if (is_array($state)) {
            $asked = [];

            foreach ($state as $one) {
                if (is_string($one)) {
                    $asked[] = trim($one);
                }
            }
        }

        if ($asked === null) {
            return self::HEADING_MARKS_DEFAULT;
        }

        $marks = [];

        foreach (self::HEADING_MARKS as $mark) {
            if (in_array($mark, $asked, true)) {
                $marks[] = $mark;
            }
        }

        return $marks;
    }

    /**
     * Stores the state of the switch of the parts and answers with the address that
     * mirrors it, exactly like the album switch does. It redraws nothing: the parts
     * live in the fields of the form, and their own buttons are put there by the
     * script that reads the switch.
     *
     * @return Response
     */
    public function actionPartsPublicationSave(): Response
    {
        Yii::$app->session->set(
            'partsPublication',
            $this->normalizePartsPublication($this->request->post('partsPublication')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Stores the state of the switch of the distribution and answers with the
     * address that mirrors it, exactly like the switch of the parts does. It
     * redraws nothing: the albums it hands out are the fields of the form, and the
     * cut is made by the script that reads the switch.
     *
     * @return Response
     */
    public function actionDistributeImagesSave(): Response
    {
        Yii::$app->session->set(
            'distributeImages',
            $this->normalizeDistributeImages($this->request->post('distributeImages')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Stores the state of the switch of the numbering and answers with the address
     * that mirrors it, exactly like the switch of the distribution does. It redraws
     * nothing: the numbers of the parts are written into the fields of the form by
     * the script that reads the switch.
     *
     * @return Response
     */
    public function actionNumberPartsSave(): Response
    {
        Yii::$app->session->set(
            'numberParts',
            $this->normalizeNumberParts($this->request->post('numberParts')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Stores the shape a part heading wears its number with and answers with the address
     * that mirrors it, exactly like the switch of the numbering does. Both of the states
     * travel in the one post: the checkbox changes the gate and leaves the field to mirror
     * the two rows, so a post of one of them alone would leave the session holding a shape
     * the form does not show. It redraws nothing — the numbers are written into the fields
     * by the script that reads the seven controls, and a state is kept even while the
     * numbering stands off, so the choice the reader made is there when it comes back on.
     *
     * @return Response
     */
    public function actionHeadingNumberTemplateSave(): Response
    {
        $session = Yii::$app->session;
        $session->set(
            'headingNumberCustom',
            $this->normalizeHeadingNumberCustom($this->request->post('headingNumberCustom')),
        );
        $session->set(
            'headingNumberTemplate',
            PublicationSettingsService::normalizeHeadingNumberTemplate($this->request->post('headingNumberTemplate')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Stores the marks the block of the heading stands with and answers with the
     * address that mirrors them, exactly like the switch of the numbering does. It
     * redraws nothing: a mark is laid over a heading by the script of the form at
     * the moment that heading leaves it, so the boxes only ever say what the next
     * heading is to be wears.
     *
     * @return Response
     */
    public function actionHeadingFormattingSave(): Response
    {
        Yii::$app->session->set(
            'headingFormatting',
            $this->normalizeHeadingFormatting($this->request->post('headingFormatting')),
        );

        return $this->asJson([
            'ok' => true,
            'url' => Url::to($this->publicationsUrl()),
        ]);
    }

    /**
     * Stores the length order of the topic list and answers with the forum block
     * redrawn from its beginning: the two buttons of the header stand the topics
     * up by the text each of them carries, so the reader gets its first page in
     * the new order. The order the posts of a discussion read in is none of its
     * business, and the block keeps showing the very same set of topics — only
     * another end of it.
     *
     * @return Response
     */
    public function actionForumTextOrderSave(): Response
    {
        Yii::$app->session->set(
            'forumTextOrder',
            $this->normalizeForumTextOrder($this->request->post('forumTextOrder')),
        );
        $data = $this->publicationsData();

        return $this->asJson([
            'ok' => true,
            'block' => 'forum',
            'url' => Url::to($this->publicationsUrl()),
            'blocks' => ['forum' => $this->renderPartial('_block_forum', $data)],
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
        $textOrder = $this->forumTextOrder();

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
                    $filters['withLinks'],
                    $filters['linksCount'],
                    $sorts['forumTopics'],
                    $sorts['forumPosts'],
                    $offset,
                    $textOrder,
                ),
                $this->forum->countTopics(
                    $filters['withImages'],
                    $filters['withPosts'],
                    $filters['imagesCount'],
                    $filters['withLinks'],
                    $filters['linksCount'],
                ),
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
            $filters['withLinks'],
            $filters['linksCount'],
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
        $titleFormats = $this->publicationTitleFormatsFromRequest(count($texts));
        $publishedAt = (string)($this->request->post('publicationAt', ''));
        $userTz = (string)($this->request->post('publicationTz', ''));
        $pressed = (string)$this->request->post('action', '');
        $action = $pressed === 'draft' || $pressed === 'home' ? $pressed : 'publish';
        $source = (string)($this->request->post('publicationSource', 'new'));
        $sourceId = $this->request->post('publicationSourceId');
        $sourceId = $sourceId === null || $sourceId === '' ? null : (int)$sourceId;
        $forumRef = $this->forumRefFromRequest();
        $individualParts = $this->partsPublication()
            && $this->request->post('individualParts') === '1' && count($texts) > 1;
        $partTimes = (array)$this->request->post('publicationPartAt', []);
        $partGroupId = trim((string)$this->request->post('publicationPartGroupId', ''));

        $ok = false;
        try {
            if ($action === 'home' && preg_match('/^[a-f0-9]{32}$/', $partGroupId) !== 1) {
                // «Сохранить по местам» is a rule about the rows a loaded group
                // stands in: a form that holds no group has no places to keep.
                throw new InvalidArgumentException(
                    '«Сохранить по местам» работает только с группой частей — откройте эту публикацию целиком.',
                );
            }

            $imageGroups = $this->withUploadedImages($this->imageGroupsFromRequest());
            $imageUrls = $imageGroups[0];

            if ($source === 'new') {
                $this->publications->saveParts(
                    $texts,
                    $imageGroups,
                    $formats,
                    $buttons,
                    $titles,
                    $titleFormats,
                    $publishedAt,
                    $action,
                    $forumRef,
                    $userTz ?: null,
                    $individualParts,
                    array_values($partTimes),
                    $partGroupId,
                );
                Yii::$app->session->setFlash('success', $action === 'draft'
                    ? 'Черновик сохранён.'
                    : 'Публикация сохранена и будет отправлена в канал в заданное время.');
            } else {
                if (preg_match('/^[a-f0-9]{32}$/', $partGroupId) === 1) {
                    // The form was opened on a part of a group and holds all of
                    // them: each goes back to the row it was taken out of.
                    $this->publications->saveGroupFromForm(
                        $texts,
                        $imageGroups,
                        $formats,
                        $buttons,
                        $titles,
                        $titleFormats,
                        $this->publicationSlotsFromRequest(count($texts)),
                        array_values($partTimes),
                        $publishedAt,
                        $action,
                        $partGroupId,
                        $userTz ?: null,
                    );
                    Yii::$app->session->setFlash('success', 'Изменения сохранены.');

                    $ok = true;

                    return $this->finishPublicationsRequest($ok);
                }

                if (count($texts) > 1) {
                    throw new InvalidArgumentException(
                        'В форме несколько частей, а открытая запись их не имеет: сохраните эту публикацию '
                        . 'одним полем текста или отправьте её как новую запись.',
                    );
                }

                $this->publications->saveFromForm(
                    $texts[0],
                    $imageUrls,
                    $formats[0],
                    $buttons[0],
                    $titles[0],
                    $titleFormats[0],
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
     * The link buttons of the publication form as one keyboard per part: the
     * rows of the shared «Кнопки-ссылки» field carry the buttons of the first
     * part, the cloned text blocks add a block of rows each while the
     * «Кнопка-ссылка в каждой части» switch is on, so a submission brings the rows
     * of a part in the place of that part. A part whose rows are missing — its box
     * stayed switched off — goes to the channel without a keyboard.
     *
     * @return LinkButtons[]
     */
    private function publicationButtonsFromRequest(int $parts): array
    {
        $labels = $this->partRowGroupsFromRequest('publicationButtonText', 'publicationPartButtonText', $parts);
        $urls = $this->partRowGroupsFromRequest('publicationButtonUrl', 'publicationPartButtonUrl', $parts);

        $buttons = [];
        for ($index = 0; $index < $parts; $index++) {
            $buttons[] = LinkButtons::fromPairs($labels[$index], $urls[$index]);
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
     * The marking of those headings as one entity list per part: the editor of a
     * heading keeps its plain text in the field the reader types into and the
     * spans of that text in the hidden positional field beside it, so a
     * submission brings the headings and their marks in the same order. A part
     * whose field is missing — a record written before a heading could be marked
     * — comes back with no marks of its own and goes out under the plain bold
     * line the composition puts over it.
     *
     * @return MessageEntities[]
     */
    private function publicationTitleFormatsFromRequest(int $parts): array
    {
        $fields = $this->partFieldsFromRequest(
            'publicationTitleFormatting',
            'publicationPartTitleFormatting',
        );

        $formats = [];
        for ($index = 0; $index < $parts; $index++) {
            $formats[] = MessageEntities::fromStored($fields[$index] ?? null);
        }

        return $formats;
    }

    /**
     * The row every part of the form goes back to. Each text block of the form
     * carries, in a hidden field of its own, the block and the id of the record
     * it was loaded with, so a save rewrites that very row rather than making a
     * copy of it. A part the reader wrote himself — and one a cut gave birth to —
     * brings nothing here and is written as a new row of the group.
     *
     * @return array<int, array{id: int|null, source: string}>
     */
    private function publicationSlotsFromRequest(int $parts): array
    {
        $raw = $this->request->post('publicationPartSlot', '');
        $fields = is_array($raw) ? array_values($raw) : [$raw];

        $slots = [];
        for ($index = 0; $index < $parts; $index++) {
            $held = isset($fields[$index]) ? json_decode((string)$fields[$index], true) : null;
            $id = is_array($held) && isset($held['id']) ? (int)$held['id'] : null;
            $slots[] = [
                'id' => $id,
                'source' => is_array($held) && isset($held['source']) ? (string)$held['source'] : '',
            ];
        }

        return $slots;
    }

    /**
     * One kind of the per-part fields of the form: the shared field of the first
     * part followed by the cloned fields of the parts that came after it, so
     * the two lists line up with the parts the way the albums do. A field whose
     * name wears brackets — the marks of a heading do, the marks of the body do
     * — comes in as a list holding it alone, and is opened up here so the part
     * it belongs to keeps its place either way.
     *
     * @return array<int, mixed>
     */
    private function partFieldsFromRequest(string $shared, string $perPart): array
    {
        $first = $this->request->post($shared, '');
        $raw = $this->request->post($perPart, []);

        return array_merge(
            is_array($first) ? array_values($first) : [$first],
            is_array($raw) ? array_values($raw) : [$raw],
        );
    }

    /**
     * One kind of the per-part row lists of the form: the rows of the shared
     * field of the first part, then the rows of every part that came after it. A
     * part's own field is named after its place in the list of parts the way the
     * album picker is, so the first part is served by the shared field and the
     * names run one behind the parts. The rows of a part come back in the order
     * they stand in, so the labels and the addresses pair up by position.
     *
     * @return array<int, array<int, mixed>>
     */
    private function partRowGroupsFromRequest(string $shared, string $perPart, int $parts): array
    {
        $names = [$shared];
        for ($index = 1; $index < $parts; $index++) {
            $names[] = $perPart . ($index - 1);
        }

        $groups = [];
        foreach ($names as $name) {
            $raw = $this->request->post($name, []);
            $groups[] = is_array($raw) ? array_values($raw) : [$raw];
        }

        return $groups;
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
     * The masks of the publication form as one list of tokens per part, standing
     * in the places the fields of links keep: «publicationImageOrder» carries the
     * album of the first part, and every part after it adds one field of
     * «publicationPartImageOrder[]», so a submission weaves each album with its
     * own pattern.
     *
     * @return string[][]
     */
    private function maskGroupsFromRequest(): array
    {
        $groups = [$this->albumMaskFromValue($this->request->post('publicationImageOrder', ''))];
        $raw = $this->request->post('publicationPartImageOrder', []);

        foreach (is_array($raw) ? array_values($raw) : [$raw] as $field) {
            $groups[] = $this->albumMaskFromValue($field);
        }

        return $groups;
    }

    /**
     * One mask field of the form: the tokens of its album, one per picture,
     * «l» for the next link of the text area and «f» for the next file of the
     * picker. A value that carries anything else is read as no mask at all,
     * which is the plain order of an album nobody has arranged.
     *
     * @return string[]
     */
    private function albumMaskFromValue(mixed $raw): array
    {
        $value = trim((string)$raw);
        $tokens = '[' . self::ALBUM_LINK_TOKEN . self::ALBUM_FILE_TOKEN . ']';

        if ($value === '' || preg_match('/^' . $tokens . '+$/', $value) !== 1) {
            return [];
        }

        return str_split($value);
    }

    /**
     * Whether a mask can be trusted over an album: it may name fewer pictures
     * than the album holds — a line typed or a file picked after the form wrote
     * the mask stands behind the rest — but one that names more, or carries a
     * token of another page, is not this album's pattern.
     *
     * @param string[] $mask
     */
    private function isAlbumMask(array $mask, int $count): bool
    {
        if (count($mask) > $count) {
            return false;
        }

        foreach ($mask as $token) {
            if ($token !== self::ALBUM_LINK_TOKEN && $token !== self::ALBUM_FILE_TOKEN) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order an album holds without anyone having moved a picture in it: the
     * links of its field, then the pictures its files became. This is the order
     * the portal has always used, and the one an album falls back to.
     *
     * @param string[] $links
     * @param string[] $stored
     * @return string[]
     */
    private function plainAlbumMask(array $links, array $stored): array
    {
        return array_merge(
            array_fill(0, count($links), self::ALBUM_LINK_TOKEN),
            array_fill(0, count($stored), self::ALBUM_FILE_TOKEN),
        );
    }

    /**
     * The album as the channel gets it: the links typed into the field woven
     * with the links the stored files became, along the pattern the mask of that
     * album carries. A token whose field has run out is passed over, and what no
     * token named comes after the rest in the order its own field keeps it.
     *
     * @param string[] $links
     * @param string[] $stored
     * @param string[] $mask
     * @return string[]
     */
    private function weaveAlbum(array $links, array $stored, array $mask): array
    {
        $urls = array_values($links);
        $files = array_values($stored);
        $pattern = $this->isAlbumMask($mask, count($urls) + count($files))
            ? $mask
            : $this->plainAlbumMask($urls, $files);
        $pictures = [];

        foreach ($pattern as $token) {
            if ($token === self::ALBUM_LINK_TOKEN && $urls !== []) {
                $pictures[] = array_shift($urls);
            } elseif ($token === self::ALBUM_FILE_TOKEN && $files !== []) {
                $pictures[] = array_shift($files);
            }
        }

        return array_merge($pictures, $urls, $files);
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
        $masks = $this->maskGroupsFromRequest();
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
            $stored = [];

            foreach ($uploads as [$file, $extension]) {
                $stored[] = $this->storeUpload($file, $extension);
            }

            // The pictures the files became stand where the reader put them: an
            // album whose form was arranged by rows is woven by its mask, and an
            // album that brought no mask, or one that does not fit it, keeps the
            // plain order of links first and stored pictures behind.
            $mask = array_key_exists($index, $masks) ? $masks[$index] : [];
            $groups[$index] = $this->weaveAlbum($groups[$index], $stored, $mask);
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
     * Lets one part of a stored group into the queue by the button of its row in
     * the posts list: the part takes the time that row carries and starts only
     * after the part above it has reached the channel.
     *
     * @return Response
     */
    public function actionPublicationPartSchedule(): Response
    {
        $groupId = trim((string)$this->request->post('publicationPartGroupId', ''));
        $order = (int)$this->request->post('publicationPartOrder', 0);
        $publishedAt = (string)$this->request->post('publicationAt', '');
        $userTz = (string)$this->request->post('publicationTz', '');

        $ok = false;
        try {
            $this->publications->releasePart($groupId, $order, $publishedAt, $userTz ?: null);
            Yii::$app->session->setFlash('success', 'Часть поставлена в очередь публикации.');
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok);
    }

    /**
     * Answers what the form has to load when a part of a stored group is opened
     * for editing: every part of that group which still lives in the publications
     * and the drafts blocks, and of the deleted block only the row the reader
     * clicked. Each part comes with the block it came out of, so the form can
     * say where every one of them lives and send each one back there.
     *
     * @return Response
     */
    public function actionPublicationPartGroup(): Response
    {
        $groupId = trim((string)$this->request->post('publicationPartGroupId', ''));
        $source = (string)$this->request->post('publicationSource', '');
        $sourceId = (int)$this->request->post('publicationId', 0);

        if (preg_match('/^[a-f0-9]{32}$/', $groupId) !== 1) {
            return $this->asJson(['ok' => false, 'error' => 'Группа частей не названа.']);
        }

        $rows = $this->publications->formRowsOfGroup($groupId, $source, $sourceId);

        return $this->asJson([
            'ok' => true,
            'parts' => array_map([$this, 'partFormPayload'], $rows),
        ]);
    }

    /**
     * One row of a group as the form reads it. `source` is the block the row is
     * moved back to, `habitat` is what its badge says, and the album, the
     * highlighting, the marking of the heading and the keyboard come in the same
     * shapes the list rows of
     * that block already carry in their own attributes.
     */
    private function partFormPayload(PublicationData $row): array
    {
        $habitat = (string)$row->habitat;

        return [
            'id' => $row->id,
            'source' => $habitat === 'draft' || $habitat === 'deleted' ? $habitat : 'post',
            'habitat' => $habitat,
            'order' => (int)$row->partOrder,
            'text' => $row->text,
            'title' => $row->title,
            'images' => implode("\n", $row->imageUrls),
            'formatting' => $row->formatting->toJson(),
            'titleFormatting' => $row->titleFormatting->toJson(),
            'buttons' => $row->buttons->toJson(),
            'publishedAt' => (string)($row->publishedAt ?? ''),
        ];
    }

    /**
     * Saves one part of the form as a draft by the «Сохранить» at its corner. A part
     * the reader invented holds no row to move between the blocks, so this writes
     * its first one and answers with the id it got: the block of the form has to
     * know the row the next save of that part writes into. The rest of the form
     * stands where the reader left it.
     *
     * @return Response
     */
    public function actionPublicationPartDraft(): Response
    {
        $texts = $this->publicationTextsFromRequest();
        $formats = $this->publicationFormatsFromRequest(count($texts));
        $buttons = $this->publicationButtonsFromRequest(count($texts));
        $titles = $this->publicationTitlesFromRequest(count($texts));
        $titleFormats = $this->publicationTitleFormatsFromRequest(count($texts));
        $albums = $this->imageGroupsFromRequest();
        $partGroupId = trim((string)$this->request->post('publicationPartGroupId', ''));

        $ok = false;
        $extra = [];
        try {
            $index = (int)$this->request->post('publicationPartIndex', -1);

            if ($index < 0 || $index >= count($texts)) {
                throw new InvalidArgumentException('Часть публикации не найдена в форме.');
            }

            if ($partGroupId !== '' && preg_match('/^[a-f0-9]{32}$/', $partGroupId) !== 1) {
                throw new InvalidArgumentException('Группа частей не названа.');
            }

            // The files go through the storage for this one part: the pictures the
            // reader picked for another part belong to no row this request writes.
            $uploads = $this->withUploadedImages([$index => $albums[$index] ?? []]);

            $draftId = $this->publications->savePartAsDraft(
                $texts[$index],
                $uploads[$index] ?? [],
                $formats[$index],
                $buttons[$index],
                $titles[$index],
                $titleFormats[$index],
                $partGroupId,
            );

            $extra = ['partId' => $draftId];
            Yii::$app->session->setFlash('success', 'Часть сохранена в черновиках.');
            $ok = true;
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok, $extra);
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
        $extra = [];
        try {
            if ($source === 'deleted') {
                $movedId = $this->publications->moveDeletedToDraft((int)$id);
                Yii::$app->session->setFlash('success', 'Удалённая запись перемещена в черновики.');
            } else {
                $movedId = $this->publications->movePostToDraft((int)$id);
                Yii::$app->session->setFlash('success', 'Публикация перемещена в черновики.');
            }
            $ok = true;
            // The record takes a new row id in the block it moves to, and the form
            // of a part has to know it: its next button would ask for the row that
            // is already gone.
            $extra = ['partId' => $movedId];
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok, $extra);
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
        $extra = [];
        try {
            if ($source === 'draft') {
                $deletedId = $this->publications->deleteDraft((int)$id);
            } elseif ($source === 'post') {
                $deletedId = $this->publications->deletePost((int)$id);
            } else {
                throw new InvalidArgumentException('Не указана удаляемая запись.');
            }
            Yii::$app->session->setFlash('success', 'Запись удалена.');
            $ok = true;
            // Same as the move to drafts: the row of the deleted block is a new one,
            // and a part of the form has to carry its id to restore itself later.
            $extra = ['partId' => $deletedId];
        } catch (InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->finishPublicationsRequest($ok, $extra);
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
