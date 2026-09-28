# Publications and Telegram Posting

## Overview

This document provides a comprehensive description of the **publications** feature of the TRVL project, covering:

- How publications are stored and managed (drafts, scheduled posts, published posts, soft-deleted posts).
- The workflow for creating, editing, deleting and publishing content.
- Integration with the **Telegram** channel (TRVL) - updating channel description, sending text or photo posts, pinning, editing and deleting messages.
- Console commands and cron schedules that automate publishing tasks.
- Configuration requirements.
- Detailed database schema and table relationships.
- Error handling and validation rules.

All of the above is implemented in the **Shared layer** of the project (see `README.md` for the layer diagram). The key classes are located under `app/shared/Publications` and `app/shared/Telegram`.

---

## Architecture

| Layer | Responsibility |
|------|-----------------|
| **Core** | Low-level technical abstractions, DTOs, exception types, utility helpers. |
| **Shared** | Business functionality - publications, forum integration, Telegram channel integration. |
| **Application** | UI controllers, console commands, configuration, orchestration of use-cases. |

The **Publications** module lives entirely in the **Shared** layer and does **not** depend on any Application code.

### Key Classes and Interfaces

#### Publications Module (`app/shared/Publications`)
- `PublicationsService` - Main service implementing use-cases for publications management
- `PublicationRepositoryInterface` - Abstract repository for publications persistence
- `PublicationForumLinkStoreInterface` - Temporary store for forum-publication links
- `PublicationData` - DTO representing a publication record (text, its inline formatting, its link button, album, timestamps, message id)
- `ForumPublicationRef` - DTO for forum entity references

#### Settings Module (`app/shared/Settings`)
- `PublicationSettingsService` - The tunables of the publications page and the form that edits them: one `SCHEMA` entry per tunable (label, type, bounds, unit, hint, default), reading that always returns a usable value, writing that refuses a value that does not fit
- `PublicationSettingsRepositoryInterface` / `PublicationSettingsRepository` - One row per tunable in `publications_setting`

#### Telegram Module (`app/shared/Telegram`)
- `ChannelService` - Service for Telegram channel operations (description, posting, editing, deleting)
- `TelegramChannelClientInterface` - Abstract client for Telegram Bot API communication
- `MessageEntities` - Value object holding the inline formatting of one message text as a list of Telegram message entities
- `LinkButton` - Value object holding the link button of one message — the label and the address of the one button Telegram draws under it
- `ChannelInfo` - DTO for channel information
- `PublishedDescriptionData` - DTO for published channel descriptions
- `PublishedDescriptionRepositoryInterface` - Repository for channel description history

#### Application Layer
- `TelegramController` (`app/commands/TelegramController.php`) - Console commands for Telegram operations
- `SiteController` (`app/controllers/SiteController.php`) - Web UI controllers for publications, channel management and the two settings pages (`settings`, `parser-settings`)

---

## Domain Model

### Database Schema

The publications feature uses four tables to manage the lifecycle of content, plus one table to hold its tunables:

| Table | Purpose | Status Indicators |
|-------|---------|-------------------|
| `publications_post` | Main publications table | - `telegram_id IS NULL` = Scheduled (future `published_at`)<br>- `telegram_id IS NOT NULL` = Published<br>- `published_at` in past = Due for publishing |
| `publications_draft` | Draft publications | - Ready for editing/scheduling |
| `publications_edited` | Edit history of published posts | - `edited_at IS NULL` = Needs update in Telegram<br>- `edited_at IS NOT NULL` = Already updated in Telegram |
| `publications_deleted` | Soft-delete archive | - `deleted_at IS NULL` = Pending deletion from Telegram<br>- `deleted_at IS NOT NULL` = Already deleted from Telegram |
| `publications_setting` | Tunables of the publications page | - one row per code (`code` → `value`, `updated_at`)<br>- seeded from `PublicationSettingsService::defaults()` by `m260925_000001_create_publications_setting` |

