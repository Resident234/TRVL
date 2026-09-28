<?php

declare(strict_types=1);

namespace app\shared\Publications\Service;

use app\shared\Forum\Contract\ForumHttpClientInterface;
use app\shared\Forum\Contract\ForumPublicationMapGatewayInterface;
use app\shared\Publications\Contract\PublicationForumLinkStoreInterface;
use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\ForumPublicationRef;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Infrastructure\TelegramApiException;
use app\shared\Telegram\Service\ChannelService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use yii;

/**
 * Use-cases for channel publications: saving form data as drafts or
 * scheduled posts, and sending due scheduled posts to Telegram.
 *
 * Form saves touch the database only; delivery to Telegram happens
 * exclusively in publishDue(), which is run by the periodic task.
 */
final class PublicationsService
{
    public function __construct(
        private readonly PublicationRepositoryInterface $publications,
        private ?LoggerInterface $logger,
        private readonly object $channel,
        private readonly ?ForumPublicationMapGatewayInterface $forumMap = null,
        private readonly ?PublicationForumLinkStoreInterface $forumLinks = null,
        private readonly ?ForumHttpClientInterface $forumHttpClient = null,
        private readonly ?PublicationSettingsService $settings = null,
    ) {
    }

    /**
     * Scheduled and published posts, newest `published_at` first (oldest
     * first when $oldestFirst asks). A zero limit reads every row.
     *
     * @return PublicationData[]
     */
    public function posts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->publications->allPosts($limit, $offset, $oldestFirst);
    }

    /**
     * Drafts, newest `updated_at` first (oldest first when $oldestFirst
     * asks). A zero limit reads every row.
     *
     * @return PublicationData[]
     */
    public function drafts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->publications->allDrafts($limit, $offset, $oldestFirst);
    }

    /**
     * Soft-deleted records, newest `updated_at` first (oldest first when
     * $oldestFirst asks). A zero limit reads every row.
     *
     * @return PublicationData[]
     */
    public function deleted(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->publications->allDeleted($limit, $offset, $oldestFirst);
    }

    /**
     * @return array{posts: int, drafts: int, deleted: int} how many rows each
     * of the three lists holds, paged or not
     */
    public function listTotals(): array
    {
        return [
            'posts' => $this->countPosts(),
            'drafts' => $this->countDrafts(),
            'deleted' => $this->countDeleted(),
        ];
    }

    public function countPosts(): int
    {
        return $this->publications->countPosts();
    }

    public function countDrafts(): int
    {
        return $this->publications->countDrafts();
    }

    public function countDeleted(): int
    {
        return $this->publications->countDeleted();
    }

    /**
     * Saves the form data as a draft. When the form was filled from a
     * forum topic or post, a map row with an empty telegram_id is
     * inserted and the "forum entity - publication" link is remembered
     * in the temporary store.
     *
     * @param string[] $imageUrls
     * @throws InvalidArgumentException when the text is empty or its
     * formatting does not fit it
     */
    public function saveDraft(
        string $text,
        array $imageUrls,
        MessageEntities $formatting,
        LinkButton $button,
        string $title,
        ?ForumPublicationRef $forumRef = null,
    ): void {
        $this->assertPartValid($title, $text, $formatting);
        $button->assertValid();
        $id = $this->publications->createDraft($text, $imageUrls, $this->now(), $formatting, $button, $title);
        $this->bindForumRef($id, $forumRef);
    }

    /**
     * Saves the form data as a scheduled (or immediately due) post.
     * When the form was filled from a forum topic or post, a map row
     * with an empty telegram_id is inserted and the
     * "forum entity - publication" link is remembered.
     *
     * @param string[] $imageUrls
     * @throws InvalidArgumentException when the text is empty or the date is invalid
     */
    public function schedulePost(
        string $text,
        array $imageUrls,
        MessageEntities $formatting,
        LinkButton $button,
        string $title,
        string $publishedAt,
        ?ForumPublicationRef $forumRef = null,
        ?string $userTimezone = null,
    ): void {
        $this->assertPartValid($title, $text, $formatting);
        $button->assertValid();
        $normalized = $this->normalizeDate($publishedAt, $userTimezone);
        $id = $this->publications->createPost(
            $text,
            $imageUrls,
            $normalized,
            $this->now(),
            $formatting,
            $button,
            $title,
        );
        $this->bindForumRef($id, $forumRef);
    }

    /**
     * Saves the text fields of the publication form, which clones its input
     * when a text goes past the Telegram limit. A single field is saved
     * exactly as before; several parts become one record each, offset from
     * the previous part by the configured parts gap, so the periodic task
     * drains them in order.
     * Those records are written by one repository call, so a failure leaves
     * neither a half publication nor a part that never reaches the channel.
     * The forum link belongs to the first part, which is the message the
     * channel thread continues from. Every part goes out with the album the
     * form holds in its own field: $imageGroups is one list of URLs per part,
     * in the order of the parts, and a part that brings no list of its own is
     * saved without images. $formats is the highlighting of every part the
     * same way, one entity list per part, counted from the same text the form
     * shows — the trim() below moves it along with the text it describes.
     * $buttons is the link button of every part in the same order again; a part
     * with an empty one goes to the channel without a keyboard. $titles is the
     * heading of every part in the same order again; a part with an empty one
     * goes out as a plain message, and the heading of a numbered part takes the
     * «Часть N» line out of its text and puts it at the end of that heading.
     *
     * @param string[] $texts
     * @param array<int, string[]> $imageGroups
     * @param MessageEntities[] $formats
     * @param LinkButton[] $buttons
     * @param string[] $titles
     * @throws InvalidArgumentException when a part is empty or longer than the
     * Telegram limit, when its formatting does not fit it, when its button is
     * half-filled or malformed, or when the date is invalid
     */
    public function saveParts(
        array $texts,
        array $imageGroups,
        array $formats,
        array $buttons,
        array $titles,
        string $publishedAt,
        string $action,
        ?ForumPublicationRef $forumRef = null,
        ?string $userTimezone = null,
    ): void {
        $parts = [];
        $formatting = [];
        $keyboard = [];
        $headings = [];
        foreach (array_values($texts) as $index => $text) {
            $parts[] = trim($text);
            $formatting[] = self::trimFormatting($text, $formats[$index] ?? new MessageEntities());
            $keyboard[] = $buttons[$index] ?? LinkButton::empty();
            $headings[] = trim((string)($titles[$index] ?? ''));
        }
        $albums = array_values($imageGroups);

        foreach ($parts as $index => $text) {
            $this->assertPartValid($headings[$index], $text, $formatting[$index]);
            $keyboard[$index]->assertValid();
        }

        if (count($parts) === 1) {
            if ($action === 'draft') {
                $this->saveDraft($parts[0], $albums[0] ?? [], $formatting[0], $keyboard[0], $headings[0], $forumRef);

                return;
            }

            $this->schedulePost(
                $parts[0],
                $albums[0] ?? [],
                $formatting[0],
                $keyboard[0],
                $headings[0],
                $publishedAt,
                $forumRef,
                $userTimezone,
            );

            return;
        }

        $now = $this->now();

        if ($action === 'draft') {
            $rows = [];
            foreach ($parts as $index => $text) {
                $rows[] = [
                    'text' => $text,
                    'imageUrls' => $albums[$index] ?? [],
                    'formatting' => $formatting[$index],
                    'button' => $keyboard[$index],
                    'title' => $headings[$index],
                ];
            }

            $this->bindForumRef($this->publications->createDrafts($rows, $now), $forumRef);

            return;
        }

        $firstAt = $this->normalizeDate($publishedAt, $userTimezone);
        $rows = [];
        foreach ($parts as $index => $text) {
            $rows[] = [
                'text' => $text,
                'imageUrls' => $albums[$index] ?? [],
                'formatting' => $formatting[$index],
                'button' => $keyboard[$index],
                'title' => $headings[$index],
                'publishedAt' => $this->shiftDate($firstAt, $index * $this->partsOffsetMinutes()),
            ];
        }

        $this->bindForumRef($this->publications->createPosts($rows, $now), $forumRef);
    }

    /**
     * The highlighting of a part as it comes from the form, moved over the
     * whitespace trim() took off its head and cut down to what is left of it
     * before the trailing whitespace goes. Leading whitespace is ASCII, so its
     * byte count is also its count in UTF-16 code units.
     */
    private static function trimFormatting(string $text, MessageEntities $formatting): MessageEntities
    {
        $lead = strlen($text) - strlen(ltrim($text));

        return $formatting->slice($lead, MessageEntities::utf16Length(trim($text)));
    }

    /**
     * Sends all due posts (empty telegram_id, published_at <= now)
     * to the channel, from the smallest id to the biggest one.
     *
     * Each post is published independently: a failure is logged and
     * does not stop the remaining posts; failed posts keep an empty
     * telegram_id and are retried on the next run.
     *
     * @return array{processed: int, published: int, failed: int}
     */
    public function publishDue(): array
    {
        $stats = ['processed' => 0, 'published' => 0, 'failed' => 0];
        $now = $this->now();

        foreach ($this->publications->findDueForPublishing($now) as $post) {
            $stats['processed']++;

            try {
                $telegramId = $this->publishToTelegram($post);
                $sentAt = $this->now();
                $this->publications->storeTelegramId($post->id, $telegramId, $sentAt, $sentAt);
                $this->stampForumMapTelegramId($post->id, $telegramId);
                $stats['published']++;
            } catch (TelegramApiException $e) {
                $stats['failed']++;
                $this->logger?->error(
                    'Publication {id} failed to reach Telegram: {error}',
                    ['id' => $post->id, 'error' => $e->getMessage()],
                );
            }
        }

        return $stats;
    }

    /**
     * Closes all pending soft-deleted records: publications_deleted
     * rows with an empty deleted_at are deleted from Telegram by
     * their telegram_id, from the smallest id to the biggest one, and
     * then stamped with the actual removal time. Records without a
     * telegram_id (drafts, scheduled posts) never had a message in
     * the channel and are stamped right away.
     *
     * Each record is processed independently: a failure is logged and
     * does not stop the remaining records; failed records keep an
     * empty deleted_at and are retried on the next run.
     *
     * @return array{processed: int, deleted: int, failed: int}
     */
    public function deleteDue(): array
    {
        $stats = ['processed' => 0, 'deleted' => 0, 'failed' => 0];

        foreach ($this->publications->findPendingChannelDeletion() as $record) {
            $stats['processed']++;

            if ($record->telegramId === null) {
                $this->publications->storeDeletedAt($record->id, $this->now());
                $stats['deleted']++;

                continue;
            }

            try {
                $this->deleteFromTelegram($record);
                $this->publications->storeDeletedAt($record->id, $this->now());
                $stats['deleted']++;
            } catch (TelegramApiException | RuntimeException $e) {
                $stats['failed']++;
                $this->logger?->error(
                    'Deleted publication {id} failed to leave Telegram: {error}',
                    ['id' => $record->id, 'error' => $e->getMessage()],
                );
            }
        }

        return $stats;
    }

    /**
     * Updates all pending archived edits in the channel:
     * publications_edited rows with an empty edited_at get their
     * message content replaced with the record's text field by the
     * telegram_id, from the smallest id to the biggest one, and then
     * stamped with the actual update time.
     *
     * Each record is processed independently: a failure is logged and
     * does not stop the remaining records; failed records keep an
     * empty edited_at and are retried on the next run.
     *
     * @return array{processed: int, edited: int, failed: int}
     */
    public function editDue(): array
    {
        $stats = ['processed' => 0, 'edited' => 0, 'failed' => 0];

        foreach ($this->publications->findPendingChannelEdits() as $record) {
            $stats['processed']++;

            try {
                $this->editInTelegram($record);
                $this->publications->storeEditedAt($record->id, $this->now());
                $stats['edited']++;
            } catch (TelegramApiException | RuntimeException | InvalidArgumentException $e) {
                $stats['failed']++;
                $this->logger?->error(
                    'Edited publication {id} failed to reach Telegram: {error}',
                    ['id' => $record->id, 'error' => $e->getMessage()],
                );
            }
        }

        return $stats;
    }

    /**
     * Soft-deletes a post by the "Удалить" button: the record moves
     * to publications_deleted. created_at and published_at keep
     * their values, updated_at is set to the current time, deleted_at
     * stays empty — the periodic deleteDue() task removes the message
     * from the channel (when it has one) and stamps deleted_at.
     *
     * @throws InvalidArgumentException when the post does not exist
     */
    public function deletePost(int $id): void
    {
        $post = $this->publications->deletePost($id);
        $deletedId = $this->publications->insertDeletedWithHistory($post, $this->now());
        $this->moveForumLink($id, $deletedId);
    }

    /**
     * Soft-deletes a draft by the "Удалить" button: the record moves
     * to publications_deleted. created_at keeps its value (the draft
     * has no published_at, it stays NULL), updated_at is set to the
     * current time, deleted_at stays empty.
     *
     * @throws InvalidArgumentException when the draft does not exist
     */
    public function deleteDraft(int $id): void
    {
        $draft = $this->publications->deleteDraft($id);
        $deletedId = $this->publications->insertDeletedWithHistory($draft, $this->now());
        $this->moveForumLink($id, $deletedId);
    }

    /**
     * Publishes a draft directly by the "Опубликовать" button in the
     * drafts list, without opening the editing form.
     *
     * The draft row is deleted and inserted into the posts table:
     * published_at and updated_at are set to the current time,
     * created_at is preserved. The post is due immediately, so the
     * periodic publishDue() task sends it to Telegram.
     *
     * @throws InvalidArgumentException when the draft does not exist
     */
    public function publishDraft(int $id): void
    {
        $draft = $this->publications->deleteDraft($id);
        $now = $this->now();
        $postId = $this->publications->insertPostWithHistory(
            new PublicationData(
                $draft->id,
                $draft->text,
                $draft->imageUrls,
                null,
                $now,
                $draft->createdAt,
                $draft->updatedAt,
                formatting: $draft->formatting,
                button: $draft->button,
                title: $draft->title,
            ),
            $now,
        );
        $this->moveForumLink($id, $postId);
    }

    /**
     * Schedules a draft by the "Запланировать публикацию" modal:
     * the draft moves to the posts table with the publication time
     * from the modal's date-time field. created_at is preserved,
     * updated_at is set to the current time; the periodic
     * publishDue() task sends the post when the time comes.
     *
     * @throws InvalidArgumentException when the draft does not exist or the date is invalid
     */
    public function scheduleDraft(int $id, string $publishedAt, ?string $userTimezone = null): void
    {
        $this->assertDraftExists($id);
        $draft = $this->publications->deleteDraft($id);
        $now = $this->now();
        $postId = $this->publications->insertPostWithHistory(
            new PublicationData(
                $draft->id,
                $draft->text,
                $draft->imageUrls,
                null,
                $this->normalizeDate($publishedAt, $userTimezone),
                $draft->createdAt,
                $draft->updatedAt,
                formatting: $draft->formatting,
                button: $draft->button,
                title: $draft->title,
            ),
            $now,
        );
        $this->moveForumLink($id, $postId);
    }

    /**
     * Makes a scheduled post due immediately by the "Опубликовать"
     * button in the posts list, without opening the editing form.
     *
     * published_at and updated_at are set to the current time, so the
     * periodic publishDue() task sends it to Telegram on its next run.
     *
     * @throws InvalidArgumentException when the post does not exist
     */
    public function publishPostNow(int $id): void
    {
        $post = $this->publications->findPost($id);
        if ($post === null) {
            throw new InvalidArgumentException("Публикация #{$id} не найдена.");
        }

        $now = $this->now();
        $this->publications->updatePost($id, $post->text, $post->imageUrls, $now, $now, $post->formatting, $post->button, $post->title);
    }

    /**
     * Publishes a single post through the Telegram channel service:
     * with photos it goes as a photo album (the caption on the first
     * photo, the full text as a separate message when it does not fit
     * the caption limit), without photos as a plain text message. The
     * heading of the record, when it has one, is composed into that
     * message as its bold first line. The highlighting goes with it as
     * message entities, and the link button of the record as the
     * keyboard under the message.
     *
     * @throws RuntimeException when the bot token is not configured
     * @throws TelegramApiException on API failure
     */
    private function publishToTelegram(PublicationData $post): int
    {
        // If we have images and forum HTTP client, download images and upload as files
        if ($post->imageUrls !== [] && $this->forumHttpClient !== null && method_exists($this->channel, 'publishPhotos')) {
            return $this->channel->publishPhotos(
                $post->messageText(),
                $this->prepareImageUrlsForTelegram($post->imageUrls),
                $post->messageEntities(),
                $post->button,
            );
        }

        if ($post->imageUrls !== [] && method_exists($this->channel, 'publishPhotos')) {
            return $this->channel->publishPhotos(
                $post->messageText(),
                $post->imageUrls,
                $post->messageEntities(),
                $post->button,
            );
        }

        if (!method_exists($this->channel, 'publishText')) {
            throw new RuntimeException('Telegram-канал не сконфигурирован.');
        }

        return $this->channel->publishText($post->messageText(), $post->messageEntities(), $post->button);
    }

    /**
     * Prepares image URLs for Telegram publishing.
     * If forum HTTP client is available, downloads forum images and returns local file paths
     * that will be uploaded to Telegram as multipart/form-data.
     *
     * @param string[] $imageUrls
     * @return string[] - local file paths or original URLs
     */
    private function prepareImageUrlsForTelegram(array $imageUrls): array
    {
        if ($this->forumHttpClient === null) {
            return $imageUrls;
        }

        $preparedUrls = [];
        foreach ($imageUrls as $url) {
            // Check if URL is from forum.awd.ru which requires authentication
            if (str_starts_with($url, 'https://forum.awd.ru/') || str_starts_with($url, 'http://forum.awd.ru/')) {
                try {
                    $localPath = $this->downloadForumImage($url);
                    if ($localPath !== null) {
                        $preparedUrls[] = $localPath;
                        continue;
                    }
                } catch (\Throwable $e) {
                    $this->logger?->warning('Failed to download forum image, will try direct URL: {error}', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            // For other URLs, use as-is (Telegram will try to fetch)
            $preparedUrls[] = $url;
        }

        return $preparedUrls;
    }

    /**
     * Downloads an image from forum.awd.ru using authenticated HTTP client.
     *
     * @return string|null - local file path or null on failure
     */
    private function downloadForumImage(string $url): ?string
    {
        try {
            $content = $this->forumHttpClient->get($url);

            // Determine file extension from URL or Content-Type
            $extension = $this->guessImageExtension($url, $content);
            $tempFile = sys_get_temp_dir() . '/forum_img_' . bin2hex(random_bytes(8)) . '.' . $extension;

            if (file_put_contents($tempFile, $content) === false) {
                $this->logger?->error('Failed to save downloaded forum image to temp file', ['url' => $url]);
                return null;
            }

            return $tempFile;
        } catch (\Throwable $e) {
            $this->logger?->error('Error downloading forum image', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Guesses image extension from URL or content.
     */
    private function guessImageExtension(string $url, string $content): string
    {
        // Try to get from URL
        $path = parse_url($url, PHP_URL_PATH);
        if ($path !== false) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext !== '') {
                return strtolower($ext);
            }
        }

        // Try to detect from content (magic bytes)
        if (strlen($content) >= 12) {
            // JPEG
            if (substr($content, 0, 3) === "\xFF\xD8\xFF") return 'jpg';
            // PNG
            if (substr($content, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") return 'png';
            // GIF
            if (substr($content, 0, 6) === "GIF87a" || substr($content, 0, 6) === "GIF89a") return 'gif';
            // WebP
            if (substr($content, 0, 12) === "RIFF" && substr($content, 8, 4) === "WEBP") return 'webp';
        }

        return 'jpg'; // default
    }

    /**
     * Removes a soft-deleted record's message from the channel.
     *
     * @throws RuntimeException when the bot token is not configured
     * @throws TelegramApiException on API failure
     */
    private function deleteFromTelegram(PublicationData $record): void
    {
        if (!method_exists($this->channel, 'deletePost')) {
            throw new RuntimeException('Telegram-канал не сконфигурирован.');
        }

        if ($record->telegramId === null) {
            throw new RuntimeException(
                "Публикация #{$record->id} не имеет telegram_id — нечего удалять из канала.",
            );
        }

        $this->channel->deletePost($record->telegramId);
    }

    /**
     * Replaces an archived edit's message content in the channel with the
     * message the record composes: its heading, when it has one, as the bold
     * first line over its text field, with the formatting and the link button.
     *
     * @throws RuntimeException when the bot token is not configured
     * @throws TelegramApiException on API failure
     */
    private function editInTelegram(PublicationData $record): void
    {
        if (!method_exists($this->channel, 'editPostText')) {
            throw new RuntimeException('Telegram-канал не сконфигурирован.');
        }

        if ($record->telegramId === null) {
            throw new RuntimeException(
                "Публикация #{$record->id} не имеет telegram_id — нечего редактировать в канале.",
            );
        }

        $this->channel->editPostText(
            $record->telegramId,
            $record->messageText(),
            $record->messageEntities(),
            $record->button,
        );
    }

    /**
     * Saves the form data of a post or draft opened for editing.
     *
     * Six cross-table scenarios depending on the source record and the
     * pressed button:
     *
     *  - scheduled post + "Опубликовать": update the posts row in place;
     *  - scheduled post + "Сохранить": move to drafts;
     *  - published post + "Опубликовать": move to publications_edited;
     *  - published post + "Сохранить": move to drafts;
     *  - draft + "Опубликовать": move to posts;
     *  - draft + "Сохранить": update the drafts row in place.
     *
     * A soft-deleted source record adds two more scenarios: the
     * record leaves publications_deleted and returns as a post
     * ("Опубликовать") or as a draft ("Сохранить"); the pending
     * channel removal is cancelled with the archive row.
     *
     * created_at is always preserved; updated_at is set to the current
     * time on every move or update.
     *
     * @param string[] $imageUrls
     * @throws InvalidArgumentException when the text is empty, its formatting
     * does not fit it, the button is malformed, the date is invalid or the
     * source record does not exist
     */
    public function saveFromForm(
        string $text,
        array $imageUrls,
        MessageEntities $formatting,
        LinkButton $button,
        string $title,
        string $publishedAt,
        string $source,
        ?int $sourceId,
        string $action,
        ?string $userTimezone = null,
    ): void {
        $this->assertPartValid($title, $text, $formatting);
        $button->assertValid();
        $now = $this->now();

        if ($source === 'deleted') {
            $this->assertDeletedExists($sourceId);
            $record = $this->publications->deleteDeleted($sourceId);

            if ($action === 'draft') {
                // deleted + "Сохранить": restore as a draft.
                $draftId = $this->publications->insertDraftWithHistory(
                    new PublicationData(
                        $record->id,
                        $text,
                        $imageUrls,
                        null,
                        null,
                        $record->createdAt,
                        $record->updatedAt,
                        formatting: $formatting,
                        button: $button,
                        title: $title,
                    ),
                    $now,
                );
                $this->moveForumLink((int)$sourceId, $draftId);

                return;
            }

            // deleted + "Опубликовать": restore as a scheduled post.
            $postId = $this->publications->insertPostWithHistory(
                new PublicationData(
                    $record->id,
                    $text,
                    $imageUrls,
                    null,
                    $this->normalizeDate($publishedAt, $userTimezone),
                    $record->createdAt,
                    $record->updatedAt,
                    formatting: $formatting,
                    button: $button,
                    title: $title,
                ),
                $now,
            );
            $this->moveForumLink((int)$sourceId, $postId);

            return;
        }

        if ($source === 'draft') {
            $this->assertDraftExists($sourceId);
            if ($action === 'draft') {
                // 6) draft + "Сохранить": update in place.
                $this->publications->updateDraft($sourceId, $text, $imageUrls, $now, $formatting, $button, $title);

                return;
            }

            // 5) draft + "Опубликовать": move to posts.
            $draft = $this->publications->deleteDraft($sourceId);
            $postId = $this->publications->insertPostWithHistory(
                new PublicationData(
                    $draft->id,
                    $text,
                    $imageUrls,
                    null,
                    $this->normalizeDate($publishedAt, $userTimezone),
                    $draft->createdAt,
                    $draft->updatedAt,
                    formatting: $formatting,
                    button: $button,
                    title: $title,
                ),
                $now,
            );
            $this->moveForumLink((int)$sourceId, $postId);

            return;
        }

        $this->assertPostExists($sourceId);
        $post = $this->publications->findPost($sourceId);
        $isPublished = $post !== null && $post->telegramId !== null;

        if ($isPublished) {
            if ($action === 'publish') {
                // 3) published post + "Опубликовать": archive to publications_edited.
                $this->publications->archiveEdited($post, $now);
                $this->publications->deletePost($sourceId);
                $this->dropForumLink((int)$sourceId);

                return;
            }

            // 4) published post + "Сохранить": move to drafts.
            $this->publications->deletePost($sourceId);
            $draftId = $this->publications->insertDraftWithHistory($post, $now);
            $this->moveForumLink((int)$sourceId, $draftId);

            return;
        }

        if ($action === 'publish') {
            // 1) scheduled post + "Опубликовать": update in place.
            $this->publications->updatePost(
                $sourceId,
                $text,
                $imageUrls,
                $this->normalizeDate($publishedAt, $userTimezone),
                $now,
                $formatting,
                $button,
                $title,
            );

            return;
        }

        // 2) scheduled post + "Сохранить": move to drafts.
        $this->publications->deletePost($sourceId);
        $draftId = $this->publications->insertDraftWithHistory(
            new PublicationData(
                $post->id,
                $text,
                $imageUrls,
                null,
                null,
                $post->createdAt,
                $post->updatedAt,
                formatting: $formatting,
                button: $button,
                title: $title,
            ),
            $now,
        );
        $this->moveForumLink((int)$sourceId, $draftId);
    }

    /**
     * Moves a post (scheduled or published) to drafts directly,
     * without opening the editing form.
     *
     * The post row is deleted from the posts table and inserted into
     * the drafts table: created_at is preserved, updated_at is set
     * to the current time (the moment of the move).
     *
     * @throws InvalidArgumentException when the post does not exist
     */
    public function movePostToDraft(int $id): void
    {
        $post = $this->publications->deletePost($id);
        $draftId = $this->publications->insertDraftWithHistory($post, $this->now());
        $this->moveForumLink($id, $draftId);
    }

    /**
     * Publishes a soft-deleted record immediately by the
     * "Опубликовать" button in the deleted list, without opening the
     * editing form.
     *
     * The publications_deleted row is removed and the record is
     * inserted into the posts table: created_at is preserved,
     * published_at and updated_at are set to the current time, the
     * old telegram_id is dropped (the new message gets its own id
     * after the periodic publishDue() task sends it).
     *
     * @throws InvalidArgumentException when the record does not exist
     */
    public function publishDeleted(int $id): void
    {
        $record = $this->publications->deleteDeleted($id);
        $now = $this->now();
        $postId = $this->publications->insertPostWithHistory(
            new PublicationData(
                $record->id,
                $record->text,
                $record->imageUrls,
                null,
                $now,
                $record->createdAt,
                $record->updatedAt,
                formatting: $record->formatting,
                button: $record->button,
                title: $record->title,
            ),
            $now,
        );
        $this->moveForumLink($id, $postId);
    }

    /**
     * Schedules a soft-deleted record by the "Запланировать
     * публикацию" modal: the record moves from publications_deleted
     * to the posts table with the publication time from the modal's
     * date-time field. created_at is preserved, updated_at is set to
     * the current time, the old telegram_id is dropped; the periodic
     * publishDue() task sends the post when the time comes.
     *
     * @throws InvalidArgumentException when the record does not exist or the date is invalid
     */
    public function scheduleDeleted(int $id, string $publishedAt, ?string $userTimezone = null): void
    {
        $record = $this->publications->deleteDeleted($id);
        $now = $this->now();
        $postId = $this->publications->insertPostWithHistory(
            new PublicationData(
                $record->id,
                $record->text,
                $record->imageUrls,
                null,
                $this->normalizeDate($publishedAt, $userTimezone),
                $record->createdAt,
                $record->updatedAt,
                formatting: $record->formatting,
                button: $record->button,
                title: $record->title,
            ),
            $now,
        );
        $this->moveForumLink($id, $postId);
    }

    /**
     * Moves a soft-deleted record to drafts directly by the
     * "Перенести в черновик" button, without opening the editing
     * form.
     *
     * The publications_deleted row is removed and the record is
     * inserted into the drafts table: created_at is preserved,
     * updated_at is set to the current time (the moment of the
     * move), the old telegram_id is dropped.
     *
     * @throws InvalidArgumentException when the record does not exist
     */
    public function moveDeletedToDraft(int $id): void
    {
        $record = $this->publications->deleteDeleted($id);
        $draftId = $this->publications->insertDraftWithHistory($record, $this->now());
        $this->moveForumLink($id, $draftId);
    }

    /**
     * Binds a freshly saved publication to its forum source: inserts
     * a map row with an empty telegram_id (the element stops showing
     * up among the unprocessed ones) and remembers the link until the
     * publication reaches the channel.
     */
    private function bindForumRef(int $publicationId, ?ForumPublicationRef $ref): void
    {
        if ($ref === null || $this->forumMap === null) {
            return;
        }

        if ($ref->isTopic()) {
            $this->forumMap->storeTopicMapTelegramId($ref->id, null);
        } elseif ($ref->isPost()) {
            $this->forumMap->storePostMapTelegramId($ref->id, null);
        } else {
            return;
        }

        $this->forumLinks?->remember($publicationId, $ref);
    }

    /**
     * Writes the telegram_id into the map row of the forum entity the
     * publication was created from; the temporary link is consumed.
     */
    private function stampForumMapTelegramId(int $publicationId, int $telegramId): void
    {
        $ref = $this->forumLinks?->find($publicationId);

        if ($ref === null || $this->forumMap === null) {
            return;
        }

        if ($ref->isTopic()) {
            $this->forumMap->storeTopicMapTelegramId($ref->id, $telegramId);
        } elseif ($ref->isPost()) {
            $this->forumMap->storePostMapTelegramId($ref->id, $telegramId);
        }

        $this->forumLinks->forget($publicationId);
    }

    /**
     * Moves the temporary forum link to the new row id a record got
     * after being moved between the publication tables.
     */
    private function moveForumLink(int $fromPublicationId, int $toPublicationId): void
    {
        if ($fromPublicationId === $toPublicationId) {
            return;
        }

        $this->forumLinks?->move($fromPublicationId, $toPublicationId);
    }

    /**
     * Drops the temporary forum link of a record that left the
     * publication flow entirely (e.g. it was deleted).
     */
    private function dropForumLink(int $publicationId): void
    {
        $this->forumLinks?->forget($publicationId);
    }

    /**
     * @throws InvalidArgumentException when the record does not exist
     */
    private function assertDeletedExists(?int $id): void
    {
        if ($id === null || $this->publications->findDeleted($id) === null) {
            throw new InvalidArgumentException(
                $id === null ? 'Не указана редактируемая удалённая запись.' : "Удалённая запись #{$id} не найдена.",
            );
        }
    }

    /**
     * @throws InvalidArgumentException when the record does not exist
     */
    private function assertPostExists(?int $id): void
    {
        if ($id === null || $this->publications->findPost($id) === null) {
            throw new InvalidArgumentException(
                $id === null ? 'Не указана редактируемая публикация.' : "Публикация #{$id} не найдена.",
            );
        }
    }

    /**
     * @throws InvalidArgumentException when the record does not exist
     */
    private function assertDraftExists(?int $id): void
    {
        if ($id === null || $this->publications->findDraft($id) === null) {
            throw new InvalidArgumentException(
                $id === null ? 'Не указан редактируемый черновик.' : "Черновик #{$id} не найден.",
            );
        }
    }

    /**
     * @throws InvalidArgumentException when the text is empty or longer than
     * the message limit Telegram accepts, or when its formatting does not
     * describe real spans of it
     */
    private function assertPartValid(string $title, string $text, MessageEntities $formatting): void
    {
        $message = self::messageOf($title, $text);
        $what = trim($title) === '' ? 'Текст публикации' : 'Заголовок с текстом';

        if (mb_strlen($text) === 0 || mb_strlen($message) > ChannelService::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('%s должен быть от 1 до %d символов.', $what, ChannelService::TEXT_MAX_LENGTH),
            );
        }

        $formatting->assertFitsText($text);
    }

    /**
     * The message a part goes to the channel as: composed by the record itself,
     * so the limit here falls on exactly the text that will be sent.
     */
    private static function messageOf(string $title, string $text): string
    {
        return (new PublicationData(
            0,
            $text,
            [],
            null,
            null,
            '',
            '',
            null,
            new MessageEntities(),
            LinkButton::empty(),
            $title,
        ))->messageText();
    }

    /**
     * @throws InvalidArgumentException when the date cannot be parsed
     */
    private function normalizeDate(string $publishedAt, ?string $userTimezone = null): string
    {
        $tz = $userTimezone ?? $this->detectUserTimezone();
        $moscowTz = new DateTimeZone($tz);
        $utcTz = new DateTimeZone('UTC');

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd/m/Y h:i A',
            'd/m/Y h:i a',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd.m.Y H:i',
            'd.m.Y H:i:s',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $publishedAt, $moscowTz);
            if ($date instanceof DateTimeImmutable) {
                return $date->setTimezone($utcTz)->format('Y-m-d H:i:s');
            }
        }

        $parsed = strtotime($publishedAt);
        if ($parsed !== false) {
            return (new DateTimeImmutable('@' . $parsed, $moscowTz))
                ->setTimezone($utcTz)
                ->format('Y-m-d H:i:s');
        }

        throw new InvalidArgumentException(
            sprintf('Некорректная дата и время публикации: "%s".', $publishedAt),
        );
    }

    /**
     * Offsets an already normalized UTC timestamp, which is how the parts of
     * one publication are spread apart. normalizeDate() cannot do this: it
     * reads a bare timestamp as local time.
     */
    private function shiftDate(string $publishedAtUtc, int $minutes): string
    {
        $date = new DateTimeImmutable($publishedAtUtc, new DateTimeZone('UTC'));

        return $date->modify($minutes . ' minutes')->format('Y-m-d H:i:s');
    }

    /**
     * The gap the parts of one publication are given, in minutes. A service
     * built without the settings storage keeps the default of the schema, so
     * the gap is never invented here twice.
     */
    private function partsOffsetMinutes(): int
    {
        if ($this->settings !== null) {
            return $this->settings->intValue('partsOffsetMinutes');
        }

        return (int)PublicationSettingsService::defaults()['partsOffsetMinutes'];
    }

    private function detectUserTimezone(): string
    {
        static $detected = null;
        if ($detected !== null) {
            return $detected;
        }
        try {
            $cookie = Yii::$app->request->cookies->get('portal_tz');
            if ($cookie !== null && $cookie->value !== '') {
                $tz = $cookie->value;
                new DateTimeZone($tz); // validate
                $detected = $tz;
                return $tz;
            }
        } catch (\Exception $e) {
            // invalid timezone in cookie, fall through to UTC
        }
        $detected = 'UTC';
        return 'UTC';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