A publication whose text goes past the 4096-character limit of one Telegram message is stored as several `publications_post` (or `publications_draft`) rows, one per part: the rows are written by a single `createPosts()`/`createDrafts()` call inside a transaction, `published_at` puts each part the configured gap after the previous one (`partsOffsetMinutes`, one minute by default, never shorter than the `publish-due` schedule), and there is no group column — the parts are ordinary records, ordered by `id`, and the «Часть N» heading is part of the text itself. Each row keeps its own `image_urls`, so a part is sent to the channel with the photos it holds: the form gives every part a field of its own (`publicationPartImages[]`, the album of the first part being the shared `publicationImages` field), and what a field holds on submit is what that row gets — an album that belongs to no part of its own stays with the first one. Beside the caret split the form offers three manual modes for the text as a whole — by paragraphs, by lines, by sentences (`splitWholeText`, the buttons carrying `data-split-whole`): one part per boundary of the chosen kind, however short it comes out, with a piece too long for one message still cut down by `splitIntoParts`. Two buttons under each part's album field move the whole list to the neighbouring part (`moveImageGroup`, `data-move-album`): the received links come in behind the ones the receiving field already held — `unionGroups`, so a link both fields showed is named once — and the field they left stands empty, while the last part has no «next» button and moving an empty list is refused so a stray click cannot empty the neighbour's field. See «Разбивка длинного текста на части» in [README.md](README.md). Every album field also carries a file picker of its own — «Файлы публикации» / «Файлы этой части» (`publicationImageFiles[]` for the shared field, `publicationPartImageFiles{N-1}[]` for part *N*, the first block's picker hidden and disabled exactly like its links field): a file chosen there is stored under the web root and becomes one more link of that same album, so `image_urls` stays a list of links and neither the record nor the channel has to know anything about the upload. See «Файлы альбома» in [README.md](README.md).

Every one of the four tables also carries a `formatting jsonb NOT NULL DEFAULT '[]'` column (`m260926_000001_add_formatting_to_publications`) — the highlighting of that row's `text`, kept as a list of Telegram message entities: `['type' => …, 'offset' => …, 'length' => …]`, plus `url` for a `text_link`, with offsets and lengths counted in UTF-16 code units, which is what the Bot API asks for. The text itself stays plain — no markup characters in it — and `parse_mode` is never sent, so the list is always read against the text it decorates, and an empty list (the column default, and therefore every row written before the editor existed) simply means plain text. A long publication is stored one row per part, so each row holds the entities of its own text. See «Форматирование текста» in [README.md](README.md).

Every one of the four tables carries a `button jsonb NOT NULL DEFAULT '{}'` column beside it (`m260927_000001_add_button_to_publications`) — the link button of that row as `{'text': …, 'url': …}`, the one button with an address Telegram draws under the message. An empty object means the part goes out without a button, which is what every row written before the column existed holds, and a part of a long publication gets the button of its own row exactly as it gets the album and the entities of its own row. See «Кнопка-ссылка» in [README.md](README.md).

Publications created from forum elements are additionally tracked in two link tables, one row per forum element:

| Table | Purpose | Status Indicators |
|-------|---------|-------------------|
| `publications_topic_map` | Links `topic.id` to a Telegram message | - no row = topic not seen<br>- `telegram_id IS NULL` = marked viewed («Просмотрено»)<br>- `telegram_id` set = published to the channel |
| `publications_post_map` | Links `post.id` to a Telegram message | - no row = post not seen<br>- `telegram_id IS NULL` = marked viewed («Просмотрено»)<br>- `telegram_id` set = published to the channel |

Both tables are written only through `ForumPublicationMapGatewayInterface` (see below). A topic that has exactly one post and that post count as a single element: marking or publishing either one writes the same state into the map row of the other, and a `telegram_id` the twin already has is never overwritten. Topics with several posts are not mirrored — one processed post says nothing about the rest.

The forum block filters («С изображениями», «С привязанными постами», «Кол-во изображений») and their session/URL sync are documented in [README.md](README.md), section «Фильтры блока «Форум»».

### Core DTOs and Interfaces

- **`PublicationData` (DTO)** - Represents a publication record with fields:
  - `id` (int): Primary key
  - `text` (string): Publication content (1-4096 characters, one record per part of a long publication)
  - `imageUrls` (string[]): Array of image URLs
  - `formatting` (MessageEntities): The inline highlighting of `text` — the entity list of that row; empty for plain text
  - `button` (LinkButton): The link button of `text` — its label and its address; empty when the record goes out without one
  - `publishedAt` (string|null): Publication timestamp in UTC (`YYYY-MM-DD HH:MM:SS`), null for a draft
  - `createdAt` (string): Creation timestamp
  - `updatedAt` (string): Last update timestamp
  - `telegramId` (int|null): Telegram message ID (null until published)
  - `deletedAt` (string|null): Only for `publications_deleted` rows; null while the message still waits for removal from the channel

- **`MessageEntities` (DTO, `app/shared/Telegram/Dto`)** - The inline formatting of one message text as the list the Bot API takes it in:
  - `TYPES` - The six formats carried: `bold`, `italic`, `underline`, `strikethrough`, `code`, `text_link`
  - `fromArray(array $raw): self` / `fromStored(mixed $raw): self` - Read a list, dropping entries of a type Telegram does not render and accepting the jsonb column value in every shape it arrives in (a JSON string, an already decoded list, null on a row written before the column)
  - `slice(int $start, ?int $length = null): self` - The entities of a substring re-based on its beginning, spans clipped to it and spans outside it dropped — this is what cuts a caption out of a text and a continuation out of its tail
  - `assertFitsText(string $text): void` - Refuses a list that does not describe real spans of the text: an empty span, a span reaching past the end, an entry out of the offset order, a link with no http(s) address
  - `utf16Length(string $text): int` / `toArray()` / `toJson()` / `isEmpty()` - Counting in the units Telegram counts in, and the shapes the list takes on its way through the column, the form and the API

- **`LinkButton` (DTO, `app/shared/Telegram/Dto`)** - The link button of one message as the Bot API takes it — the one button with an address drawn under the text, the photo or the album:
  - `TEXT_MAX_LENGTH` (64) / `URL_MAX_LENGTH` (2048) - What Telegram accepts of a label and of an address; the label is counted in **bytes**, so 33 Cyrillic letters are already too long
  - `fromArray(array $raw): self` / `fromStored(mixed $raw): self` - Read a pair, trimming both halves, and accept the jsonb column value in every shape it arrives in (a JSON object string, an already decoded pair, the empty object, null on a row written before the column)
  - `assertValid(): void` - Refuses a half-filled pair — a label with no address, an address with no label — a label over the byte limit, an address over the character limit, and an address that is not `http://`, `https://` or `tg://`; an empty pair needs nothing and passes, because no button is a valid answer
  - `isEmpty()` / `toArray()` / `toJson()` - Whether the message goes out without a keyboard, and the shapes the pair takes on its way through the column, the form and the API

- **`PublicationRepositoryInterface`** - Abstract repository used by the service to persist publications (implemented by `PublicationRepository`, which keeps all the SQL):
  - `createDraft(string $text, array $imageUrls, string $now, MessageEntities $formatting, LinkButton $button): int` - Creates a draft with the highlighting of its text and its link button, returns its id
  - `createPost(string $text, array $imageUrls, string $publishedAt, string $now, MessageEntities $formatting, LinkButton $button): int` - Creates a scheduled post with its button, returns its id
  - `createPosts(array $parts, string $now): int` - Inserts the parts of one long publication as separate posts **in a single transaction** (all rows or none); `$parts` is a list of `['text' => …, 'imageUrls' => …, 'formatting' => …, 'button' => …]` rows, so every part carries its own album, its own highlighting and its own button. Returns the id of the first part
  - `createDrafts(array $parts, string $now): int` - Same, for drafts
  - `updateDraft(int $id, string $text, array $imageUrls, string $now, MessageEntities $formatting, LinkButton $button): void` - Updates a draft, `created_at` untouched
  - `updatePost(int $id, string $text, array $imageUrls, string $publishedAt, string $now, MessageEntities $formatting, LinkButton $button): void` - Updates a post, `created_at` untouched
  - `deletePost(int $id): PublicationData` / `deleteDraft(int $id): PublicationData` / `deleteDeleted(int $id): PublicationData` - Removes a row and returns it as it was before deletion
  - `findPost(int $id): PublicationData|null` - Finds a post by ID
  - `findDraft(int $id): PublicationData|null` - Finds a draft by ID
  - `findDeleted(int $id): PublicationData|null` - Finds a deleted record by ID
  - `allPosts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): PublicationData[]` - Gets posts (scheduled and published), newest `published_at` first; `$limit` takes that many rows starting at `$offset`, `0` means the whole list, `$oldestFirst` reverses the order
  - `allDrafts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): PublicationData[]` - Gets drafts by `updated_at`, paged and ordered the same way
  - `allDeleted(int $limit = 0, int $offset = 0, bool $oldestFirst = false): PublicationData[]` - Gets soft-deleted records by `updated_at`, paged and ordered the same way
  - `countPosts(): int` / `countDrafts(): int` / `countDeleted(): int` - How many rows each list holds without paging, so a paged reader knows when it has reached the end
  - `findDueForPublishing(string $now): PublicationData[]` - Posts with an empty `telegram_id` and `published_at <= now`, `id` ascending
  - `storeTelegramId(int $id, int $telegramId, string $publishedAt, string $now): void` - Stores the channel message id and corrects `published_at` to the send time
  - `insertPostWithHistory(PublicationData $post, string $now): int` / `insertDraftWithHistory(PublicationData $draft, string $now): int` / `insertDeletedWithHistory(PublicationData $record, string $now): int` - Insert a moved row preserving its original `created_at` (and `published_at` for archived records)
  - `archiveEdited(PublicationData $post, string $now): void` - Copies a published post into `publications_edited`
  - `findPendingChannelDeletion(): PublicationData[]` - Soft-deleted records still awaiting removal from the channel
  - `storeDeletedAt(int $id, string $deletedAt): void` - Stamps the time a record left the channel
  - `findPendingChannelEdits(): PublicationData[]` - Archived edits still awaiting the channel update
  - `storeEditedAt(int $id, string $editedAt): void` - Stamps the time a message was replaced in the channel

- **`ForumPublicationRef`** - Reference to the forum entity a publication was created from:
  - `type` (string): Either `'topic'` or `'post'`, `isTopic()` / `isPost()` test it
  - `id` (int): ID of the forum topic or post

- **`PublicationForumLinkStoreInterface`** - Temporary store that remembers the relation between a newly created draft/post and a forum entity until the publication is finally published:
  - `remember(int $publicationId, ForumPublicationRef $ref): void` - Stores the link
  - `find(int $publicationId): ForumPublicationRef|null` - Recalls the link
  - `move(int $fromPublicationId, int $toPublicationId): void` - Moves link between publication IDs
  - `forget(int $publicationId): void` - Removes the link

- **`ForumPublicationMapGatewayInterface`** - Permanent bridge tables storing the link between a forum entity and a Telegram message (implemented by `ForumRepository`):
  - `storeTopicMapTelegramId(int $topicId, ?int $telegramId): void` - Creates the `publications_topic_map` row with an empty `telegram_id`, or stamps an existing row with the channel message id
  - `storePostMapTelegramId(int $postId, ?int $telegramId): void` - Same for `publications_post_map`
  - Both mirror the state onto the twin entity when a topic has exactly one post — see the note under the link tables above

The concrete implementations live in the **Infrastructure** sub-namespace of each module and are wired via Yii DI container.

---

## Service API – `PublicationsService`

The service lives in `app/shared/Publications/Service/PublicationsService.php` and implements the following public use-cases:

| Method | Description |
|--------|-------------|
| `posts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array` | Returns *published* posts (including scheduled ones that are already due), newest `published_at` first. `$limit`/`$offset` read the list a page at a time, `0` returns all of it, `$oldestFirst` flips the order. |
| `drafts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array` | Returns drafts by `updated_at`, paged and ordered the same way. |
| `deleted(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array` | Returns soft-deleted records by `updated_at`, paged and ordered the same way. |
| `listTotals(): array` | `{posts, drafts, deleted}` — how many rows each of the three lists holds, so the browser knows how much further a block can scroll. |
| `saveDraft(string $text, array $imageUrls, MessageEntities $formatting, LinkButton $button, ?ForumPublicationRef $forumRef = null): void` | Validates text length (1 and 4096 chars), that the formatting describes spans of that text and that the button is either whole or absent, then stores the record as a **draft**. If a forum reference is supplied, a temporary link is stored. |
| `schedulePost(string $text, array $imageUrls, MessageEntities $formatting, LinkButton $button, string $publishedAt, ?ForumPublicationRef $forumRef = null, ?string $userTimezone = null): void` | Validates text and button, normalises the supplied date (several common formats are supported), creates a **scheduled** post (`published_at` set) and stores an optional forum link. |
| `saveParts(array $texts, array $imageGroups, array $formats, array $buttons, string $publishedAt, string $action, ?ForumPublicationRef $forumRef = null, ?string $userTimezone = null): void` | Entry point of the publication form for a **new** record, which sends one text field, one album field, one formatting field and one button pair per part of a long publication. Every part goes through the text validation before anything is written. A single field is saved exactly as `saveDraft()`/`schedulePost()` would. Several fields become one record each through a single repository call (`createPosts()`/`createDrafts()`), so a failure halfway leaves none of the parts behind; `$action` (`'draft'` or `'publish'`) picks the table, the forum link belongs to the first record, and its `published_at` is the form date with each following part shifted by the configured parts gap (`partsOffsetMinutes`, read through `PublicationSettingsService::intValue()` and floored by the `publish-due` cron interval), so `publishDue()` drains the parts in order. `$imageGroups` is read positionally against `$texts` — the album of part *n* is the list the form held in field *n* — and a part whose field stands empty is saved without images. `$formats` is read the same way, and the `trim()` of a part takes its formatting through the same cut (`trimFormatting()`), so a span never outlives the text it was counted over. `$buttons` is read the same way once more — the pair of fields of that part, the shared «Кнопка-ссылка» field standing for the first one — and a part whose box stayed switched off arrives with no pair at all and is saved without a button. |
| `saveFromForm(string $text, array $imageUrls, MessageEntities $formatting, LinkButton $button, string $publishedAt, string $source, ?int $sourceId, string $action, ?string $userTimezone = null): void` | The eight scenarios of the form when it holds a record opened for editing (`$source` is `post`, `draft` or `deleted`, `$action` is `'draft'` or `'publish'`): update in place, or move between the posts, drafts, edited and deleted tables. `created_at` is always preserved, `updated_at` is the moment of the change, nothing is sent to Telegram from the form. See «Сценарии сохранения» in [README.md](README.md). |
| `publishDue(): array` | Finds all scheduled posts whose `published_at` is in the past and whose `telegram_id` is empty, sends them to Telegram via `ChannelService`, stores the returned `telegram_id`, and marks the publication as **published**. Returns a stats array `{processed, published, failed}`. |
| `deleteDue(): array` | Processes rows in `publications_deleted` with an empty `deleted_at`, removes the message from Telegram by `telegram_id` (if present), then sets `deleted_at` to current time. Returns a stats array `{processed, deleted, failed}`. Records without `telegram_id` are marked as deleted immediately (no message existed in Telegram). |
| `editDue(): array` | Processes rows in `publications_edited` with an empty `edited_at`, updates the Telegram message text by `telegram_id`, then sets `edited_at` to current time. Returns a stats array `{processed, edited, failed}`. |
| `publishDraft(int $id): void` | The «Опубликовать» button on a draft: the record moves to the posts table with `published_at` and `updated_at` set to the current time, so the periodic `publishDue()` task sends it to Telegram at once. `created_at` is preserved. |
| `scheduleDraft(int $id, string $publishedAt, ?string $userTimezone = null): void` | The «Запланировать публикацию» modal: the draft moves to the posts table with the publication time from the modal. `created_at` is preserved, `updated_at` is the current time. |
| `publishPostNow(int $id): void` | The «Опубликовать» button on a scheduled post: the post stops being scheduled — `published_at` and `updated_at` become the current time, `created_at` is untouched. |
| `movePostToDraft(int $id): void` | The «Переместить в черновик» button: the post row is deleted from the posts table and inserted into the drafts table; `created_at` is preserved, `updated_at` is the moment of the move. |
| `publishDeleted(int $id): void` | Restores a soft-deleted publication to the posts table with current timestamp as `published_at`, clears `telegram_id` (new message will be sent), and removes the deleted record. Used for republishing deleted content. |
| `scheduleDeleted(int $id, string $publishedAt, ?string $userTimezone = null): void` | Restores a soft-deleted publication to the posts table with the specified `publishedAt`, clears `telegram_id`, and removes the deleted record. Used for rescheduling deleted content. |
| `moveDeletedToDraft(int $id): void` | Moves a soft-deleted publication to the drafts table, clearing `telegram_id` and setting `updated_at` to current time. Used for recovering deleted content as a draft. |
| `deleteDraft(int $id): void` | Moves a draft into `publications_deleted` so the pending removal is dropped and the record stays restorable. |
| `deletePost(int $id): void` | Soft-deletes a published/scheduled post by moving it to `publications_deleted` table, preserving `created_at` and `published_at`, setting `updated_at` to current time, and clearing `telegram_id` (the periodic task will handle Telegram deletion). |

### Validation Rules
- Text content must be between 1 and 4096 characters (inclusive). The limit is per record, and a long publication is split into parts by the form before saving, so each stored part stays inside it (see `saveParts()` and «Разбивка длинного текста на части» in [README.md](README.md))
- A formatting list must describe real spans of its text (`MessageEntities::assertFitsText()`, reached from `assertPartValid()` on every write path): no empty span, nothing past the end, offsets in non-decreasing order, an http(s) address behind every `text_link`. An entry of a type Telegram does not render is dropped on read, not refused
- A link button is either a full pair or nothing (`LinkButton::assertValid()`, called on every write path beside `assertPartValid()`): a label with no address or an address with no label, a label over 64 bytes, an address over 2048 characters and an address outside `http://`, `https://` or `tg://` all raise `InvalidArgumentException` — a flash, and no record written
- Image URLs array can be empty but must contain valid URLs when provided
- Dates must be parseable by multiple common formats (see `normalizeDate()` method)
- Forum references are optional but when provided must contain valid source ('topic' or 'post') and positive sourceId

### Error Handling
- `InvalidArgumentException` is thrown for validation failures (text length, date format, missing IDs, etc.)
- `TelegramApiException` is thrown when Telegram API calls fail (network issues, invalid bot token, rate limiting, etc.)
- `RuntimeException` is thrown when Telegram bot is not configured (missing TELEGRAM_BOT_TOKEN)

---

## Telegram Integration – `ChannelService`

Located at `app/shared/Telegram/Service/ChannelService.php`. The service is a thin wrapper around the low-level `TelegramChannelClientInterface` and adds business-level validation.

| Method | Purpose |
|--------|---------|
| `isConfigured(): bool` | Returns **true** if a bot client instance is provided. |
| `channelInfo(): ChannelInfo` | Retrieves basic channel information (id, title, username, description). |
| `updateDescription(string $description): void` | Validates that the description does not exceed **255** characters and updates the channel description via the Telegram API. The new description is archived in the `publishedDescriptions` repository. |
| `publishedDescriptions(): array` | Returns archived channel descriptions (newest first). |
| `publishText(string $text, MessageEntities $entities = new MessageEntities(), LinkButton $button = new LinkButton()): int` | Sends a plain-text message (1–4096 chars) with its entities and, when the record has one, its link button as the keyboard of the message. Returns the Telegram `message_id`. |
| `publishPhotos(string $text, array $photoUrls, MessageEntities $entities = new MessageEntities(), LinkButton $button = new LinkButton()): int` | Sends one or many photos (up to 10 per API call). The first photo receives a caption (max 1024 chars), cut at the last line break that still fits so a line is never split. What does not fit into the caption is sent once as a continuation text message after the photos, never as a repeat of the whole text. The formatting follows the same cut: `MessageEntities::slice()` keeps the spans of the caption in the caption and re-bases the rest onto the continuation. Only the album that carries the caption is given the button, so a text cut into several albums leaves its keyboard on the first of them. Returns the `message_id` of the first photo. |
| `pinPost(int $messageId): void` | Pins the given message in the channel. |
| `deletePost(int $messageId): void` | Deletes a message from the channel. |
| `editPostText(int $messageId, string $text, MessageEntities $entities = new MessageEntities(), LinkButton $button = new LinkButton()): void` | Edits the text of an existing message (same validation as `publishText`) — entities, keyboard and all. |

Entities are the only way formatting reaches the channel: `parse_mode` is never sent, the text goes out as plain characters, and the spans go with it as `entities` of a text message or `caption_entities` of a photo — an empty list sends neither (`NutgramChannelClient::toMessageEntities()`).

A link button reaches the channel as `reply_markup`: `NutgramChannelClient::toKeyboard()` builds an `InlineKeyboardMarkup` of one row holding one `InlineKeyboardButton(text, url)`, and an empty button builds nothing at all — a publication without a button sends the very same request it sent before. `sendMediaGroup` takes no `reply_markup`, so the button of a part with an album is attached to its first photo by a following `editMessageReplyMarkup`, and only when there is a button to attach. `editMessageText` keeps the keyboard that hung on the message when the parameter is omitted, which is why every edit answers with one: the button's markup, or an empty `InlineKeyboardMarkup` that drops it (`toReplacementKeyboard()`).

All methods may throw `TelegramApiException` (API failure) and `RuntimeException` when the bot token is missing.

The limits Telegram itself imposes are public constants of the class — `TEXT_MAX_LENGTH` (4096), `CAPTION_MAX_LENGTH` (1024), `DESCRIPTION_MAX_LENGTH` (255), `ALBUM_MAX_PHOTOS` (10) and `PARTS_NUMBERING_RESERVE` (16 characters the «Часть N» heading keeps out of the text budget) — and of `LinkButton`, which holds `TEXT_MAX_LENGTH` (64 bytes of a button label) and `URL_MAX_LENGTH` (2048 characters of its address) — because the publications form reads the first two to decide where a long text has to be split, and the settings page lists all of them through `PublicationSettingsService::telegramLimits()`. None of them is editable from the portal.

---

## Console Commands (`app/commands/TelegramController.php`)

The console controller exposes the above functionality to the command line and is intended to be used by cron jobs or developers.

| Command | Description |
|---------|-------------|
| `php yii telegram/index` | Shows current channel information (id, title, username, description). |
| `php yii telegram/set-description "<desc>"` | Updates the channel description. Returns an error code if validation fails or the bot is not configured. |
| `php yii telegram/test-post "<text>" [--pin=0|1]` | Sends a test text message. Optionally pins the message. |
| `php yii telegram/publish-due` | Executes `PublicationsService::publishDue()` - publishes all due scheduled posts. |
| `php yii telegram/delete-due` | Executes `PublicationsService::deleteDue()` - removes messages from Telegram for soft-deleted publications. |
| `php yii telegram/edit-due` | Executes `PublicationsService::editDue()` - updates the text of edited publications in Telegram. |

These commands are typically scheduled via **cron** using the environment variables defined in `.env` (see below).

---

## Cron Scheduling (environment variables)

| Variable | Default | Description |
|----------|---------|-------------|
| `TELEGRAM_PUBLISH_CRON_SCHEDULE` | `*/5 * * * *` | How often `telegram/publish-due` should run. |
| `TELEGRAM_DELETE_CRON_SCHEDULE` | `*/5 * * * *` | How often `telegram/delete-due` should run. |
| `TELEGRAM_EDIT_CRON_SCHEDULE` | `*/5 * * * *` | How often `telegram/edit-due` should run. |

These variables are read by the **docker-compose** entrypoint (or by a host scheduler) and passed to the container. Adjust them according to your desired frequency.

`TELEGRAM_PUBLISH_CRON_SCHEDULE` is also passed to the `app` container, because the settings page needs it: `PublicationSettingsService::cronIntervalMinutes()` turns the schedule into a number of minutes, and that number is the lower bound of the gap between the parts of one publication. The portal shows the schedule read-only and never edits it — the cron of the container is not something a page can change.

---

## Configuration (`.env`)

The Telegram integration requires two variables:

```
TELEGRAM_BOT_TOKEN=xxxx:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_CHANNEL_ID=@your_channel_username   # or numeric channel ID
```

If the token is missing the service will throw a `RuntimeException` and all Telegram-related commands will exit with the `CONFIG` exit code.

Three more variables left the source code and are read from the environment:

```
# Signs the session and CSRF cookies (app/config/web.php); empty key = no request
# carrying one of them is accepted. Generate: php -r "echo bin2hex(random_bytes(32));"
COOKIE_VALIDATION_KEY=
# Both are read by app/web/index.php and app/yii; without them the app is production
YII_ENV=dev
YII_DEBUG=1
```

None of the three is displayed on a settings page: a page that lists tunables is not where a secret belongs.

---

## Usage from the Web UI

The **SiteController** (`app/controllers/SiteController.php`) provides a UI for managing the channel description and for viewing the connection status. The dashboard (`views/site/index.php`) shows whether the channel is reachable. Two more pages hold the tunables: `/settings` («Настройки публикаций») edits every entry of `publications_setting` through `PublicationSettingsService::save()`, shows the Telegram protocol limits and the `publish-due` schedule read-only, and keeps the channel description cards it inherited from the old `/channel-settings` page; `/parser-settings` («Настройки парсера») edits the HTTP behaviour and the entity ranges stored in `parser_config` (see «Настройки парсера» in [PARSER.md](PARSER.md)). Both forms answer a bad value with a flash and store nothing. The rest of the portal reads its numbers from the same place: the page sizes, the scroll edge, the image probe timeout, the manual split snap range, the album preview limits, the size and the number of the files an album accepts from a computer, the schedule picker step and horizon, the ceiling of the forum image-count filter, the default reading order of the lists, the date format and the gap between the parts of a publication (`SiteController::publicationSettings()`, injected into `app/views/site/publications.php` as `__*` globals and into the widgets through `PublicationsUi`). See «Страницы настроек» in [README.md](README.md).

The publications page (`/publications`) and its form posts to `publication-create`, which calls `PublicationsService::saveParts` for a new record and `saveFromForm` for a record opened for editing; the per-record buttons call `publishDraft`/`publishPostNow`, `movePostToDraft` and `deletePost`/`deleteDraft` through `publication-publish`, `publication-to-draft` and `publication-delete`. The image links a fill brings in (`data-image-urls`, from the record or from the forum) are probed in the browser before they settle in the album field of the part they belong to (`publicationImages` for the first part, `publicationPartImages[]` for the rest): each list goes into its field at once so a submit made mid-probe loses nothing, only links that answer a load are kept, a link that never answers is kept too, and the ones dropped are named in the notice under that same field (see «Открытие на редактирование» in [README.md](README.md)). The files the album pickers brought in are read before the record is written (`SiteController::withUploadedImages`): every album's files are looked up by the name of its picker — `UploadedFile::getInstancesByName()`, so an empty picker of one part cannot shift the files of another — and each one is checked against the two upload settings, against `getimagesize()` for the format and for having arrived whole, before any of them leaves the temporary directory. A file that passes becomes `web/uploads/publications/YYYYMM/<random>.<ext>` served by nginx, and its public URL is appended to the links of that album; anything refused raises `InvalidArgumentException`, which the form answers with a flash and without writing a record. A request whose body PHP discarded for being larger than `post_max_size` carries neither fields nor files, so the action notices the empty body against `CONTENT_LENGTH` and explains the size instead of reporting an empty text. See «Файлы альбома» in [README.md](README.md). Each part of the form is typed into a Quill editor mounted beside the hidden textarea that still carries its text (`PublicationEditorAsset`), and the toolbar offers only what the channel draws — bold, italic, underline, strikethrough, code and a link. The toolbar's own link handler is replaced (`modules.toolbar.handlers.link`): rather than the `prompt()` Quill asks with — a browser window that speaks the browser's language, shows no address of a link already standing and offers no label for any of it — the page shows a Bootstrap modal (`#publicationLinkModal`) with one address field, which adds, edits and removes the link over the current selection: a caret standing inside a link expands to the whole span and prefills its address, an empty address means the same as the «Убрать ссылку» button, a host typed without a scheme is read as `https://`, and any other scheme is refused with a flash, because Quill sanitises everything outside `http, https, mailto, tel, sms` to `about:blank` and its own check cannot be relied on. Text arriving from outside the editor is linkified as it fills: `linkifyParts()` runs inside `setTextParts()` (a record opened for editing, a forum fill, a whole thread, every split and merge) before the `Часть N` prefix is applied, and the paste listener calls `linkifyEditor()` on its own field, so every `http(s)://…` address not already covered by a link becomes a `text_link` whose visible label has lost its protocol — `https://awd.ru` inside the entity, `awd.ru` on screen. The scanner (`findLinks`) requires the scheme, so a bare `www.` host is left alone, trims the punctuation an address is written between and an unbalanced closing bracket, skips anything an existing link overlaps and is therefore safe to run over the same text twice; it changes the Quill document (`deleteText` plus `formatText`, both as `api`) and reads the entity list back from it, so the spans of the neighbours move with the shortened text on their own. What a person types in the editor is never scanned, and a link brought from the forum arrives with no address at all, since `ForumPageDomParser::htmlToText()` drops the `href` of an `<a>`. See «Ссылка в тексте» in [README.md](README.md). What the editor shows leaves the form as a second field per part, `publicationFormatting[]`, read positionally against the texts (`SiteController::publicationFormatsFromRequest`) exactly as the albums are; a record opened for editing brings its list back from the `data-formatting` attribute of its own row. See «Форматирование текста» in [README.md](README.md). Emoji are ordinary text: the form stores nothing about them, and the panel only helps to put a character into the text of a part, where the same character a keyboard brings in already stands. The table (`app/web/ui-kit/assets/vendor/emoji/trvl-emoji.js`, registered by `PublicationEditorAsset` before Quill) holds the 1921 sequences `emoji-test.txt` counts as fully qualified, in that file's order and categories, with the Russian CLDR annotations of each symbol as its search words, one tabbed line per emoji; both sources are Unicode License v3, next to the file as `.LICENSE.txt`. `emojiSearch()` ranks a name above its keys — a CLDR key is a free association, so a stopwatch is keyed to the same word as a pushpin — matches from the beginning of a word and keeps the order of Unicode inside one rank, while `emojiKey()` folds case, `ё` and the spaces between words. Two ways in share one panel, a node moved into `body`, fixed at `z-index: 1080` and placed by the same `caretPoint()`/`popupPoint()` pair the move popup uses: the toolbar button `.ql-emoji`, added to the toolbar after the editor is mounted because Quill collects its own controls at construction and answering `mousedown` like the rest of that toolbar, which opens the panel with its search field and its row of categories over the caret; and a `:` typed in the text, which opens the very same panel with the grid only, the word being already there. That colon asks only where a word could start — at the beginning of the text or after a space or an opening sign, and made of letters, digits and `-` — so `Время:30`, `https://awd.ru` and a Russian `Время:` call nothing up; `↓` and `↑` walk the list with a wrap and a scroll, `Enter` and `Tab` take what stands highlighted, `Esc` puts the panel away and leaves the word it read until that word changes, and a scroll, a resize or any rebuild of the parts closes it. Insertion goes through the editor and nowhere else (`deleteText()` of the shortcode as `api`, `insertText()` of the emoji as `user`), so the change the page already listens to reads the entity list back from the document, writes the textarea mirror, updates the counters and runs the split on its own: an emoji is the keystroke it imitates, and one put at the end of a bold word joins that word exactly as a letter there would. It counts as the two UTF-16 units it is, which is how the channel counts it, so the counters, the limit of a message and the offsets of the entities need no arithmetic of their own. `wholeCharacter()` keeps a cut out of the middle of one: the hard cut of `splitIntoParts()` and the caret and middle cuts of `manualCut()`/`middleCut()` step back over a divided surrogate pair, a variation selector, a keycap sign, a skin tone, a joiner standing on either side and the two regional indicators of a flag, so no part begins or ends with half a character. See «Emoji в тексте» in [README.md](README.md). The button of a part travels the same way: the shared «Кнопка-ссылка» pair is the button of the first part, `.publication-part-button` holds one pair per part after it (`publicationPartButtonText[]`, `publicationPartButtonUrl[]`, read positionally by `SiteController::publicationButtonsFromRequest`), and the checkbox «Кнопка-ссылка в каждой части» — visible only while the form holds more than one part — opens those pairs prefilled from the shared field; a pair left switched off is disabled, so it is not submitted and that part goes out with no button. See «Кнопка-ссылка» in [README.md](README.md). The three record lists start at one page of `publicationPageSize` rows and ask `publication-page` (`posts()`/`drafts()`/`deleted()` with a page and an offset, rendered through the `_item_*` partials) for the next page whenever a block is scrolled to its bottom. The forum block pages on two levels of its own: its topics come from the same `publication-page` endpoint (`ForumRepository::latestTopicsWithPosts` with an offset, `countTopics` for the total), while the posts of one topic sit in a scrollable box of their own (`_item_topic`, `.thread-replies`) and are loaded by `forum-post-page` (`ForumRepository::topicPosts`) as that box is scrolled — see «Бесконечная прогрузка списков» in [README.md](README.md). Beside the «Опубликовать» button of a topic, two more buttons load the whole discussion into the form: they post to `forum-thread`, which answers every post the table holds for that topic (`ForumRepository::topicThread` — no page, no filter, published posts included, `login_required = FALSE` kept) in the order the block currently shows them, while the topic itself is read off the attributes of its own publish button; «Тред целиком» joins the texts into one field and the links of the whole discussion into the album of the first part, «Тред по частям» gives each entity a part of its own with that entity's links in that part's album field, cutting every text with `splitIntoParts` so no part can exceed the limit `saveParts` checks (see «Тред топика в форму» in [README.md](README.md)). A switch in the header of a block posts to `publication-sort`, which stores the reading order of that list in the session and answers with the block the switch belongs to redrawn from its first page in the new order — the forum block has two such switches, one for its topics (`ForumRepository::latestTopicsWithPosts` topic order) and one for the posts of each topic; the session state — the forum filters and the reversed lists alike — is mirrored into the address bar (`SiteController::publicationsUrl`, `?postsOldest=1` and friends), and a page opened on an address that disagrees with the session is redirected onto it (see «Сортировка списков» in [README.md](README.md)). Addresses are flat: `UrlManager` maps each of these routes onto its own path without `index.php?r=` (see «Адреса страниц» in [README.md](README.md)). After a scheduled post becomes due the background `publish-due` command sends it to Telegram.

---

## Testing

Codeception suites live in `app/tests`; the installed PHPUnit requires PHP >= 8.4, and `Unit`, `Functional` and `Acceptance` run against the test configuration in `app/config/test.php`, which binds `PublicationRepositoryInterface` to the real repository, so repository tests talk to a PostgreSQL test database.

- `Unit/shared/Publications/Infrastructure/PublicationRepositoryTest` — rows written by `createDraft`/`createPost`, the sort order of the three lists, what `findDueForPublishing` selects, that an entity list and a link button survive a write and an update and come back with the record they decorate, and moving records back out of `publications_deleted`
- `Unit/shared/Publications/Infrastructure/CachePublicationForumLinkStoreTest` — remember/find/move/forget of the temporary forum link
- `Unit/shared/Telegram/Dto/MessageEntitiesTest` — reading a stored list in every shape the jsonb column brings it, UTF-16 counting, what `slice()` keeps of a caption and what it hands to the continuation, and every case `assertFitsText()` refuses
- `Unit/shared/Telegram/Dto/LinkButtonTest` — reading a stored pair in every shape the jsonb column brings it, the byte limit of a label, the character and scheme limits of an address, and the half-filled pair `assertValid()` refuses
- `Unit/shared/Telegram/Service/ChannelServicePublishPhotosTest` — album grouping, caption truncation and the continuation message, and the button travelling with the album that carries the caption, with the low-level Telegram client mocked
- `Unit/shared/Telegram/Infrastructure/PublishedDescriptionRepositoryTest` — channel description persistence

The service use-cases themselves (`saveParts`, `saveFromForm`, the periodic tasks) have no automated test coverage, and no test exercises the publications page in a browser. Neither has a Codeception test been written for the settings: `PublicationSettingsService` (clamping on read, refusal on write, the cron-derived floor) and both settings pages are covered by offline harnesses only, so a migration run against PostgreSQL and a click through the two forms remain to be done. The same stands for the editor: the entity arithmetic of the page (splitting, merging, renumbering and moving a selection, all of which carry the spans along, and the address automation and the link dialog of the toolbar, and the emoji of a part — the search over the vendored table, the panel of the button and of the colon, and the cut that refuses to divide an emoji) and the round trip through the form were checked by offline harnesses over the extracted script and by a headless-Chrome probe of the page itself, neither of which lives in the repository, so the two new migrations (`m260926_000001_add_formatting_to_publications`, `m260927_000001_add_button_to_publications`), the reading of `publicationFormatting[]` and of the two `publicationPartButton*[].` lists by a real POST and what Telegram accepts of an overlapping entity list or of a keyboard under an album are still unverified here.

---

## References

- **Telegram Bot API** – https://core.telegram.org/bots/api
- **Yii 2 Dependency Injection** – https://www.yiiframework.com/doc/guide/2.0/en/concept-di
- **Publications Service Design** – see source code under `app/shared/Publications/Service/`
- **Channel Service Design** – see source code under `app/shared/Telegram/Service/`

---