<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var \app\shared\Publications\Dto\PublicationData[] $posts */
/** @var \app\shared\Publications\Dto\PublicationData[] $drafts */
/** @var \app\shared\Publications\Dto\PublicationData[] $deleted */
/** @var array<int, array{topic: \app\shared\Forum\Dto\TopicData, posts: \app\shared\Forum\Dto\PostData[]}> $topics */
/** @var bool $withImagesOnly */
/** @var int $imagesCount the exact number of image links a record carries, 0 — без ограничения */
/** @var bool $withPostsOnly */
/** @var bool $withLinksOnly */
/** @var int $linksCount the exact number of addresses a record's text has to carry, 0 — без ограничения */
/** @var array{posts: int, drafts: int, deleted: int, forum: int} $totals */
/** @var array<string, bool> $oldestFirst the order of every switch of the page */
/** @var string $forumTextOrder '' | 'asc' | 'desc' — the length order of the topics */
/** @var bool $titleFromFirstLine whether a fill of the forum cuts its first line into the heading */
/** @var bool $linksToButtons whether the addresses of a text become link buttons on their own */
/** @var array<string, string> $settings the tunables of the publications page */
/** @var string $now */

use app\assets\PublicationEditorAsset;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Service\ChannelService;
use yii\helpers\Html;

$this->title = 'Публикации в канал';

PublicationEditorAsset::register($this);

// One message of the channel carries at most this many characters, so a longer
// text is broken into parts, each in its own field of the form.
$textLimit = ChannelService::TEXT_MAX_LENGTH;
// How many link buttons one message may carry: a bound the portal gives a post,
// so the form stops adding rows at it instead of refusing them on submit.
$buttonLimit = LinkButtons::MAX_BUTTONS;
// How long the label of one of those buttons may be: the portal counts it in
// bytes, so a label the form writes on its own is cut to this.
$buttonLabelBytes = LinkButton::TEXT_MAX_LENGTH;
// How the keyboard of a message is packed: the preview has to break the list
// into rows the way the sender does, or it shows a keyboard the channel never
// draws — so it takes the bound of the rows out of LinkButtons.
$keyboardMaxRows = LinkButtons::MAX_ROWS;

$this->registerCss(
    <<<CSS
.stacked-images.publication-preview-images {
    margin-top: 0.5rem;
}

/* A distributed publication goes to the channel as one message per part: the
   photos first, the text of the part as the caption under them. */
.stacked-images.publication-part-images {
    margin: 0 0 0.5rem;
}

.stacked-images.publication-preview-images img,
.stacked-images.publication-part-images img {
    max-height: 120px;
    width: auto;
    border-radius: 0.375rem;
    object-fit: cover;
}

.stacked-images.publication-preview-images .plus,
.stacked-images.publication-part-images .plus {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 60px;
    font-size: 1.25rem;
    font-weight: 600;
    border-radius: 0.375rem;
    background-color: var(--bs-danger);
    color: white;
    margin-left: 0.25rem;
}

.preview-card > .card-body {
    align-items: flex-start;
    justify-content: flex-start;
    display: flex;
    flex-direction: column;
}

/* The yellow cards are inset by their own p-3, like the red ones, so card-body
   and card-footer must not add a second 1rem on top of it. */
.in-progress > .card-body {
    padding: 0;
}

.in-progress > .card-footer {
    margin-top: 0.75rem;
    padding: 0.5rem 0 0;
}

#publicationPreview {
    margin-top: 0;
    padding-top: 0;
    align-self: stretch;
    text-align: left;
    width: 100%;
    flex: 0 0 auto;
}

.telegram-preview-text {
    align-self: flex-start;
    width: 100%;
}

.publication-preview-part {
    margin-bottom: 0;
    white-space: pre-wrap;
    word-break: break-word;
    text-align: left;
}

/* The keyboard of a channel message: the sender packs its buttons into rows the
   way LinkButtons::rows() does and a client gives every row the whole width of
   the message, split between its buttons. The label goes in the colour of a
   link. */
.telegram-preview-keyboard-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.telegram-preview-button {
    flex: 1 1 0;
    min-width: 0;
    padding: 0.375rem 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
    background-color: var(--bs-body-bg);
    color: var(--bs-link-color);
    font-size: 0.875rem;
    font-weight: 500;
    line-height: 1.25;
    text-align: center;
    overflow-wrap: anywhere;
}

/* The frame of the button fields borrows the border of a board card, but it is
   not a card of a board: standing inside `.kanban-items` it would catch the
   dashed orange hover and the move cursor of a draggable item while the form is
   being filled. The three classes of the board rule are repeated here so this
   one wins on specificity, not on the order the sheets were loaded in. */
.kanban-board .kanban-items .kanban-item.publication-button-frame:hover {
    border: 1px solid #dfe5ea;
    cursor: auto;
}

/* The icon that adds a button floats at the right of its fields and the notice
   under them runs past it, so both boxes that hold a float own a block
   formatting context: a float only counts towards the height of such a box, and
   without it the icon would hang out of the bottom of the white card. A float
   still shortens the line boxes of the text in the same context, which is the
   wrapping the notice wants. The vendored sheet has no `d-flow-root` utility, so
   the two boxes name the display themselves. */
.publication-button-frame,
.publication-part-button {
    display: flow-root;
}

/* A hint belongs to the control that carries it, not to the row of the card the
   control stands in, so the box holding the hint is as wide as that control:
   Bootstrap centres a popover over the box of its trigger, and a full-width
   checkbox group or field label both dragged the hint to the middle of the card
   and answered a hover over the empty right half of the row. The box may not
   grow past the card, though: a hint of a long label would then run out of it
   instead of wrapping the way it does on a narrow screen. */
[data-bs-toggle="popover"] {
    width: max-content;
    max-width: 100%;
}

/* The buttons that move a selection sit next to it, in viewport coordinates. */
.publication-selection-actions {
    position: fixed;
    z-index: 1080;
}

.publication-selection-actions .btn {
    white-space: nowrap;
}

/* The emoji panel opens over the caret of the part that asked for it, in
   viewport coordinates like the buttons that move a selection. */
.publication-emoji-panel {
    position: fixed;
    z-index: 1080;
    width: 19rem;
    padding: 0.5rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
    background-color: var(--bs-body-bg);
}

/* The colon reads its shortcode from the text of the part itself, so the panel
   it opens has no search field and no categories to pick one from. */
.publication-emoji-inline .publication-emoji-head {
    display: none;
}

.publication-emoji-categories {
    display: flex;
    flex-wrap: wrap;
    gap: 0.125rem;
    margin-top: 0.5rem;
}

.publication-emoji-category {
    padding: 0.0625rem 0.375rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
    background-color: transparent;
    color: var(--bs-secondary-color);
    font-size: 0.75rem;
    line-height: 1.5;
}

.publication-emoji-category:hover {
    color: var(--bs-body-color);
}

.publication-emoji-category.active {
    background-color: var(--bs-primary-bg-subtle);
    border-color: transparent;
    color: var(--bs-link-color);
}

.publication-emoji-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    max-height: 10.5rem;
    overflow-y: auto;
    margin-top: 0.5rem;
}

.publication-emoji-cell {
    padding: 0;
    border: 0;
    border-radius: 0.25rem;
    background: none;
    font-size: 1.25rem;
    line-height: 1.6;
}

/* The cell the arrows stopped on and the one under the cursor are the same
   thing: the keyboard follows the mouse and the mouse follows the keyboard. */
.publication-emoji-cell:hover,
.publication-emoji-cell.active {
    background-color: var(--bs-primary-bg-subtle);
}

/* Quill gives the buttons of its toolbar an icon of its own svg set and knows
   nothing of this one, so it takes the icon font of the portal. */
.publication-editor .ql-toolbar .ql-emoji {
    color: var(--bs-body-color);
    font-size: 1rem;
}
CSS
);
?>
<!-- Row start -->
<div class="row">
    <div class="col-12 kanban-board">
<?php
    // The badge of the filter card counts the filters that are on, the badge of
    // the forum card counts the topics those filters leave.
    $activeFilterCount = ($withImagesOnly ? 1 : 0) + ($withPostsOnly ? 1 : 0) + ($imagesCount > 0 ? 1 : 0)
        + ($withLinksOnly ? 1 : 0) + ($linksCount > 0 ? 1 : 0);
?>
        <!-- Forum block: its filters and its topics in one white card -->
        <div class="card mb-4 p-3">
            <div class="kanban-items ui-sortable">

                <!-- Forum filters -->
                <div class="card p-3 border border-danger to-do">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="icon-box sm bg-danger-subtle border border-danger rounded-circle">
                                <i class="bi bi-list-task text-danger"></i>
                            </span>
                            <h5 class="text-danger fw-semibold m-0">Фильтры</h5>
                        </div>
                        <span class="badge rounded-pill bg-danger-subtle text-danger px-3 py-2" id="forumFilterCount"><?= str_pad((string)$activeFilterCount, 2, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="forumFilterWithImages"
                            <?= $withImagesOnly ? 'checked' : '' ?>>
                        <label class="form-check-label" for="forumFilterWithImages">С изображениями</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="forumFilterWithPosts"
                            <?= $withPostsOnly ? 'checked' : '' ?>>
                        <label class="form-check-label" for="forumFilterWithPosts">С привязанными постами</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="forumFilterWithLinks"
                            <?= $withLinksOnly ? 'checked' : '' ?>>
                        <label class="form-check-label" for="forumFilterWithLinks">С ссылками</label>
                    </div>
                    <div class="mb-3">
                        <label for="forumFilterImagesCount" class="form-label small">
                            Кол-во изображений
                            <span id="forumFilterImagesCountValue" class="ms-2 fw-bold text-primary"><?= $imagesCount > 0 ? (int)$imagesCount : '∞' ?></span>
                        </label>
                        <input type="range" class="form-range" id="forumFilterImagesCount"
                               min="0" max="<?= (int)$settings['imagesCountFilterMax'] ?>" value="<?= $imagesCount > 0 ? (int)$imagesCount : 0 ?>">
                        <div class="form-text">0 — без ограничения</div>
                    </div>
                    <div class="mb-0">
                        <label for="forumFilterLinksCount" class="form-label small">
                            Кол-во ссылок
                            <span id="forumFilterLinksCountValue" class="ms-2 fw-bold text-primary"><?= $linksCount > 0 ? (int)$linksCount : '∞' ?></span>
                        </label>
                        <input type="range" class="form-range" id="forumFilterLinksCount"
                               min="0" max="<?= (int)$settings['linksCountFilterMax'] ?>" value="<?= $linksCount > 0 ? (int)$linksCount : 0 ?>">
                        <div class="form-text">0 — без ограничения</div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2">
                        <small class="text-muted">
                            <i class="bi bi-funnel me-1"></i>
                            Фильтры применяются к топикам ниже
                        </small>
                        <?php
                            $clearUrl = \yii\helpers\Url::to(['site/forum-filter-clear']);
                        ?>
                        <a href="<?= $clearUrl ?>" class="btn btn-sm btn-outline-secondary" id="forumFilterClearBtn"
                           title="Очистить все фильтры">
                            <i class="bi bi-x-circle me-1"></i>Очистить
                        </a>
                    </div>
                </div>

                <!-- Forum topics -->
                <div class="card p-3 border border-danger to-do">
                    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="icon-box sm bg-danger-subtle border border-danger rounded-circle">
                                <i class="bi bi-list-task text-danger"></i>
                            </span>
                            <h5 class="text-danger fw-semibold m-0">Форум</h5>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="pubSortForumTopics"
                                    <?= $oldestFirst['forumTopics'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pubSortForumTopics">Сначала старые топики</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="pubSortForumPosts"
                                    <?= $oldestFirst['forumPosts'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pubSortForumPosts">Сначала старые посты</label>
                            </div>
                            <?php /* The two buttons stand the topics up by the length of their
                                   own text instead of by their dates: the first puts the
                                   shortest on top, the second the longest. Only one of them is
                                   ever active — pressing the other takes the order over, and
                                   pressing the active one again leaves the list to its dates.
                                   The state lives in the session, like the filters above and
                                   the two switches beside it, so a reload reads the list the
                                   way the reader left it and the address says it too. */ ?>
                            <div class="btn-group mb-0" role="group" aria-label="Сортировка топиков по длине текста">
                                <input type="checkbox" class="btn-check" id="pubForumTextAsc" autocomplete="off"
                                    <?= $forumTextOrder === 'asc' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary btn-sm" for="pubForumTextAsc">Сначала короткий текст</label>
                                <input type="checkbox" class="btn-check" id="pubForumTextDesc" autocomplete="off"
                                    <?= $forumTextOrder === 'desc' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary btn-sm" for="pubForumTextDesc">Сначала длинный текст</label>
                            </div>
                            <span class="badge rounded-pill bg-danger-subtle text-danger px-3 py-2" id="forumTopicsTotal"><?= str_pad((string)$totals['forum'], 2, '0', STR_PAD_LEFT) ?></span>
                        </div>
                    </div>
                    <div class="scroll350">

                        <!-- Forum topics widget start -->
                        <div class="notification-center h-100">
                            <div class="threads" id="pub-forum-list"><?= $this->render('_block_forum', ['topics' => $topics]) ?></div>
                        </div>
                        <!-- Forum topics widget end -->

                    </div>
                </div>

            </div>
        </div>

    </div>
    <div class="col-12 kanban-board">

        <!-- Preview and form: one full-width block, the preview above the form -->
        <div class="card mb-4 p-3">
            <div class="kanban-items ui-sortable">

                <div class="card p-3 border border-warning in-progress preview-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="icon-box sm bg-warning-subtle border border-warning rounded-circle">
                                <i class="bi bi-hourglass-split text-warning"></i>
                            </span>
                            <h5 class="text-warning fw-semibold m-0">Предпросмотр публикации</h5>
                        </div>
                    </div>
                    <div class="card-img">
                        <img src="" class="card-img-top img-fluid d-none" alt="Превью" id="previewCardImgEl">
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-column gap-2 w-100" id="publicationPreview"
                             data-source="publicationTextInput"
                             data-placeholder="Введите текст публикации — он отобразится здесь до отправки в канал TRVL."></div>
                        <div class="stacked-images publication-preview-images d-none" id="publicationPreviewImages"></div>
                    </div>
                    <div class="card-footer bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="bi bi-eye me-1"></i>
                                Текст обновляется по мере ввода
                            </small>
                            <span id="previewPublicationAt" class="badge bg-primary-subtle text-primary rounded-pill px-3"></span>
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-3">
                                до <?= $textLimit ?> символов на часть
                            </span>
                        </div>
                    </div>
                </div>

                <!-- New post form -->
                <div class="card p-3 border border-warning in-progress">
                    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="icon-box sm bg-warning-subtle border border-warning rounded-circle">
                                <i class="bi bi-hourglass-split text-warning"></i>
                            </span>
                            <h5 class="text-warning fw-semibold m-0">Новая публикация</h5>
                        </div>
                        <?php /* A fill of the forum block brings one run of text. With this
                               switch its first line goes to the heading field of the part it
                               fills, so the line a topic stands in front of its own text — or
                               the line an author opened a post with — is drawn bold over that
                               text instead of being its first words. The state of the switch
                               lives in the session, like the filters of the block above and the
                               order of the lists, so a reload reads the form the way the reader
                               left it and the address of the page says it too. */ ?>
                        <div class="form-check form-switch mb-0"
                             data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                             data-bs-custom-class="popover-info"
                             data-bs-content="При подгрузке из блока «Форум» первую строку текста пишет в поле «Заголовок», а остальное оставляет в тексте. У тофика первой строкой стоит его название, у поста — первая набранная им строка. Текст из одной строки не режет: поле «Текст публикации» тогда осталось бы пустым.">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="publicationTitleFromFirstLine"
                                   <?= $titleFromFirstLine ? 'checked' : '' ?>>
                            <label class="form-check-label" for="publicationTitleFromFirstLine">
                                <i class="bi bi-card-heading me-1"></i>Первая строка — в заголовок
                            </label>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post" action="<?= \yii\helpers\Url::to(['site/publication-create']) ?>"
                              data-ajax data-clear-editing>
                            <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>"
                                   value="<?= Yii::$app->request->csrfToken ?>">
                            <input type="hidden" name="publicationSource" id="publicationSource" value="new">
                            <input type="hidden" name="publicationSourceId" id="publicationSourceId" value="">
                            <input type="hidden" name="forumEntityType" id="forumEntityType" value="">
                            <input type="hidden" name="forumEntityId" id="forumEntityId" value="">
                            <input type="hidden" name="publicationTz" id="publicationTz" value="">
                            

                            <!-- Textarea: cloned into one field per part once a text goes past the limit -->
                            <div id="publicationTextParts">
                                <div class="mb-3 publication-text-block">
                                    <?php /* The heading of a part: an optional first line the channel
                                            draws bold over the text under it. A numbered part moves
                                            its «Часть N» line in here, to the end of the heading. */ ?>
                                    <div class="mb-2">
                                        <label class="form-label mb-1 publication-title-label" for="publicationTitleInput">Заголовок</label>
                                        <input type="text" class="form-control publication-title-field"
                                               id="publicationTitleInput" name="publicationTitle"
                                               placeholder="Жирная первая строка публикации">
                                    </div>
                                    <div class="d-flex justify-content-between align-items-baseline">
                                        <label for="publicationTextInput" class="form-label mb-0 publication-text-label">Текст публикации</label>
                                        <small class="text-muted publication-text-count"></small>
                                    </div>
                                    <?php /* The plain text of a part lives in this field: every split,
                                                merge and counter of the page reads it, and it is what the
                                                form submits. The editor below paints the highlighting over
                                                it and keeps the two in step, so it stays hidden. */ ?>
                                    <div class="publication-editor">
                                        <textarea class="form-control publication-text-part d-none" id="publicationTextInput"
                                                  name="publicationText[]" tabindex="-1" aria-hidden="true"
                                                  placeholder="Введите текст публикации"></textarea>
                                        <div class="publication-editor-field"></div>
                                        <input type="hidden" class="publication-format-field"
                                               name="publicationFormatting[]" value="[]">
                                    </div>

                                    <?php /* The album of a part. The first block never shows its own:
                                            its album is the shared «Изображения публикации» field under the
                                            list, so this one stays hidden and disabled — a disabled field
                                            is not submitted and cannot shift the parts of the list. */ ?>
                                    <div class="publication-part-album d-none mt-2">
                                        <label class="form-label mb-1" for="publicationPartImages">
                                            <i class="bi bi-images me-1"></i>Изображения этой части
                                        </label>
                                        <textarea class="form-control publication-part-album-field" id="publicationPartImages"
                                                  name="publicationPartImages[]" rows="2" disabled
                                                  placeholder="По одному URL изображения в строке"></textarea>

                                        <?php /* The companion of the links field: the same album filled from a
                                                computer instead of from addresses. One picker per part, so the
                                                files a part holds move and merge with the links of that part. */ ?>
                                        <label class="form-label mb-1 mt-2 publication-part-album-files-label"
                                               for="publicationPartImageFiles">
                                            <i class="bi bi-file-earmark-image me-1"></i>Файлы этой части
                                        </label>
                                        <input type="file" class="form-control publication-part-album-files"
                                               id="publicationPartImageFiles" name="publicationPartImageFiles0[]"
                                               accept="image/*" multiple disabled>

                                        <?php /* The album goes to a neighbour and comes in behind what that field
                                                already holds — the links and the files alike; the field it left
                                                stands empty. */ ?>
                                        <div class="d-flex flex-wrap gap-2 mt-1 publication-album-move">
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    data-move-album="-1"
                                                    title="Ссылки и файлы этого поля переедут в предыдущую часть и встанут после тех, что в ней уже есть">
                                                <i class="bi bi-arrow-left-short me-1"></i>Переместить изображения в предыдущую часть
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    data-move-album="1"
                                                    title="Ссылки и файлы этого поля переедут в следующую часть и встанут после тех, что в ней уже есть">
                                                <i class="bi bi-arrow-right-short me-1"></i>Переместить изображения в следующую часть
                                            </button>
                                        </div>
                                        <div class="bg-primary-subtle px-3 py-2 mt-1 rounded-2 text-break d-none publication-images-notice"
                                             role="status"></div>
                                        <?php /* A file the album will not take is named here: the pick stays out
                                                of the form, and the album keeps what it already held. */ ?>
                                        <div class="bg-primary-subtle px-3 py-2 mt-1 rounded-2 text-break d-none publication-files-notice"
                                             role="status"></div>
                                        <div class="stacked-images mt-2 d-none publication-part-images"></div>
                                    </div>

                                    <?php /* The buttons of a part. They come out of the switch
                                            under the shared «Кнопки-ссылки» field and start
                                            with the buttons of that field; the first block
                                            never shows its own, its buttons are the shared
                                            ones. A hidden box is disabled, so it submits
                                            nothing and the part goes without a keyboard.
                                            Every row is one button with the same two fields;
                                            the rows of a box share one name, so the form
                                            submits them as the list of buttons of that part.
                                            The two attributes below are the bases of those
                                            names: a row gains its name from a base and its own
                                            id from the row number, both rewritten when the box
                                            moves to another part. */ ?>
                                    <div class="publication-part-button publication-button-box d-none mt-2">
                                        <label class="form-label mb-1" for="publicationPartButtonText0-0">
                                            <i class="bi bi-link-45deg me-1"></i>Кнопки-ссылки этой части
                                        </label>
                                        <div class="d-flex flex-column gap-2 publication-button-rows"
                                             data-text="publicationPartButtonText0"
                                             data-url="publicationPartButtonUrl0">
                                            <div class="row gx-2 gy-2 align-items-center publication-button-row">
                                                <div class="col-sm-4">
                                                    <input type="text" class="form-control publication-button-text"
                                                           id="publicationPartButtonText0-0"
                                                           name="publicationPartButtonText0[]" disabled
                                                           placeholder="Надпись кнопки">
                                                </div>
                                                <div class="col-sm">
                                                    <input type="text" class="form-control publication-button-url"
                                                           id="publicationPartButtonUrl0-0"
                                                           name="publicationPartButtonUrl0[]" maxlength="2048" disabled
                                                           placeholder="https://example.com/poll">
                                                </div>
                                                <div class="col-sm-auto d-none">
                                                    <button type="button"
                                                            class="btn btn-danger btn-icon publication-button-remove"
                                                            aria-label="Убрать кнопку-ссылку" disabled
                                                            title="Убрать эту кнопку">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button"
                                                class="btn btn-primary btn-icon mt-2 float-end ms-3 publication-button-add"
                                                aria-label="Добавить кнопку-ссылку" disabled
                                                title="Добавить ещё одну кнопку под это сообщение">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>

                                    <!-- The row a part is merged with the one under it by;
                                         the last part of the form has none. -->
                                    <div class="text-end mt-2 d-none publication-merge-row">
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                title="Слить эту часть со следующей в одно поле">
                                            <i class="bi bi-arrows-collapse-vertical me-1"></i>Объединить
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Outside the parts box, so the template a new part is cloned from stays clean. -->
                            <div class="d-flex flex-wrap justify-content-end gap-2 mb-2" id="publicationSplitModes">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-split-whole="paragraphs"
                                        title="Разбить весь текст по абзацам: пустая строка начинает новую часть">
                                    <i class="bi bi-paragraph me-1"></i>По абзацам
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-split-whole="lines"
                                        title="Разбить весь текст по переносам строк: каждая строка становится частью">
                                    <i class="bi bi-list-nested me-1"></i>По строкам
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-split-whole="sentences"
                                        title="Разбить весь текст по предложениям: «.», «!», «?» и «…» начинают новую часть">
                                    <i class="bi bi-chat-left-text me-1"></i>По предложениям
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="publicationSplitPart"
                                        title="Разделить часть по курсору, а без курсора — примерно посередине">
                                    <i class="bi bi-scissors me-1"></i>Разделить
                                </button>
                            </div>

                            <div class="form-check mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                 data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                 data-bs-content="Дописывает «Часть 1», «Часть 2» … в начало каждого фрагмента разбитой публикации">
                                <input class="form-check-input" type="checkbox" id="publicationNumberParts">
                                <label class="form-check-label" for="publicationNumberParts">
                                    <i class="bi bi-list-ol me-1"></i>Нумерация частей
                                </label>
                            </div>

                            <!-- The part albums are the submitted fields already, so this one
                                 never travels to the server: it hands the images of the shared
                                 field out to the fields of the parts inside the form. -->
                            <div class="form-check mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                 data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                 data-bs-content="Раздаёт изображения первой части по всем частям так, чтобы каждая ушла в канал со своей группой">
                                <input class="form-check-input" type="checkbox" id="publicationDistributeImages">
                                <label class="form-check-label" for="publicationDistributeImages">
                                    <i class="bi bi-card-image me-1"></i>Равномерно распределить изображения между частями
                                </label>
                            </div>

                            <!-- Attached images -->
                            <div class="mb-3">
                                <label for="publicationImages" class="form-label"
                                       data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                                       data-bs-custom-class="popover-info"
                                       data-bs-content="Изображения отправляются в канал вместе с текстом публикации (первое — с подписью); у разбитой публикации это изображения её первой части. Файл, выбранный здесь, сохраняется на сервере и становится ссылкой этого же альбома.">
                                    <i class="bi bi-images me-1"></i>Изображения публикации
                                </label>
                                <textarea class="form-control" id="publicationImages" name="publicationImages"
                                          rows="3"
                                          placeholder="По одному URL изображения в строке&#10;https://example.com/photo1.jpg&#10;https://example.com/photo2.jpg"></textarea>

                                <?php /* The companion of the links field for the first part of the
                                        publication: the album of that part is this block, so its
                                        picker carries the name of the field, not of a part. The
                                        brackets make PHP keep every file of a `multiple` input
                                        instead of only the last one. */ ?>
                                <label class="form-label mb-1 mt-2" for="publicationImageFiles">
                                    <i class="bi bi-file-earmark-image me-1"></i>Файлы публикации
                                </label>
                                <input type="file" class="form-control" id="publicationImageFiles"
                                       name="publicationImageFiles[]" accept="image/*" multiple>

                                <?php /* Named here are the links a fill left out: the shape is the
                                        one the ui-kit gives a day divider inside a chat column. */ ?>
                                <div class="bg-primary-subtle px-3 py-2 m-3 mb-1 rounded-2 text-break d-none publication-images-notice"
                                     id="publicationImagesNotice" role="status"></div>
                                <div class="bg-primary-subtle px-3 py-2 mt-1 rounded-2 text-break d-none publication-files-notice"
                                     id="publicationImageFilesNotice" role="status"></div>
                                <div class="stacked-images mt-2 d-none" id="publicationImagesPreview"></div>
                            </div>

                            <!-- Link buttons -->
                            <div class="mb-3">
                                <?php /* The frame of the buttons: the same bordered card a column of the
                                        board in ui-kit/tasks.html puts under each of its items. */ ?>
                                <div class="kanban-item publication-button-frame p-3 rounded-2 bg-white">
                                    <?php /* The switch that reads the addresses of the text into the
                                            buttons of the same part. It never travels with the saved
                                            record: the rows it adds are the fields that do — the switch
                                            itself goes to the server alone, and only to be remembered.
                                            It stands at the top
                                            left of the frame in the shape the vendored sheet draws it:
                                            the group is padded by the width of its toggle and the toggle
                                            is pulled back by the same amount, so the knob itself lands on
                                            the left edge of the frame and the text of the label keeps its
                                            own line. A float of this group would let the button rows run
                                            out under it. What the switch does is written in the popover
                                            of this same group, so the hover that asks for it covers the
                                            toggle and its text alike. */ ?>
                                    <div class="form-check form-switch mb-3"
                                         data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                                         data-bs-custom-class="popover-info"
                                         data-bs-content="Через 10 секунд после того, как текст перестали править, дописывает в «Кнопки-ссылки» адреса этого текста, кроме ссылок на изображения. Набранные вручную кнопки оставляет, адрес, который в кнопках уже есть, не повторяет. У разбитой публикации каждая часть берёт адреса своего текста, поэтому её поля кнопок включаются сами.">
                                        <input class="form-check-input" type="checkbox"
                                               role="switch" id="publicationLinksToButtons"
                                               <?= $linksToButtons ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationLinksToButtons">
                                            <i class="bi bi-link-45deg me-1"></i>Ссылки из текста в кнопки-ссылки
                                        </label>
                                    </div>

                                    <?php /* The box of the buttons of the first part: the same shape the box of
                                            any later part has, so one rule names its rows and one icon adds them. */ ?>
                                    <div class="publication-button-box" id="publicationButtonBox">
                                        <label class="form-label mb-1" for="publicationButtonText-0"
                                               data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                                               data-bs-custom-class="popover-info"
                                               data-bs-content="Кнопки встают под сообщением в канале не больше чем в <?= $keyboardMaxRows ?> ряда, причём нижний ряд шире верхнего: две кнопки встают одна под другой, три — одна сверху и две снизу. Каждая ведёт по своему адресу, надпись длиной до 64 байт, адрес — с http://, https:// или tg://. Под одним сообщением не больше <?= $buttonLimit ?> кнопок. Пустые поля означают публикацию без кнопок; у разбитой публикации это кнопки её первой части.">
                                            <i class="bi bi-link-45deg me-1"></i>Кнопки-ссылки
                                        </label>

                                        <?php /* Every row is one button of the same message: the two fields the
                                                row holds are the label and the address of it, and the rows of this
                                                box share one name, so the form submits them as the list of buttons
                                                of the first part. */ ?>
                                        <div class="d-flex flex-column gap-2 publication-button-rows"
                                             data-text="publicationButtonText"
                                             data-url="publicationButtonUrl">
                                            <div class="row gx-2 gy-2 align-items-center publication-button-row">
                                                <div class="col-sm-4">
                                                    <input type="text" class="form-control publication-button-text"
                                                           id="publicationButtonText-0" name="publicationButtonText[]"
                                                           placeholder="Надпись кнопки">
                                                </div>
                                                <div class="col-sm">
                                                    <input type="text" class="form-control publication-button-url"
                                                           id="publicationButtonUrl-0" name="publicationButtonUrl[]"
                                                           maxlength="2048" placeholder="https://example.com/poll">
                                                </div>
                                                <div class="col-sm-auto d-none">
                                                    <button type="button"
                                                            class="btn btn-danger btn-icon publication-button-remove"
                                                            aria-label="Убрать кнопку-ссылку" title="Убрать эту кнопку">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="button"
                                                class="btn btn-primary btn-icon mt-2 float-end ms-3 publication-button-add"
                                                aria-label="Добавить кнопку-ссылку"
                                                title="Добавить ещё одну кнопку под это сообщение">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>

                                    <?php /* The switch of the buttons to every part of a split
                                            publication: it stands in the form only while the
                                            publication really has parts. The popover of the box
                                            above it says what a button is; this one says what
                                            the switch does to the boxes of the parts. */ ?>
                                    <div class="form-check mt-2 d-none" id="publicationButtonEveryPartRow"
                                         data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                                         data-bs-custom-class="popover-info"
                                         data-bs-content="Показывает поля кнопок у каждой части и заполняет их этими же кнопками; надпись и адрес одной части можно поправить после этого.">
                                        <input class="form-check-input" type="checkbox" id="publicationButtonEveryPart">
                                        <label class="form-check-label" for="publicationButtonEveryPart">
                                            Кнопки-ссылки в каждой части
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Publication date & time -->
                            <div class="mb-3">
                                <label class="form-label" for="publicationAt">Дата и время публикации</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-calendar4"></i>
                                    </span>
                                    <input type="text" id="publicationAt" name="publicationAt"
                                           class="form-control publication-datepicker-time"
                                           autocomplete="off">
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" name="action" value="publish" class="btn btn-primary">
                                    <i class="bi bi-send me-1"></i>Опубликовать
                                </button>
                                <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">
                                    <i class="bi bi-save me-1"></i>Сохранить
                                </button>
                            </div>
                        </form>

                        <!-- Shown over a selection of text inside one of the parts. The
                             script moves the node to the body, where nothing can shadow
                             the viewport it is placed against. -->
                        <div class="publication-selection-actions d-none" id="publicationSelectionActions">
                            <div class="btn-group shadow" role="group" aria-label="Перемещение выделенного текста">
                                <button type="button" class="btn btn-primary btn-sm" id="publicationMovePrevPart"
                                        title="Перенести выделенный текст в конец предыдущей части">
                                    <i class="bi bi-arrow-left-short me-1"></i>Переместить в предыдущую часть
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" id="publicationMoveNextPart"
                                        title="Перенести выделенный текст в начало следующей части">
                                    <i class="bi bi-arrow-right-short me-1"></i>Переместить в следующую часть
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Запись сохраняется в БД и будет отправлена в канал TRVL в заданное время
                            </small>
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-3">
                                до <?= $textLimit ?> символов на часть
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <div class="col-12">

        <!-- Publications -->
        <div class="card mb-4">
            <div class="card-header">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <h5 class="card-title mb-0">Публикации</h5>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="pubSortPosts"
                            <?= $oldestFirst['posts'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pubSortPosts">Сначала старые</label>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="scroll350">

                    <!-- Timeline start -->
                    <div class="m-0" id="pub-posts-list"><?= $this->render('_block_posts', ['posts' => $posts, 'now' => $now]) ?></div>
                    <!-- Timeline end -->

                </div>
            </div>
        </div>

    </div>
    <div class="col-12">

        <!-- Drafts -->
        <div class="card mb-4">
            <div class="card-header">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <h5 class="card-title mb-0">Черновики</h5>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="pubSortDrafts"
                            <?= $oldestFirst['drafts'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pubSortDrafts">Сначала старые</label>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="scroll350">

                    <!-- Timeline start -->
                    <div class="m-0" id="pub-drafts-list"><?= $this->render('_block_drafts', ['drafts' => $drafts, 'now' => $now]) ?></div>
                    <!-- Timeline end -->

                </div>
            </div>
        </div>

    </div>
    <div class="col-12">

        <!-- Deleted -->
        <div class="card mb-4">
            <div class="card-header">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <h5 class="card-title mb-0">Удаленные</h5>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="pubSortDeleted"
                            <?= $oldestFirst['deleted'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pubSortDeleted">Сначала старые</label>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="scroll350">

                    <!-- Timeline start -->
                    <div class="m-0" id="pub-deleted-list"><?= $this->render('_block_deleted', ['deleted' => $deleted, 'now' => $now]) ?></div>
                    <!-- Timeline end -->

                </div>
            </div>
        </div>

    </div>
</div>
<!-- Row end -->

<!-- Schedule modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scheduleModalLabel">
                    Запланировать публикацию
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="<?= \yii\helpers\Url::to(['site/publication-schedule']) ?>"
                      id="scheduleForm" data-ajax>
                    <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>"
                           value="<?= Yii::$app->request->csrfToken ?>">
                    <input type="hidden" name="publicationSource" id="scheduleSource" value="draft">
                    <input type="hidden" name="publicationId" id="scheduleDraftId" value="">
                    <input type="hidden" name="publicationTz" id="schedulePublicationTz" value="">
                    
                    <div class="mb-3">
                        <label class="form-label" for="scheduleAt">Дата и время публикации</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-calendar4"></i>
                            </span>
                            <input type="text" id="scheduleAt" name="publicationAt"
                                   class="form-control publication-datepicker-time"
                                   autocomplete="off">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Отмена
                </button>
                <button type="submit" form="scheduleForm" class="btn btn-primary">
                    <i class="bi bi-calendar2-plus me-1"></i>Запланировать
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Link dialog -->
<div class="modal fade" id="publicationLinkModal" tabindex="-1" aria-labelledby="publicationLinkModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="publicationLinkModalLabel">
                    Ссылка
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="publicationLinkAddress"
                       data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                       data-bs-custom-class="popover-info"
                       data-bs-content="Протокол можно не писать — адрес http:// или https:// принимается и так, а в тексте ссылки остаётся только то, что стоит за ним.">Адрес ссылки</label>
                <input type="text" class="form-control" id="publicationLinkAddress"
                       autocomplete="off" placeholder="https://example.com/page">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger d-none" id="publicationLinkRemove">
                    <i class="bi bi-link-45deg me-1"></i>Убрать ссылку
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Отмена
                </button>
                <button type="button" class="btn btn-primary" id="publicationLinkApply">
                    <i class="bi bi-check2 me-1"></i>Применить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Emoji panel. The script moves the node to the body, where nothing can
     shadow the viewport it is placed against. -->
<div class="publication-emoji-panel d-none shadow" id="publicationEmojiPanel">
    <div class="publication-emoji-head">
        <input type="text" class="form-control form-control-sm" id="publicationEmojiQuery"
               autocomplete="off" placeholder="Поиск emoji" aria-label="Поиск emoji">
        <div class="publication-emoji-categories" id="publicationEmojiCategories"></div>
    </div>
    <div class="publication-emoji-grid" id="publicationEmojiGrid"></div>
</div>

<?php
$csrfParam = Yii::$app->request->csrfParam;
$csrfToken = Yii::$app->request->csrfToken;
$filterSaveUrl = \yii\helpers\Url::to(['site/forum-filter-save']);
$pageUrl = \yii\helpers\Url::to(['site/publication-page']);
$postPageUrl = \yii\helpers\Url::to(['site/forum-post-page']);
$threadUrl = \yii\helpers\Url::to(['site/forum-thread']);
$sortUrl = \yii\helpers\Url::to(['site/publication-sort']);
$titleSwitchSaveUrl = \yii\helpers\Url::to(['site/title-from-first-line-save']);
$linksSwitchSaveUrl = \yii\helpers\Url::to(['site/links-to-buttons-save']);
$textOrderSaveUrl = \yii\helpers\Url::to(['site/forum-text-order-save']);
$blockTotals = json_encode($totals);
// The page tunes its own behaviour through the settings storage: what the
// scroll waits for, how long a picture may think, which step the minutes of
// the picker take, which format of date the reader reads and how big the
// files the album picks from a computer may be.
$scrollEdge = (int)$settings['scrollEdgePx'];
$probeTimeout = (int)$settings['imageProbeTimeoutMs'];
$snapRange = (int)$settings['splitSnapRangeChars'];
$minuteStep = (int)$settings['scheduleMinuteStep'];
$horizonHours = (int)$settings['scheduleHorizonHours'];
$previewLimit = (int)$settings['imagesPreviewLimit'];
$uploadMaxMb = (int)$settings['imageUploadMaxMb'];
$uploadLimit = (int)$settings['imageUploadLimit'];
$numberingReserve = ChannelService::PARTS_NUMBERING_RESERVE;
$pickerFormat = PublicationSettingsService::DATE_FORMATS[$settings['dateFormat']];
$this->registerJs(
    "var __FILTER_SAVE_URL = '{$filterSaveUrl}';
var __PAGE_URL = '{$pageUrl}';
var __POST_PAGE_URL = '{$postPageUrl}';
var __THREAD_URL = '{$threadUrl}';
var __SORT_URL = '{$sortUrl}';
var __TITLE_SWITCH_URL = '{$titleSwitchSaveUrl}';
var __LINKS_SWITCH_URL = '{$linksSwitchSaveUrl}';
var __TEXT_ORDER_URL = '{$textOrderSaveUrl}';
var __BLOCK_TOTALS = {$blockTotals};
var __CSRF_PARAM = '{$csrfParam}';
var __CSRF_TOKEN = '{$csrfToken}';
var __TEXT_PART_LIMIT = {$textLimit};
var __BUTTON_LIMIT = {$buttonLimit};
var __BUTTON_LABEL_BYTES = {$buttonLabelBytes};
var __KEYBOARD_MAX_ROWS = {$keyboardMaxRows};
var __NUMBERING_RESERVE = {$numberingReserve};
var __SCROLL_EDGE = {$scrollEdge};
var __IMAGE_PROBE_TIMEOUT = {$probeTimeout};
var __SNAP_RANGE = {$snapRange};
var __MINUTE_STEP = {$minuteStep};
var __HORIZON_HOURS = {$horizonHours};
var __PREVIEW_LIMIT = {$previewLimit};
var __UPLOAD_MAX_MB = {$uploadMaxMb};
var __UPLOAD_LIMIT = {$uploadLimit};
var __PICKER_FORMAT = '{$pickerFormat}';
" . <<<'JS'
var __BLOCK_TARGETS = {
    forum: 'pub-forum-list',
    posts: 'pub-posts-list',
    drafts: 'pub-drafts-list',
    deleted: 'pub-deleted-list'
};

// --- message entities: the arithmetic the editor lives by ---------------------
//
// The highlighting of a part is a list of Telegram entities over its plain
// text: {type, offset, length}, plus url for a text_link. Offsets count UTF-16
// code units, which is what an index into a JavaScript string already is, so
// the numbers a browser reads out of the editor reach the server unchanged.

var ENTITY_OF_FORMAT = {
    bold: 'bold',
    italic: 'italic',
    underline: 'underline',
    strike: 'strikethrough',
    code: 'code',
    link: 'text_link'
};

var FORMAT_OF_ENTITY = {
    bold: 'bold',
    italic: 'italic',
    underline: 'underline',
    strikethrough: 'strike',
    code: 'code',
    text_link: 'link'
};

// The order the spans of one and the same piece of text are listed in.
var ENTITY_ORDER = ['bold', 'italic', 'underline', 'strikethrough', 'code', 'text_link'];

// The tags the preview renders the entity types with. The list of the types
// themselves is the channel's, not the browser's: an entity of a type without
// a tag here is left as plain text.
var FORMAT_TAGS = {
    bold: 'strong',
    italic: 'em',
    underline: 'u',
    strikethrough: 's',
    code: 'code',
    text_link: 'a'
};

function makeEntity(type, offset, length, url) {
    var entity = { type: type, offset: offset, length: length };
    if (url) {
        entity.url = url;
    }

    return entity;
}

function sortEntities(list) {
    return list.slice().sort(function (a, b) {
        return (a.offset - b.offset) || (ENTITY_ORDER.indexOf(a.type) - ENTITY_ORDER.indexOf(b.type));
    });
}

// Two spans of the same highlighting that grew together in the text.
function joinAdjacent(list) {
    var joined = [];

    sortEntities(list).forEach(function (entity) {
        var last = joined[joined.length - 1];

        if (last && last.type === entity.type && last.url === entity.url
            && last.offset + last.length === entity.offset) {
            last.length += entity.length;

            return;
        }
        joined.push(entity);
    });

    return joined;
}

function shiftEntities(list, delta) {
    return list.map(function (entity) {
        return makeEntity(entity.type, entity.offset + delta, entity.length, entity.url);
    });
}

// The highlighting of the piece of text [from, to), counted from its own
// beginning: a span sticking out of the piece is cut down to it, a span fully
// outside disappears with the text it stood on.
function takeEntities(list, from, to) {
    var taken = [];

    list.forEach(function (entity) {
        var start = Math.max(entity.offset, from);
        var end = Math.min(entity.offset + entity.length, to);

        if (end - start < 1) {
            return;
        }
        taken.push(makeEntity(entity.type, start - from, end - start, entity.url));
    });

    return sortEntities(taken);
}

// The two halves of a cut: what the part above the seam and the part below it
// keep of the highlighting.
function splitEntitiesAt(list, cut) {
    return {
        head: takeEntities(list, 0, cut),
        tail: takeEntities(list, cut, Number.MAX_SAFE_INTEGER)
    };
}

// The text left with a piece cut out of it: the halves on both sides of the
// hole close the gap up, and a span the hole split in two comes back as one.
function exciseEntities(list, from, to) {
    return joinAdjacent(
        takeEntities(list, 0, from)
            .concat(shiftEntities(takeEntities(list, to, Number.MAX_SAFE_INTEGER), from))
    );
}

// The highlighting of a new value of the text that was there before: whatever
// did not move keeps its spans, inserted text pushes them along, removed text
// takes the spans that stood inside it away. This is what carries a part's
// formatting through the «Часть N» numbering and through every rewrite of the
// text that does not move it between the parts.
function remapEntities(list, before, after) {
    if (before === after || list.length === 0) {
        return list.slice();
    }

    var common = 0;
    var limit = Math.min(before.length, after.length);
    while (common < limit && before.charAt(common) === after.charAt(common)) {
        common += 1;
    }

    var tail = 0;
    while (tail < limit - common
        && before.charAt(before.length - 1 - tail) === after.charAt(after.length - 1 - tail)) {
        tail += 1;
    }

    // The run that stands for the changed text: [common, holeEnd) in the old
    // value, [common, gapEnd) in the new one.
    var holeEnd = before.length - tail;
    var gapEnd = after.length - tail;

    return sortEntities(
        takeEntities(list, 0, common)
            .concat(shiftEntities(takeEntities(list, holeEnd, Number.MAX_SAFE_INTEGER), gapEnd))
    );
}

// The highlighting of several parts glued into one run of text: the spans of
// each move behind the ones of the parts before it, over the blank line that
// separates them.
function joinFormats(values, formats) {
    var list = [];
    var at = 0;

    values.forEach(function (value, index) {
        list = list.concat(shiftEntities(formats[index] || [], at));
        at += value.length + 2;
    });

    return sortEntities(list);
}

// The line a part of a numbered publication opens with, and the whole of it up to
// and including the blank line under it. The twin of the same match the server
// makes when it composes a record into a message.
var PART_NUMBER = /^Часть \d+[.:]?\s*(\n|$)/;

function partNumberPrefix(text) {
    var match = text.match(PART_NUMBER);

    return match ? match[0] : '';
}

// The heading a part goes out with and the text under it: the heading of a
// numbered part takes the number off the head of its text and stands at the end
// of it, so a part never carries «Часть 2» twice.
function splitHeading(title, text) {
    var heading = title.trim();

    if (heading === '') {
        return {heading: '', gap: '', body: text, moved: 0};
    }

    var prefix = partNumberPrefix(text);

    if (prefix === '') {
        return {heading: heading, gap: '\n\n', body: text, moved: 0};
    }

    return {
        heading: heading + '. ' + prefix.replace(/\s+$/, ''),
        gap: text.length > prefix.length ? '\n\n' : '',
        body: text.slice(prefix.length),
        moved: prefix.length
    };
}

// The same highlighting lying under a heading of its own: the whole heading is
// bold and every span moves behind it and over the line that separates the two,
// less the number the heading was built from.
function entitiesUnderHeading(list, heading, gap, moved) {
    if (heading === '') {
        return list.slice();
    }

    return sortEntities(
        [makeEntity('bold', 0, heading.length)]
            .concat(shiftEntities(list, heading.length + gap.length - moved))
    );
}

// The message a part goes to the channel as, and its highlighting: what the
// preview shows and the counters count is the text the server composes, so a
// heading is never a decoration the posted message does not carry.
function composedMessage(title, text, entities) {
    var cut = splitHeading(title, text);

    if (cut.heading === '') {
        return {text: text, entities: (entities || []).slice()};
    }

    return {
        text: cut.body === '' ? cut.heading : cut.heading + cut.gap + cut.body,
        entities: entitiesUnderHeading(entities || [], cut.heading, cut.gap, cut.moved)
    };
}

// The highlighting of the parts a run of text was cut up into: every piece is
// looked up again in the text it came from and takes the spans standing on it.
// The cuts drop the whitespace around them, so the positions are found rather
// than counted.
function formatsOfParts(parts, whole, list) {
    var formats = [];
    var from = 0;

    parts.forEach(function (part) {
        var at = part === '' ? from : whole.indexOf(part, from);

        if (at === -1) {
            formats.push([]);

            return;
        }

        formats.push(takeEntities(list, at, at + part.length));
        from = at + part.length;
    });

    return formats;
}

// The entity list of an editor document: the runs of one and the same
// highlighting are walked in order and joined back into spans. A block format
// a paste brought along (a heading, a list) is dropped: the channel gets the
// text of the line either way.
function entitiesFromOps(ops) {
    var open = {};
    var list = [];
    var offset = 0;

    (ops || []).forEach(function (op) {
        var text = typeof op.insert === 'string' ? op.insert : '';
        var attributes = op.attributes || {};
        // An embed — a picture, a video — is one unit of the text for Telegram.
        var length = text === '' ? 1 : text.length;

        ENTITY_ORDER.forEach(function (type) {
            var value = attributes[FORMAT_OF_ENTITY[type]];
            if (type === 'text_link') {
                value = value ? String(value) : '';
            }

            var held = open[type];
            if (!value) {
                if (held) {
                    list.push(held);
                    open[type] = null;
                }

                return;
            }

            if (held) {
                held.length += length;

                return;
            }

            open[type] = makeEntity(type, offset, length, type === 'text_link' ? value : null);
        });

        offset += length;
    });

    ENTITY_ORDER.forEach(function (type) {
        if (open[type]) {
            list.push(open[type]);
        }
    });

    return sortEntities(list);
}

// --- the addresses a text holds as plain words --------------------------------

// An address written out in the open: the scheme is what makes it one, since a
// host without it cannot be told from the word it sits in. The brackets and the
// quotes an address is often named between end its run.
var ADDRESS_IN_TEXT = /https?:\/\/[^\s<>"'«»]+/g;

// The punctuation that closes the sentence around an address rather than the
// address itself. A closing bracket is not in this list: it leaves with the
// address only when nothing opened it.
var ADDRESS_TAIL = /[.,;:!?>»«'"…\]]+$/;

// The protocol is what a label of a link does not show: the host and whatever
// follows it are the whole of it.
function addressLabel(url) {
    return url.replace(/^https?:\/\//, '');
}

// The addresses of a text a link does not yet stand on: where each one starts,
// how far it reaches, the address itself and the label it is worth. A span that
// already links the text is left alone, so running this over linked text finds
// nothing and turns no address into a link twice.
function findLinks(text, list) {
    var found = [];
    var links = (list || []).filter(function (entity) {
        return entity.type === 'text_link';
    });

    (text || '').replace(ADDRESS_IN_TEXT, function (match, start) {
        var address = match.replace(ADDRESS_TAIL, '');
        while (address.charAt(address.length - 1) === ')'
            && (address.match(/\(/g) || []).length < (address.match(/\)/g) || []).length) {
            address = address.slice(0, -1);
        }

        var end = start + address.length;
        var label = addressLabel(address);
        var covered = links.some(function (entity) {
            return entity.offset < end && entity.offset + entity.length > start;
        });

        if (!covered && label !== '') {
            found.push({ start: start, length: address.length, url: address, label: label });
        }

        return match;
    });

    return found;
}

// The addresses a button is not worth: a link that points at a picture, by the
// same suffixes an album of a part takes and a forum page cuts out of a post.
// Such an address already travels with the message as one of its photos.
var IMAGE_ADDRESS = /\.(?:gif|jpe?g|png|webp)(?:[?#]|$)/i;

// The addresses a text carries: written out in the open and standing over a word
// alike, an address the text names twice counted once.
function addressesInText(text, entities) {
    var found = findLinks(text, []).map(function (link) {
        return link.url;
    });

    (entities || []).forEach(function (entity) {
        if (entity.type === 'text_link' && typeof entity.url === 'string' && entity.url !== '') {
            found.push(entity.url);
        }
    });

    return found.filter(function (address, index) {
        return !IMAGE_ADDRESS.test(address) && found.indexOf(address) === index;
    });
}

// The label a button of an address gets: the host with the first piece of its
// path, the way the channel names a link it has no words for. The portal counts
// a label in bytes, so the cut stops before a letter that would not fit whole.
function buttonLabelOf(address, bound) {
    var rest = address.replace(/^https?:\/\//i, '').replace(/^www\./i, '');
    var slash = rest.indexOf('/');
    var host = slash < 0 ? rest : rest.slice(0, slash);
    var first = slash < 0 ? '' : rest.slice(slash + 1).split('/')[0].split('?')[0].split('#')[0];
    var label = first === '' ? host : host + '/' + first;
    var encoder = new TextEncoder();

    if (encoder.encode(label).length <= bound) {
        return label;
    }

    var taken = '';
    var used = 0;

    Array.from(label).every(function (letter) {
        var cost = encoder.encode(letter).length;

        if (used + cost > bound) {
            return false;
        }
        taken += letter;
        used += cost;

        return true;
    });

    return taken;
}

// --- the emoji the editor searches ---------------------------------------------

// The table comes as one tabbed line per emoji — itself, the number of its
// category, its words — because the keys of an object per row would cost more
// than the words do. Reading it once keeps the parsing out of the search.
function readEmojiTable(table) {
    return table.d.split('\n').map(function (line) {
        var fields = line.split('\t');

        return {
            emoji: fields[0],
            category: table.g[Number(fields[1])],
            name: fields[2],
            words: fields.slice(3),
        };
    });
}

// The shape a word of the table and a typed query are put into before they are
// compared: the letters are what a person types, so the case of a Latin name,
// the ё they do not shift and the space between two words must not tell them
// apart.
function emojiKey(word) {
    return String(word || '')
        .toLowerCase()
        .replace(/ё/g, 'е')
        .replace(/[^0-9a-zа-я]+/gi, ' ')
        .trim();
}

// The emoji a query asks for, best first. A word of a name counts from its
// beginning and nowhere else: «ёлка» is the name of a tree, while «тарелка» only
// happens to hold those four letters, and the channel's own search keeps them
// apart. A query of two words is read against the whole name. The name of an
// emoji outweighs its keys — a CLDR key is a free association, and «секундомер»
// is keyed to «кнопка» as readily as a pushpin is — and inside one rank the
// table keeps its own order, which is the order of Unicode: the common emoji of
// a category come before its rare ones.
function emojiSearch(query, list, limit) {
    var key = emojiKey(query);
    var found = [];

    if (key === '') {
        return [];
    }

    var rankOf = function (word) {
        var shape = emojiKey(word);

        if (shape === '') {
            return -1;
        }
        if (shape.split(' ').indexOf(key) >= 0 || shape.indexOf(key) === 0) {
            return 0;
        }
        if (shape.split(' ').some(function (one) {
            return one.indexOf(key) === 0;
        })) {
            return 1;
        }

        return -1;
    };

    (list || []).forEach(function (item, order) {
        var best = rankOf(item.name);

        item.words.forEach(function (word) {
            var rank = rankOf(word);

            if (rank >= 0) {
                rank += 3;
            }
            if (rank >= 0 && (best < 0 || rank < best)) {
                best = rank;
            }
        });

        if (best >= 0) {
            found.push({ item: item, rank: best, order: order });
        }
    });

    found.sort(function (a, b) {
        return a.rank - b.rank || a.order - b.order;
    });

    return found.slice(0, limit === undefined ? 60 : limit).map(function (hit) {
        return hit.item;
    });
}

function emojiCategories(list) {
    var names = [];

    (list || []).forEach(function (item) {
        if (names.indexOf(item.category) < 0) {
            names.push(item.category);
        }
    });

    return names;
}

// --- the emoji a part must not be cut through ----------------------------------

// The units of an emoji belong together: half of an astral character is no
// character at all, a variation selector, a keycap sign, a skin tone or a joiner
// stands for nothing without what it modifies, and a flag is two regional
// indicators, of which each alone is a letter in a box. The channel counts the
// text of a part in these same units, so a cut through one of them sends its two
// halves into two messages.
function isIndicator(point) {
    return point >= 0x1F1E6 && point <= 0x1F1FF;
}

// The code point that ends at a position: an astral one begins a unit before the
// low half a position of a cut stands over.
function pointBefore(text, at) {
    var last = text.charCodeAt(at - 1);

    return text.codePointAt(last >= 0xDC00 && last <= 0xDFFF ? at - 2 : at - 1);
}

function splitsCharacter(text, at) {
    var high = text.charCodeAt(at - 1);
    var low = text.charCodeAt(at);
    var after = text.codePointAt(at);
    var before = pointBefore(text, at);

    if (high >= 0xD800 && high <= 0xDBFF && low >= 0xDC00 && low <= 0xDFFF) {
        return true;
    }
    if (after === 0xFE0F || after === 0xFE0E || after === 0x20E3 || after === 0x200D
        || before === 0x200D) {
        return true;
    }
    if (after >= 0x1F3FB && after <= 0x1F3FF) {
        return true;
    }

    return isIndicator(after) && isIndicator(before);
}

// The nearest position that goes between two emoji rather than through one. It
// only ever steps back, so a cut pulled off an emoji keeps the text of the part it
// was chosen for and gives the rest to the next.
function wholeCharacter(text, at) {
    while (at > 0 && at < text.length && splitsCharacter(text, at)) {
        at -= 1;
    }

    return at;
}

jQuery(document).ready(function () {
    var pickerFormat = __PICKER_FORMAT;
    var __FLASH_ID = 'app-flash';

    function roundUpToMinuteStep(m) {
        var minutes = m.minute();
        var remainder = minutes % __MINUTE_STEP;
        if (remainder === 0 && m.second() === 0 && m.millisecond() === 0) {
            return m.clone().add(__MINUTE_STEP, 'minute').startOf('minute');
        }
        return m.clone().add(__MINUTE_STEP - remainder, 'minute').startOf('minute');
    }

    function computeNextPublicationSlot() {
        var now = moment();
        var candidate = roundUpToMinuteStep(now);
        if (!candidate.isAfter(now)) {
            candidate = candidate.add(__MINUTE_STEP, 'minute');
        }
        return candidate;
    }

    function setupDateTimePicker(inputJq) {
        if (!inputJq.length) return;
        var currentVal = inputJq.val();
        var startMoment;
        if (currentVal && currentVal !== '' && $.trim(currentVal) !== '') {
            startMoment = moment(currentVal, pickerFormat);
            if (!startMoment || !startMoment.isValid()) {
                startMoment = computeNextPublicationSlot();
            }
        } else {
            startMoment = computeNextPublicationSlot();
        }

        var userTz = getPortalTimezone();
        if (userTz && userTz !== 'UTC') {
            if (startMoment && startMoment.isValid()) {
                startMoment = startMoment.tz(userTz);
            }
        }

        try {
            var existing = inputJq.data('daterangepicker');
            if (existing) {
                existing.remove();
                inputJq.removeData('daterangepicker');
                inputJq.off('.daterangepicker');
            }
        } catch (e) {}
        inputJq.daterangepicker({
            singleDatePicker: true,
            timePicker: true,
            timePicker24Hour: true,
            timePickerIncrement: __MINUTE_STEP,
            startDate: startMoment,
            endDate: startMoment.clone().add(__HORIZON_HOURS, 'hour'),
            locale: {
                format: pickerFormat,
            },
        });
        inputJq.val(startMoment.format(pickerFormat));
    }

    function renderUtcTimes(root) {
        (root || document).querySelectorAll('.utc-time[data-utc]').forEach(function (el) {
            var shown = utcToPickerValue(el.getAttribute('data-utc'));
            if (shown !== '') {
                el.textContent = shown;
            }
        });
    }

    // A list element carries its publication time as a raw UTC timestamp, while
    // the picker and the server both work with the wall-clock time of the user,
    // so the value has to change timezone before it reaches the form. The same
    // conversion writes the timestamps a block shows under a record.
    function utcToPickerValue(utcStr) {
        if (!utcStr || typeof moment === 'undefined') {
            return '';
        }
        var utc = moment.utc(utcStr, 'YYYY-MM-DD HH:mm:ss');
        if (!utc.isValid()) {
            return '';
        }
        var local = utc.tz(getPortalTimezone());

        return local.isValid() ? local.format(pickerFormat) : '';
    }

    function showFlash(type, message) {
        var container = document.getElementById(__FLASH_ID);
        if (!container) {
            return;
        }
        var isSuccess = type === 'success';
        var alertBox = document.createElement('div');
        alertBox.className = 'alert ' + (isSuccess ? 'alert-success' : 'alert-danger')
            + ' alert-dismissible fade show d-flex align-items-center';
        alertBox.setAttribute('role', 'alert');
        alertBox.innerHTML = '<i class="bi ' + (isSuccess ? 'bi-check2-circle' : 'bi-x-circle')
            + ' me-2 fs-4 lh-1"></i><div><strong></strong> </div>'
            + '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>';
        alertBox.querySelector('strong').textContent = isSuccess ? 'Готово:' : 'Ошибка:';
        alertBox.querySelector('div').appendChild(document.createTextNode(message));
        container.innerHTML = '';
        container.appendChild(alertBox);
    }

    // Both badges of the forum block are printed by the server padded to two
    // digits; a number refreshed by AJAX keeps that shape.
    function paddedCount(value) {
        var text = String(value);

        return text.length < 2 ? '0' + text : text;
    }

    function applyBlocks(payload) {
        var blocks = payload.blocks || {};
        var totals = payload.totals || {};
        var forumTotalBadge = document.getElementById('forumTopicsTotal');

        if (typeof totals.forum === 'number' && forumTotalBadge) {
            forumTotalBadge.textContent = paddedCount(totals.forum);
        }
        Object.keys(__BLOCK_TARGETS).forEach(function (name) {
            if (typeof blocks[name] !== 'string') {
                return;
            }
            var target = document.getElementById(__BLOCK_TARGETS[name]);
            if (!target) {
                return;
            }
            target.innerHTML = blocks[name];
            renderUtcTimes(target);
            resetPaging(name, payload.totals);
        });
        if (typeof payload.flash === 'string') {
            var container = document.getElementById(__FLASH_ID);
            if (container) {
                container.innerHTML = payload.flash;
            }
        }
    }

    function postForJson(url, fields) {
        var body;
        if (typeof FormData === 'function' && fields instanceof FormData) {
            body = fields;
        } else {
            body = new FormData();
            Object.keys(fields || {}).forEach(function (key) {
                body.append(key, fields[key]);
            });
        }
        if (!body.has(__CSRF_PARAM)) {
            body.append(__CSRF_PARAM, __CSRF_TOKEN);
        }

        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (payload) {
            if (!payload || typeof payload !== 'object') {
                throw new Error('некорректный ответ сервера');
            }
            return payload;
        });
    }

    // The address bar mirrors the state the server has just stored.
    function mirrorUrl(payload) {
        if (typeof payload.url === 'string' && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', payload.url);
        }
    }

    function postForBlocks(url, fields) {
        return postForJson(url, fields).then(function (payload) {
            applyBlocks(payload);
            mirrorUrl(payload);

            return payload;
        }).catch(function (error) {
            showFlash('error', 'Не удалось обновить списки: '
                + (error && error.message ? error.message : error));
        });
    }

    // Every list the page draws a page at a time: reaching the bottom of a
    // block asks the server for the next page of rows of that list. The size
    // of the page the server answers with lives in the settings, not here. The
    // forum block pages through its topics, while the posts of one topic are
    // paged by the box that holds them.
    var __PAGED_BLOCKS = ['posts', 'drafts', 'deleted', 'forum'];
    // What one row of a paged block looks like: how many of them a block holds
    // is how far the reader already is.
    var __BLOCK_ROWS = {
        posts: '.activity-log',
        drafts: '.activity-log',
        deleted: '.activity-log',
        forum: '.thread'
    };
    // Every switch that reverses the order of a list, by the name the server
    // knows it under. The forum block has two of them because it reads its
    // topics and its posts in an order of their own.
    var __SORT_SWITCH_IDS = {
        posts: 'pubSortPosts',
        drafts: 'pubSortDrafts',
        deleted: 'pubSortDeleted',
        forumTopics: 'pubSortForumTopics',
        forumPosts: 'pubSortForumPosts'
    };
    // The two buttons that stand the topics up by the length of their text
    // instead of their dates. They are one control with three states, so they
    // are not part of the switches above.
    var __TEXT_ORDER_BUTTON_IDS = {
        asc: 'pubForumTextAsc',
        desc: 'pubForumTextDesc'
    };
    var paging = {};

    function pagingState(name) {
        if (!paging[name]) {
            paging[name] = { offset: 0, total: 0, busy: false, failed: false };
        }

        return paging[name];
    }

    function loadedRows(name) {
        var list = document.getElementById(__BLOCK_TARGETS[name]);

        return list ? list.querySelectorAll(__BLOCK_ROWS[name]).length : 0;
    }

    function nearBottom(el) {
        return el.scrollTop + el.clientHeight >= el.scrollHeight - __SCROLL_EDGE;
    }

    // A block that has just been repainted shows its first page again, so the
    // reading position restarts there; the totals of the same response say how
    // much further there is to read.
    function resetPaging(name, totals) {
        if (__PAGED_BLOCKS.indexOf(name) === -1) {
            return;
        }
        var state = pagingState(name);

        if (totals && typeof totals[name] === 'number') {
            state.total = totals[name];
        }
        state.offset = loadedRows(name);
        state.failed = false;
    }

    // Scrolling a block is not enough to reload it: the block of the element
    // that scrolled has to be found, and the element is the viewport
    // OverlayScrollbars keeps, not the list the rows live in.
    function pagedBlockOf(el) {
        if (!el || typeof el.closest !== 'function') {
            return '';
        }
        var scroller = el.closest('.scroll350');
        if (!scroller) {
            return '';
        }
        var found = '';
        __PAGED_BLOCKS.forEach(function (name) {
            var list = document.getElementById(__BLOCK_TARGETS[name]);
            if (list && scroller.contains(list)) {
                found = name;
            }
        });

        return found;
    }

    function loadNextPage(name) {
        var state = pagingState(name);

        if (state.busy || state.failed || state.offset >= state.total) {
            return;
        }
        state.busy = true;

        postForJson(__PAGE_URL, { block: name, offset: state.offset }).then(function (payload) {
            var list = document.getElementById(__BLOCK_TARGETS[name]);

            if (!list || payload.block !== name || typeof payload.html !== 'string') {
                return;
            }
            list.insertAdjacentHTML('beforeend', payload.html);
            renderUtcTimes(list);

            if (typeof payload.offset === 'number') {
                state.offset = payload.offset;
            }
            if (typeof payload.total === 'number') {
                state.total = payload.total;
            }
        }).catch(function (error) {
            // One failure stops the block: the reader is still looking at the
            // same bottom edge, and retrying would fire on every pixel of it.
            state.failed = true;
            showFlash('error', 'Не удалось догрузить список: '
                + (error && error.message ? error.message : error));
        }).then(function () {
            state.busy = false;
        });
    }

    // A discussion keeps its place in its own markup: the box says how many of
    // its posts are on screen and how many the filters leave, so a repainted
    // forum block restarts every one of them at its first page for free.
    function loadNextReplies(box) {
        var topic = parseInt(box.dataset.topic, 10) || 0;
        var offset = parseInt(box.dataset.offset, 10) || 0;
        var total = parseInt(box.dataset.total, 10) || 0;

        if (box.__busy || box.__failed || topic <= 0 || offset >= total) {
            return;
        }
        box.__busy = true;

        postForJson(__POST_PAGE_URL, { topic: topic, offset: offset }).then(function (payload) {
            if (payload.topic !== topic || typeof payload.html !== 'string') {
                return;
            }
            box.insertAdjacentHTML('beforeend', payload.html);

            if (typeof payload.offset === 'number') {
                box.dataset.offset = payload.offset;
            }
            if (typeof payload.total === 'number') {
                box.dataset.total = payload.total;
            }
        }).catch(function (error) {
            box.__failed = true;
            showFlash('error', 'Не удалось догрузить посты: '
                + (error && error.message ? error.message : error));
        }).then(function () {
            box.__busy = false;
        });
    }

    // The answer of a switch comes back as the first page of the new order,
    // so the reader restarts the block at the end of the list they asked for.
    function watchSortSwitches() {
        Object.keys(__SORT_SWITCH_IDS).forEach(function (name) {
            var input = document.getElementById(__SORT_SWITCH_IDS[name]);
            if (!input) {
                return;
            }
            input.addEventListener('change', function () {
                postForJson(__SORT_URL, { block: name, oldest: input.checked ? '1' : '0' }).then(function (payload) {
                    applyBlocks(payload);
                    mirrorUrl(payload);
                }).catch(function (error) {
                    // The list kept the order it was showing, so the switch
                    // has to keep it too.
                    input.checked = !input.checked;
                    showFlash('error', 'Не удалось изменить порядок: '
                        + (error && error.message ? error.message : error));
                });
            });
        });
    }

    // Which of the two buttons is pressed: the order the pair stands for, or
    // none of them when the list reads itself by its dates.
    function textOrderState(buttons) {
        if (buttons.asc && buttons.asc.checked) {
            return 'asc';
        }
        if (buttons.desc && buttons.desc.checked) {
            return 'desc';
        }

        return '';
    }

    // The pair of buttons of the forum header is one control with three states:
    // pressing the idle button takes the order over from its neighbour, pressing
    // the active one leaves the list to the order of the dates. The answer comes
    // back as the first page of the new order, so the block restarts where the
    // reader asked to look.
    function watchTextOrderButtons() {
        var buttons = {
            asc: document.getElementById(__TEXT_ORDER_BUTTON_IDS.asc),
            desc: document.getElementById(__TEXT_ORDER_BUTTON_IDS.desc)
        };
        if (!buttons.asc || !buttons.desc) {
            return;
        }
        // What the list on the screen is ordered by: the state a request that
        // failed has to put the pair back into.
        var shown = textOrderState(buttons);

        ['asc', 'desc'].forEach(function (name) {
            buttons[name].addEventListener('change', function () {
                buttons[name === 'asc' ? 'desc' : 'asc'].checked = false;
                var order = textOrderState(buttons);

                postForJson(__TEXT_ORDER_URL, { forumTextOrder: order }).then(function (payload) {
                    shown = order;
                    applyBlocks(payload);
                    mirrorUrl(payload);
                }).catch(function (error) {
                    buttons.asc.checked = shown === 'asc';
                    buttons.desc.checked = shown === 'desc';
                    showFlash('error', 'Не удалось изменить порядок: '
                        + (error && error.message ? error.message : error));
                });
            });
        });
    }

    // The heading switch of the form changes none of the lists: it says how a
    // fill of the forum block reads, so the server only stores its state and
    // answers with the address that mirrors it.
    function watchHeadingSwitch() {
        var input = document.getElementById('publicationTitleFromFirstLine');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            postForJson(__TITLE_SWITCH_URL, { titleFromFirstLine: input.checked ? '1' : '0' })
                .then(function (payload) {
                    mirrorUrl(payload);
                })
                .catch(function (error) {
                    // The session kept the state it was asked with, so the
                    // switch has to keep the one it was rendered with.
                    input.checked = !input.checked;
                    showFlash('error', 'Не удалось запомнить переключатель: '
                        + (error && error.message ? error.message : error));
                });
        });
    }

    // The switch that reads the addresses of a text into buttons changes none of
    // the lists either: it says what the form does with the text it holds, so the
    // server stores its state and answers with the address that mirrors it. The
    // same turn of the switch starts the waiting over again — turning it on reads
    // the text as it stands, turning it off stops the fill that had not come yet,
    // and a rejected save has to leave the waiting on the state it was told — so
    // `arm` is that waiting, which lives in the scope of the form.
    function watchLinksSwitch(arm) {
        var input = document.getElementById('publicationLinksToButtons');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var state = input.checked;
            arm();
            postForJson(__LINKS_SWITCH_URL, { linksToButtons: state ? '1' : '0' })
                .then(function (payload) {
                    mirrorUrl(payload);
                })
                .catch(function (error) {
                    // The session kept the state it was asked with, so the
                    // switch has to keep the one it was rendered with — and the
                    // waiting has to agree with it.
                    input.checked = !state;
                    arm();
                    showFlash('error', 'Не удалось запомнить переключатель: '
                        + (error && error.message ? error.message : error));
                });
        });
    }

    var runOnce = false;
    function initAll() {
        if (runOnce) return;
        runOnce = true;
        var publicationAtJq = jQuery('#publicationAt');
        var scheduleAtJq = jQuery('#scheduleAt');
        setupDateTimePicker(publicationAtJq);
        setupDateTimePicker(scheduleAtJq);

        // What a control does is written in the popover of that control — of its
        // group for a checkbox, of its label for a field — rather than in a line
        // of text under it. Bootstrap draws a popover inside its container, so a
        // page-level container keeps the hint out of the clipping of the box that
        // holds the field; the hint of the link dialog is the one that wants to
        // stay inside its dialog, which opens over the page and hides with it.
        if (window.bootstrap && window.bootstrap.Popover) {
            var Popover = window.bootstrap.Popover;
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
                // The script of the kit hands a popover to every trigger like this
                // one, with its own settings, and it runs before this page does;
                // an instance that already exists keeps the settings it was built
                // with and drops the ones asked for now, so it is let go first.
                var built = Popover.getInstance(el);
                if (built) {
                    built.dispose();
                }
                var dialog = el.closest('.modal');
                Popover.getOrCreateInstance(el, {
                    container: dialog || document.body,
                    // The placement of the markup goes to Popper by hand: the map
                    // Bootstrap looks a placement up in knows only the four sides
                    // and `auto`, so a side with an alignment (`top-start`) is
                    // missing from it, and the popover of such a trigger is left
                    // standing at the top left of the page.
                    popperConfig: { placement: el.dataset.bsPlacement }
                });
            });
        }

        // The badge of the filter card counts how many of the five filters
        // are on, the same number the page was rendered with.
        var filterCountBadge = document.getElementById('forumFilterCount');
        function updateFilterCount(count) {
            if (filterCountBadge) {
                filterCountBadge.textContent = paddedCount(count);
            }
        }

        var applyFiltersDirect = function () {
            var imagesSwitch = document.getElementById('forumFilterWithImages');
            var postsSwitch = document.getElementById('forumFilterWithPosts');
            var linksSwitch = document.getElementById('forumFilterWithLinks');
            var imagesCountInput = document.getElementById('forumFilterImagesCount');
            var linksCountInput = document.getElementById('forumFilterLinksCount');
            var imagesCount = imagesCountInput ? (parseInt(imagesCountInput.value, 10) || 0) : 0;
            var linksCount = linksCountInput ? (parseInt(linksCountInput.value, 10) || 0) : 0;
            var active = (imagesSwitch && imagesSwitch.checked ? 1 : 0)
                + (postsSwitch && postsSwitch.checked ? 1 : 0)
                + (imagesCount > 0 ? 1 : 0)
                + (linksSwitch && linksSwitch.checked ? 1 : 0)
                + (linksCount > 0 ? 1 : 0);

            updateFilterCount(active);
            postForBlocks(__FILTER_SAVE_URL, {
                withImages: imagesSwitch && imagesSwitch.checked ? 1 : 0,
                withPosts: postsSwitch && postsSwitch.checked ? 1 : 0,
                imagesCount: imagesCount,
                withLinks: linksSwitch && linksSwitch.checked ? 1 : 0,
                linksCount: linksCount
            });
        };

        ['forumFilterWithImages', 'forumFilterWithPosts', 'forumFilterWithLinks'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', applyFiltersDirect);
        });

        var partsBox = document.getElementById('publicationTextParts');
        var source = document.getElementById('publicationTextInput');
        var sharedButtonBox = document.getElementById('publicationButtonBox');
        if (!partsBox || !source || !sharedButtonBox) {
            return;
        }
        // The first field is the template the extra parts are cloned from. It is
        // never replaced itself, so `source` stays a valid reference.
        var partTemplate = partsBox.querySelector('.publication-text-block').cloneNode(true);
        // The row of the shared field is the template of a new button: the markup
        // draws it blank, and a clone of it is what adding appends to a box.
        var buttonRowTemplate = buttonRowsOf(sharedButtonBox)[0].cloneNode(true);
        var numberPartsInput = document.getElementById('publicationNumberParts');
        var distributeImagesInput = document.getElementById('publicationDistributeImages');
        var linksToButtonsInput = document.getElementById('publicationLinksToButtons');
        var titleFromFirstLineInput = document.getElementById('publicationTitleFromFirstLine');
        var splitButton = document.getElementById('publicationSplitPart');
        var splitModesBox = document.getElementById('publicationSplitModes');
        var buttonEveryPartInput = document.getElementById('publicationButtonEveryPart');
        var buttonEveryPartRow = document.getElementById('publicationButtonEveryPartRow');
        var linkModal = document.getElementById('publicationLinkModal');
        var linkAddressInput = document.getElementById('publicationLinkAddress');
        var linkApplyButton = document.getElementById('publicationLinkApply');
        var linkRemoveButton = document.getElementById('publicationLinkRemove');

        // --- the rich-text editor of a part -----------------------------------
        //
        // The textarea of a part stays the carrier of its plain text: every
        // counter, cut and merge of this page reads it, and it is what the form
        // submits. Quill is the surface the user sees and types into, and what
        // it paints comes back as the entity list of that text. Three rules tie
        // the two together:
        //
        //   * writing `field.value` carries the spans along with the text that
        //     moved and repaints the editor, so the numbering, a merge and a
        //     fill of a record all keep the formatting;
        //   * typing in the editor writes the value through the native setter,
        //     re-reads the spans from the document and then dispatches the
        //     `input` event this page has always listened to;
        //   * the caret of the editor answers for the field — `selectionStart`,
        //     `selectionEnd`, `setSelectionRange` and `focus` point at the text
        //     on the screen, so a cut at the caret stays a cut where the user
        //     sees it.

        var TEXTAREA_VALUE = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
        var TOOLBAR_FORMATS = [['bold', 'italic', 'underline', 'strike', 'code'], ['link']];

        function formattingOf(field) {
            return field.__formatting || [];
        }

        function editorRootOf(field) {
            return field.parentNode ? field.parentNode.querySelector('.publication-editor-field') : null;
        }

        function formatFieldOf(field) {
            return field.parentNode ? field.parentNode.querySelector('.publication-format-field') : null;
        }

        // The editor's own reading of the list: painting clamps a span that fell
        // out of the text and joins the runs that grew together, so what the form
        // submits is what the screen shows.
        function paintEditor(field) {
            var editor = field.__editor;
            if (!editor) {
                return;
            }

            var value = TEXTAREA_VALUE.get.call(field);
            var caret = field.__caret;
            field.__painting = true;
            editor.setText(value);

            // A value that ends in a line break loses that break: an editor holds
            // no empty line behind its last one, and the channel trims it anyway.
            var shown = editor.getText().replace(/\n$/, '');
            if (shown !== value) {
                TEXTAREA_VALUE.set.call(field, shown);
            }

            formattingOf(field).forEach(function (entity) {
                var format = FORMAT_OF_ENTITY[entity.type];
                if (!format) {
                    return;
                }
                editor.formatText(entity.offset, entity.length, format, entity.url || true, 'api');
            });
            field.__formatting = entitiesFromOps(editor.getContents().ops);

            if (caret && document.activeElement === editor.root) {
                var start = Math.min(caret[0], shown.length);
                var end = Math.min(Math.max(caret[1], start), shown.length);
                editor.setSelection(start, end - start, 'api');
                field.__caret = [start, end];
            }
            field.__painting = false;
        }

        function writeMirror(field, value) {
            field.__painting = true;
            TEXTAREA_VALUE.set.call(field, value);
            field.__painting = false;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // The highlighting a part starts out with: the list of entities over the
        // text the field already holds, painted into the editor.
        function setPartFormatting(field, entities) {
            field.__formatting = entities || [];
            paintEditor(field);
        }

        function mountPartEditor(field) {
            var root = editorRootOf(field);
            if (field.__editor || !root || typeof Quill !== 'function') {
                return;
            }

            var editor = new Quill(root, {
                theme: 'snow',
                placeholder: field.placeholder || '',
                modules: {
                    toolbar: {
                        container: TOOLBAR_FORMATS,
                        handlers: { link: function () { openLinkDialog(field); } },
                    },
                },
            });
            field.__editor = editor;
            field.__formatting = formattingOf(field);
            field.__caret = null;

            // Quill builds the toolbar of a part from the formats of this page and
            // knows nothing of an emoji button, so the page adds it to the toolbar
            // it built and answers it alone. It answers mousedown, as the toolbar of
            // Quill does: the button must not take the caret the panel opens over.
            var toolbar = root.parentNode ? root.parentNode.querySelector('.ql-toolbar') : null;

            if (toolbar) {
                var emojiGroup = document.createElement('span');
                var emojiButton = document.createElement('button');

                emojiGroup.className = 'ql-formats';
                emojiButton.type = 'button';
                emojiButton.className = 'ql-emoji';
                emojiButton.title = 'Добавить emoji';
                emojiButton.setAttribute('aria-label', 'Добавить emoji');
                emojiButton.innerHTML = '<i class="bi bi-emoji-smile"></i>';
                emojiGroup.appendChild(emojiButton);
                toolbar.appendChild(emojiGroup);
                emojiButton.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    toggleEmojiPicker(field);
                });
            }

            Object.defineProperty(field, 'value', {
                configurable: true,
                get: function () {
                    return TEXTAREA_VALUE.get.call(field);
                },
                set: function (next) {
                    var before = TEXTAREA_VALUE.get.call(field);
                    TEXTAREA_VALUE.set.call(field, next);

                    if (field.__painting || before === next) {
                        return;
                    }

                    field.__formatting = remapEntities(formattingOf(field), before, next);
                    paintEditor(field);
                    // The painted list is what the server gets: a value written
                    // from the page never leaves its field behind the text.
                    writeFormattingField(field);
                },
            });

            [['selectionStart', 0], ['selectionEnd', 1]].forEach(function (pair) {
                var which = pair[1];
                Object.defineProperty(field, pair[0], {
                    configurable: true,
                    get: function () {
                        return field.__caret ? field.__caret[which] : 0;
                    },
                    set: function (position) {
                        var other = field.__caret ? field.__caret[1 - which] : 0;
                        setCaret(which === 0 ? position : other, which === 0 ? other : position);
                    },
                });
            });

            function setCaret(from, to) {
                field.__caret = [from, to];
                editor.setSelection(from, Math.max(0, to - from), 'api');
            }

            field.setSelectionRange = function (from, to) {
                setCaret(from, to === undefined ? from : to);
            };
            field.focus = function () {
                editor.focus();
            };

            editor.on('text-change', function (delta, old, source) {
                if (source !== 'user' || field.__painting) {
                    return;
                }

                var range = editor.getSelection();
                var text = editor.getText().replace(/\n$/, '');

                field.__caret = range ? [range.index, range.index + range.length] : field.__caret;
                field.__formatting = entitiesFromOps(editor.getContents().ops);
                // The word in front of the caret is what the panel of a colon lists.
                // It is asked before the mirror is written: writing it can cut the
                // part anew and replace the very field this panel belongs to.
                updateEmojiTrigger(field, text);
                writeMirror(field, text);
            });
            editor.on('selection-change', function (range, previous, source) {
                if (!range || source !== 'user') {
                    return;
                }

                lastEditedField = field;
                field.__caret = [range.index, range.index + range.length];
            });
            // The caret of the page lives in the editor now, so the field it was
            // typed in is the one a cut or a move works on.
            root.addEventListener('focusin', function () {
                lastEditedField = field;
            });
            // A selection is made with the mouse or with shift and the arrows, and
            // the editor reports neither to the listener of the box.
            ['mouseup', 'keyup'].forEach(function (name) {
                root.addEventListener(name, function () {
                    showSelectionActions(field);
                });
            });
            // The arrows and Enter belong to the panel of a colon while it is open
            // over this part. The capture phase, as for the paste: Quill's own
            // keyboard answers ArrowDown and Enter, and it must not.
            root.addEventListener('keydown', function (event) {
                if (emojiTarget && emojiTarget.field === field && emojiKeys(event)) {
                    event.stopPropagation();
                }
            }, true);
            // Text arriving from outside the page comes in as it is copied, without
            // the headings and the colours of the page it was taken from. The
            // listener answers in the capture phase: Quill reads the clipboard of
            // the editable box itself, and its reading has to be the one that
            // never happens.
            root.addEventListener('paste', function (event) {
                var text = (event.clipboardData || window.clipboardData).getData('text');
                if (text === null || text === undefined) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                var range = editor.getSelection(true);
                editor.deleteText(range.index, range.length, 'api');
                editor.insertText(range.index, text, 'user');

                // An address that came in with the text is an address the user
                // meant to keep, so it is read as one here too; the caret follows
                // the text back to where the paste left it.
                var removed = linkifyEditor(field);
                if (removed > 0) {
                    var caret = Math.min(range.index + text.length - removed, field.value.length);
                    editor.setSelection(caret, 0, 'api');
                    field.__caret = [caret, caret];
                    writeMirror(field, editor.getText().replace(/\n$/, ''));
                }
            }, true);

            paintEditor(field);
        }

        function mountPartEditors() {
            textParts().forEach(mountPartEditor);
        }

        // The hidden field of a part carries its list to the server next to the
        // text the spans are counted over.
        function writeFormattingField(field) {
            var holder = formatFieldOf(field);

            if (holder) {
                holder.value = JSON.stringify(formattingOf(field));
            }
        }

        function writeFormattingFields() {
            textParts().forEach(writeFormattingField);
        }

        // The text and the highlighting of a part written together: the value goes
        // in without the spans of the text it replaced being moved over it, and the
        // list that comes with it is painted as it stands.
        function seedPart(field, value, entities) {
            field.__caret = null;
            field.__painting = true;
            TEXTAREA_VALUE.set.call(field, value);
            field.__painting = false;
            field.__formatting = entities || [];
            paintEditor(field);
        }

        // The addresses a part holds as plain words turned into links: the
        // protocol leaves the text and the label of the link is what stays of the
        // address. The editor itself is what changes, so the highlighting of the
        // part is read back from the document instead of being moved over the
        // shorter text by hand — which is also what carries the spans of the
        // neighbours along. The cuts run from the end backwards, so the place of
        // the next one still stands. Returns how many characters left the text.
        function linkifyEditor(field) {
            var editor = field.__editor;
            if (!editor) {
                return 0;
            }

            var text = editor.getText().replace(/\n$/, '');
            var links = findLinks(text, formattingOf(field));
            var removed = 0;

            links.reverse().forEach(function (link) {
                var scheme = link.url.length - link.label.length;

                editor.deleteText(link.start, scheme, 'api');
                editor.formatText(link.start, link.length - scheme, 'link', link.url, 'api');
                removed += scheme;
            });

            if (removed === 0) {
                return 0;
            }

            field.__formatting = entitiesFromOps(editor.getContents().ops);
            field.__painting = true;
            TEXTAREA_VALUE.set.call(field, editor.getText().replace(/\n$/, ''));
            field.__painting = false;

            return removed;
        }

        function linkifyParts() {
            textParts().forEach(function (field) {
                linkifyEditor(field);
            });
        }

        // --- the link of a selection --------------------------------------------

        // The toolbar asks for the address of a link in a window of its own: the
        // prompt Quill answers with belongs to the browser — its words are the
        // browser's, the address of a link that already stands is not shown in it,
        // and nothing there says that an empty answer takes the link away. A
        // selection is what the link goes over; a caret standing inside a link
        // names the link it belongs to, so the same button adds, edits and removes
        // one.

        var linkTarget = null;

        function enclosingLink(field, at) {
            var found = null;

            formattingOf(field).forEach(function (entity) {
                if (entity.type === 'text_link'
                    && at >= entity.offset && at <= entity.offset + entity.length) {
                    found = entity;
                }
            });

            return found;
        }

        // An address the channel takes: a host written without a scheme is read as
        // an https one, and an address of any other kind is refused. An empty one
        // is not a mistake but the way to take a link away. What the editor keeps
        // is what the channel draws — an http(s) address; the deep links of
        // Telegram itself belong to the button of a part, which has its own field.
        function linkAddressOf(raw) {
            var value = (raw || '').trim();

            if (value === '') {
                return '';
            }
            if (!/^[a-z][a-z0-9+.-]*:/i.test(value)) {
                value = 'https://' + value;
            }

            return /^https?:\/\//i.test(value) ? value : null;
        }

        function showLinkModal(show) {
            if (!linkModal || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                return;
            }
            bootstrap.Modal.getOrCreateInstance(linkModal)[show ? 'show' : 'hide']();
        }

        function openLinkDialog(field) {
            var editor = field.__editor;
            var range = editor.getSelection() || (field.__caret
                ? { index: field.__caret[0], length: field.__caret[1] - field.__caret[0] }
                : null);
            var format = range ? editor.getFormat(range.index, range.length) : {};
            var url = typeof format.link === 'string' ? format.link : '';

            if (range && range.length === 0 && url !== '') {
                var held = enclosingLink(field, range.index);

                if (held) {
                    range = { index: held.offset, length: held.length };
                }
            }
            if (!range || range.length === 0) {
                showFlash('error', 'Выделите текст, который станет ссылкой.');
                return;
            }

            linkTarget = { field: field, start: range.index, length: range.length };
            if (linkAddressInput) {
                linkAddressInput.value = url;
            }
            if (linkRemoveButton) {
                linkRemoveButton.classList.toggle('d-none', url === '');
            }
            showLinkModal(true);
        }

        function writeLinkOfSelection(address) {
            if (!linkTarget) {
                return;
            }
            var target = linkTarget;

            linkTarget = null;
            showLinkModal(false);
            target.field.__editor.formatText(target.start, target.length, 'link', address, 'user');
            target.field.__editor.setSelection(target.start, target.length, 'api');
        }

        // --- the emoji the editor offers ---------------------------------------
        //
        // One panel, two ways into it: the button of the toolbar opens it and asks
        // its own search field, and a colon typed in the text of a part reads the
        // word after it from the text itself and shows only the grid. Both put the
        // emoji in the same way — through the editor, with the source of a keystroke
        // — so the mirror of the part, its counters, its list of entities and the
        // split of a part that ran over the limit all happen as they do for a typed
        // letter. The words searched are the CLDR annotations of Russian, which are
        // the names the apps of Telegram show, and the order of the results is the
        // order of Unicode, so the common emoji of a category come before its rare
        // ones.

        var emojiPanel = document.getElementById('publicationEmojiPanel');
        var emojiQueryInput = document.getElementById('publicationEmojiQuery');
        var emojiCategoriesBox = document.getElementById('publicationEmojiCategories');
        var emojiGridBox = document.getElementById('publicationEmojiGrid');
        var EMOJI = window.TRVL_EMOJI ? readEmojiTable(window.TRVL_EMOJI) : [];
        // A category of the table is a few hundred emoji wide, which is more than the
        // panel shows and more than the arrows are worth walking. The order of
        // Unicode puts the common ones of a category first, so what the cut leaves
        // out is its rare tail.
        var EMOJI_BROWSE_LIMIT = 160;
        var EMOJI_SEARCH_LIMIT = 60;
        // The part the panel belongs to, where its emoji goes and what its colon
        // typed. `inline` is the panel of a colon, `picker` the one of the button.
        var emojiTarget = null;
        var emojiShown = [];
        var emojiActive = -1;
        var emojiCategory = '';
        // The word Escape gave up on: the same one must not raise the panel again
        // while it is still being typed.
        var emojiDismissed = null;

        // The colon opens the search only where a word could start: at the beginning
        // of the text or after a space or an opening sign. The colons of a time, of
        // an address and of a Russian «Время:» stand behind a letter or a digit, and
        // a panel over the caret every time one of them is typed is worse than no
        // panel at all.
        function emojiTriggerAt(text, at) {
            var upto = text.slice(0, at);
            var colon = upto.lastIndexOf(':');

            if (colon < 0) {
                return null;
            }

            var before = colon > 0 ? upto.slice(colon - 1, colon) : '';
            var word = upto.slice(colon + 1);

            if (before !== '' && !/[\s([{«"']/.test(before)) {
                return null;
            }
            if (!/^[-\wа-яё]*$/i.test(word)) {
                return null;
            }

            return { start: colon, length: word.length + 1, shortcode: word };
        }

        // What the panel lists: the answer to a word, or the category it was opened
        // on while nothing is asked for.
        function emojiListOf(word) {
            if (word !== '') {
                return emojiSearch(word, EMOJI, EMOJI_SEARCH_LIMIT);
            }

            return EMOJI.filter(function (item) {
                return item.category === emojiCategory;
            }).slice(0, EMOJI_BROWSE_LIMIT);
        }

        function emojiQuery() {
            if (!emojiTarget) {
                return '';
            }
            if (emojiTarget.mode === 'inline') {
                return emojiTarget.shortcode;
            }

            return emojiQueryInput ? emojiQueryInput.value.trim() : '';
        }

        function buildEmojiCategories() {
            if (!emojiCategoriesBox) {
                return;
            }

            emojiCategoriesBox.innerHTML = '';
            emojiCategories(EMOJI).forEach(function (name) {
                var button = document.createElement('button');

                button.type = 'button';
                button.className = 'publication-emoji-category';
                button.dataset.emojiCategory = name;
                button.textContent = name;
                emojiCategoriesBox.appendChild(button);
            });
        }

        function renderEmojiPanel() {
            if (!emojiTarget || !emojiGridBox) {
                return;
            }

            var word = emojiQuery();

            emojiShown = emojiListOf(word);
            // Nothing stands highlighted while the search field is still empty: the
            // panel of the button waits for a word, the panel of a colon has a list
            // to choose from as soon as it is open.
            emojiActive = emojiShown.length > 0 && (word !== '' || emojiTarget.mode === 'inline')
                ? 0
                : -1;

            emojiGridBox.innerHTML = '';
            emojiShown.forEach(function (item, index) {
                var cell = document.createElement('button');

                cell.type = 'button';
                cell.className = 'publication-emoji-cell' + (index === emojiActive ? ' active' : '');
                cell.textContent = item.emoji;
                cell.title = item.name;
                emojiGridBox.appendChild(cell);
            });
            Array.prototype.forEach.call(emojiCategoriesBox.children, function (button) {
                button.classList.toggle('active', word === '' && button.dataset.emojiCategory === emojiCategory);
            });

            // The list is as long as the panel, so the rows decide where it fits; a
            // list that changed starts at its top again.
            emojiGridBox.scrollTop = 0;
            placeEmojiPanel();
        }

        function placeEmojiPanel() {
            var point = popupPoint(
                caretPoint(emojiTarget.field, emojiTarget.start + emojiTarget.length),
                { width: emojiPanel.offsetWidth, height: emojiPanel.offsetHeight },
                { width: window.innerWidth, height: window.innerHeight }
            );

            emojiPanel.style.left = point.left + 'px';
            emojiPanel.style.top = point.top + 'px';
        }

        function openEmojiPanel(field, mode, trigger) {
            if (!emojiPanel || !field.__editor) {
                return;
            }

            var caret = field.__caret;

            emojiTarget = {
                field: field,
                mode: mode,
                // A colon names the word it types over; the button takes the caret
                // of the editor, or the end of its text while the part was never
                // clicked into.
                start: trigger ? trigger.start : (caret ? caret[0] : field.__editor.getLength() - 1),
                length: trigger ? trigger.length : (caret ? caret[1] - caret[0] : 0),
                shortcode: trigger ? trigger.shortcode : '',
            };
            emojiCategory = emojiCategories(EMOJI)[0] || '';
            emojiDismissed = null;
            if (mode === 'picker' && emojiQueryInput) {
                emojiQueryInput.value = '';
            }

            emojiPanel.classList.toggle('publication-emoji-inline', mode === 'inline');
            emojiPanel.classList.remove('d-none');
            renderEmojiPanel();

            if (mode === 'picker' && emojiQueryInput) {
                emojiQueryInput.focus();
            }
        }

        function closeEmojiPanel() {
            emojiTarget = null;
            emojiShown = [];
            emojiActive = -1;

            if (emojiPanel) {
                emojiPanel.classList.add('d-none');
            }
        }

        // Escape leaves the word it read in the text, where it was typed: the panel
        // does not come back over the same word until it changes.
        function dismissEmoji() {
            var field = emojiTarget ? emojiTarget.field : null;

            if (emojiTarget && emojiTarget.mode === 'inline') {
                emojiDismissed = { field: field, shortcode: emojiTarget.shortcode };
            }

            closeEmojiPanel();

            if (field && field.__editor) {
                field.focus();
            }
        }

        function setEmojiActive(index) {
            var previous = emojiGridBox.children[emojiActive];

            if (previous) {
                previous.classList.remove('active');
            }

            emojiActive = index;

            var cell = emojiGridBox.children[index];

            if (cell) {
                cell.classList.add('active');
                // The arrows walk a list the panel does not show whole.
                cell.scrollIntoView({ block: 'nearest' });
            }
        }

        function moveEmojiActive(step) {
            if (emojiShown.length === 0) {
                return;
            }

            setEmojiActive(emojiActive < 0
                ? (step > 0 ? 0 : emojiShown.length - 1)
                : (emojiActive + step + emojiShown.length) % emojiShown.length);
        }

        // The keys of the panel, whether they are typed into its search field or
        // into the text of the part over the word it reads.
        function emojiKeys(event) {
            if (!emojiTarget) {
                return false;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                dismissEmoji();

                return true;
            }

            // Only up and down walk the grid: left and right belong to the caret of
            // the word being typed, in the text of a part as in the search field.
            var steps = { ArrowDown: 1, ArrowUp: -1 };

            if (Object.prototype.hasOwnProperty.call(steps, event.key)) {
                event.preventDefault();
                moveEmojiActive(steps[event.key]);

                return true;
            }
            if (event.key !== 'Enter' && event.key !== 'Tab') {
                return false;
            }
            // Nothing is chosen while the search field is empty; Tab is then left to
            // the browser, which is how a keyboard walks out of a panel it has
            // nothing to take from.
            if (emojiActive < 0 && event.key === 'Tab') {
                return false;
            }

            event.preventDefault();

            if (emojiActive < 0) {
                dismissEmoji();
            } else {
                insertEmoji(emojiShown[emojiActive]);
            }

            return true;
        }

        function toggleEmojiPicker(field) {
            if (emojiTarget && emojiTarget.mode === 'picker' && emojiTarget.field === field) {
                dismissEmoji();

                return;
            }

            openEmojiPanel(field, 'picker', null);
        }

        // The only way an emoji enters a part: the editor changes, source `user`, and
        // everything the page hangs off its text follows.
        function insertEmoji(item) {
            if (!emojiTarget) {
                return;
            }

            var field = emojiTarget.field;
            var editor = field.__editor;
            var at = emojiTarget.start;

            if (emojiTarget.length > 0) {
                editor.deleteText(at, emojiTarget.length, 'api');
            }
            editor.insertText(at, item.emoji, 'user');
            closeEmojiPanel();

            // The write of the mirror can cut the part anew and replace every field
            // of it but the first: the caret only goes back where a part of the page
            // still stands.
            if (textParts().indexOf(field) !== -1) {
                var caret = Math.min(at + item.emoji.length, field.value.length);

                editor.focus();
                editor.setSelection(caret, 0, 'api');
                field.__caret = [caret, caret];
            }
        }

        // Every edit of a part asks the panel of the colon: the word in front of the
        // caret is what it lists, and when there is none the panel closes.
        function updateEmojiTrigger(field, text) {
            if (!emojiPanel || (emojiTarget && emojiTarget.mode === 'picker')) {
                return;
            }

            var caret = field.__caret;
            var trigger = caret && caret[0] === caret[1] ? emojiTriggerAt(text, caret[0]) : null;

            if (trigger && emojiDismissed && emojiDismissed.field === field
                && emojiDismissed.shortcode === trigger.shortcode) {
                return;
            }

            if (!trigger) {
                emojiDismissed = null;

                if (emojiTarget && emojiTarget.field === field) {
                    closeEmojiPanel();
                }

                return;
            }

            if (emojiTarget && emojiTarget.field === field) {
                emojiTarget.start = trigger.start;
                emojiTarget.length = trigger.length;
                emojiTarget.shortcode = trigger.shortcode;
                renderEmojiPanel();

                return;
            }

            if (emojiTarget) {
                closeEmojiPanel();
            }

            openEmojiPanel(field, 'inline', trigger);
        }

        // The parts as the splitting and the merging see them: the text without
        // the «Часть N» prefix, and the entity offsets moved out of it too.
        function bareParts() {
            var fields = textParts();
            var values = fields.map(function (field) {
                return stripPartNumber(field.value);
            });

            return {
                fields: fields,
                values: values,
                formats: fields.map(function (field, index) {
                    var delta = values[index].length - field.value.length;

                    return takeEntities(shiftEntities(formattingOf(field), delta), 0, values[index].length);
                }),
            };
        }

        function textParts() {
            return Array.prototype.slice.call(partsBox.querySelectorAll('.publication-text-part'));
        }

        function isPartField(el) {
            return !!(el && el.classList && el.classList.contains('publication-text-part'));
        }

        function partValues() {
            return textParts().map(function (field) {
                return field.value;
            });
        }

        // The heading field of every part, in the order of the parts: the first
        // block holds the shared «Заголовок» the form submits on its own, the
        // cloned ones a field of the list behind it.
        function titleFields() {
            return Array.prototype.slice.call(partsBox.querySelectorAll('.publication-title-field'));
        }

        function readTitles() {
            return titleFields().map(function (field) {
                return field.value;
            });
        }

        // The album of a part is its own field, except for the first part: the
        // shared «Изображения публикации» textarea under the list is its album,
        // so the targets run one ahead of the part fields.
        function imageTargets() {
            return [imagesInput].concat(albumFields());
        }

        function readImageGroups() {
            return imageTargets().map(function (target) {
                return parseImageUrls(target ? target.value : '');
            });
        }

        function noteImageWrite(index) {
            albumWrites[index] = (albumWrites[index] || 0) + 1;
        }

        function writeImageGroup(index, urls) {
            var target = imageTargets()[index];
            if (!target) {
                return;
            }
            noteImageWrite(index);
            target.value = (urls || []).join('\n');
        }

        // The picker that stands beside each of those fields: a file chosen for
        // a part belongs to the album of that part and travels with its links.
        function fileTargets() {
            return [imageFilesInput].concat(albumFileFields());
        }

        function readFileGroups() {
            return fileTargets().map(function (target) {
                return target && target.files ? Array.prototype.slice.call(target.files) : [];
            });
        }

        // A rebuilt part block comes out of the template with an empty picker,
        // so a list gets back into a field only through DataTransfer — the one
        // way a script has of setting input.files at all.
        function writeFileGroup(index, files) {
            var target = fileTargets()[index];
            if (!target) {
                return;
            }
            var transfer = new DataTransfer();
            (files || []).forEach(function (file) {
                transfer.items.add(file);
            });
            target.files = transfer.files;
        }

        function writeFileGroups(groups) {
            var count = fileTargets().length;

            for (var index = 0; index < count; index++) {
                writeFileGroup(index, (groups || [])[index] || []);
            }
        }

        // `count` empty pickers, as the fields of the form see them.
        function emptyFileGroups(count) {
            return alignGroups([], count);
        }

        // What an album really holds: the links of its field with the files
        // picked for that part behind them — the list the channel will get once
        // the server has turned every file into a link of its own.
        function albumPictures() {
            var links = readImageGroups();
            var files = readFileGroups();

            return links.map(function (urls, index) {
                return urls.concat(files[index] || []);
            });
        }

        // The album of a part handed to its neighbour: the moved links and files
        // come after what the receiving field already holds, a link both fields
        // showed is named once, and the field they came from stands empty.
        function moveImageGroup(index, step) {
            var groups = readImageGroups();
            var files = readFileGroups();
            var to = index + step;

            // An empty album has nothing to move, so the buttons never turn into a
            // way to empty the field of a neighbour.
            if (index < 0 || to < 0 || to >= groups.length
                || groups[index].length + files[index].length === 0) {
                return;
            }

            writeImageGroup(to, unionGroups([groups[to], groups[index]]));
            writeImageGroup(index, []);
            writeFileGroup(to, unionGroups([files[to], files[index]]));
            writeFileGroup(index, []);
            renderNotice(albumNotices()[index], []);
            renderFilesNotice(albumFileNotices()[index], [], []);
            updateImages();
        }

        // Lists laid out along the parts: what a part has no list of its own
        // gets stands empty, as it does when the parts grow past them.
        function alignGroups(groups, count) {
            var list = [];

            for (var index = 0; index < count; index++) {
                list.push(Array.isArray(groups[index]) ? groups[index] : []);
            }

            return list;
        }

        // `count` albums with nothing in them, as the fields of the form see them.
        function emptyGroups(count) {
            var list = [];

            for (var index = 0; index < count; index++) {
                list.push('');
            }

            return list;
        }

        function asTexts(groups) {
            return groups.map(function (urls) {
                return urls.join('\n');
            });
        }

        // An album that is not handed to a part of its own goes to the first of
        // them: one text cut into several parts still travels with all its photos.
        function unionGroups(groups) {
            var urls = [];

            groups.forEach(function (group) {
                group.forEach(function (url) {
                    if (urls.indexOf(url) === -1) {
                        urls.push(url);
                    }
                });
            });

            return urls;
        }

        function flattenGroups(groups) {
            return groups.length > 0 ? [unionGroups(groups)] : [];
        }

        // The lists that stand where the parts already stood before a rebuild.
        function keepGroups(count) {
            return asTexts(alignGroups(readImageGroups(), count));
        }

        function setAlbumFields(groups) {
            var targets = imageTargets();

            for (var index = 0; index < targets.length; index++) {
                writeImageGroup(index, parseImageUrls(groups[index] || ''));
            }
        }

        // Only the parts after the first one show their album: the first of them
        // is served by the shared field, whose block keeps the box hidden.
        function albumBoxes() {
            return Array.prototype.slice
                .call(partsBox.querySelectorAll('.publication-part-album'))
                .slice(1);
        }

        function albumFields() {
            return albumBoxes().map(function (box) {
                return box.querySelector('.publication-part-album-field');
            });
        }

        function albumFileFields() {
            return albumBoxes().map(function (box) {
                return box.querySelector('.publication-part-album-files');
            });
        }

        function albumStrips() {
            return albumBoxes().map(function (box) {
                return box.querySelector('.publication-part-images');
            });
        }

        // The notice of a part comes after the notice of the shared field, which
        // is the one the first part writes its dead links into.
        function albumNotices() {
            return [imagesNotice].concat(albumBoxes().map(function (box) {
                return box.querySelector('.publication-images-notice');
            }));
        }

        // The notice of a refused pick stands beside the notice of the dead links,
        // in the place of the same album.
        function albumFileNotices() {
            return [imageFilesNotice].concat(albumBoxes().map(function (box) {
                return box.querySelector('.publication-files-notice');
            }));
        }

        function updateAlbumBoxes() {
            Array.prototype.slice
                .call(partsBox.querySelectorAll('.publication-part-album'))
                .forEach(function (box, index) {
                    box.classList.toggle('d-none', index === 0);
                });
        }

        // --- the link buttons of a part -------------------------------------------

        // The shared «Кнопки-ссылки» field of the form carries the buttons of the
        // first part, so the box of that part stays hidden and disabled the same
        // way the album box does. The switch of the field shows the box of every
        // part after it and puts the buttons of the shared field into each one.
        // A box holds one row per button: the two fields of it and the icon that
        // takes the row out of the form again.

        function buttonBoxes() {
            return Array.prototype.slice
                .call(partsBox.querySelectorAll('.publication-part-button'))
                .slice(1);
        }

        function rowsBoxOf(box) {
            return box.querySelector('.publication-button-rows');
        }

        function buttonRowsOf(box) {
            return Array.prototype.slice.call(rowsBoxOf(box).querySelectorAll('.publication-button-row'));
        }

        function buttonFieldsOf(row) {
            return [
                row.querySelector('.publication-button-text'),
                row.querySelector('.publication-button-url'),
            ];
        }

        function addButtonOf(box) {
            return box.querySelector('.publication-button-add');
        }

        function readButtonRow(row) {
            var fields = buttonFieldsOf(row);

            return {
                text: fields[0].value.trim(),
                url: fields[1].value.trim(),
            };
        }

        function isEmptyButton(button) {
            return button.text === '' && button.url === '';
        }

        // The buttons a box holds. An empty row is no button and drops out of the
        // list; a half row stays, so that the preview shows none of it and the
        // server refuses it the way it refused a half button before.
        function buttonsOf(box) {
            return buttonRowsOf(box).map(readButtonRow).filter(function (button) {
                return !isEmptyButton(button);
            });
        }

        // Every row of a box carries the name of that box, so the form submits the
        // rows of one part as one list, and an id of its own, so the label of the
        // box points at one field. The bases of the names move with the box.
        function nameButtonRows(box) {
            var bases = [
                rowsBoxOf(box).getAttribute('data-text'),
                rowsBoxOf(box).getAttribute('data-url'),
            ];

            buttonRowsOf(box).forEach(function (row, index) {
                buttonFieldsOf(row).forEach(function (field, column) {
                    field.name = bases[column] + '[]';
                    field.id = bases[column] + '-' + index;
                });
            });
            box.querySelector('label').setAttribute('for', bases[0] + '-0');
        }

        // A box holds one row per button and always at least one row: the blank
        // field the form starts with is how a part goes without a button.
        function writeButtons(box, buttons) {
            var rowsBox = rowsBoxOf(box);
            var rows = buttonRowsOf(box);
            var count = Math.max(1, Math.min(buttons.length, __BUTTON_LIMIT));

            while (rows.length > count) {
                rowsBox.removeChild(rows.pop());
            }
            for (var added = rows.length; added < count; added++) {
                rowsBox.appendChild(buttonRowTemplate.cloneNode(true));
            }

            buttonRowsOf(box).forEach(function (row, index) {
                var fields = buttonFieldsOf(row);
                var button = buttons[index] || {text: '', url: ''};
                fields[0].value = button.text;
                fields[1].value = button.url;
            });
            nameButtonRows(box);
        }

        function addButtonRow(box) {
            if (buttonRowsOf(box).length >= __BUTTON_LIMIT) {
                return;
            }
            rowsBoxOf(box).appendChild(buttonRowTemplate.cloneNode(true));
            nameButtonRows(box);
        }

        function removeButtonRow(button) {
            var row = button.closest('.publication-button-row');
            var box = row.closest('.publication-button-box');

            if (buttonRowsOf(box).length < 2) {
                return;
            }
            rowsBoxOf(box).removeChild(row);
            nameButtonRows(box);
        }

        // A row goes out by its own icon, and the one row of a box keeps its icon
        // hidden; the icon of adding stops at the bound the portal gives a message.
        // The icon goes out with its column, so that the address field of a row
        // without an icon reaches the same right edge the icon stands at.
        function updateButtonRows(box, enabled) {
            var rows = buttonRowsOf(box);

            rows.forEach(function (row) {
                var remove = row.querySelector('.publication-button-remove');
                remove.parentElement.classList.toggle('d-none', rows.length < 2);
                remove.disabled = !enabled;
            });
            addButtonOf(box).disabled = !enabled || rows.length >= __BUTTON_LIMIT;
        }

        // The buttons of every part, the shared field taking the first of them.
        function readButtonGroups() {
            var boxes = buttonBoxes();

            return textParts().map(function (field, index) {
                return buttonsOf(index === 0 ? sharedButtonBox : boxes[index - 1]);
            });
        }

        function isEveryPartButton() {
            return !!(buttonEveryPartInput && buttonEveryPartInput.checked)
                && textParts().length > 1;
        }

        // The boxes hold the buttons of the shared field while the switch is on and
        // stand empty while it is off: what a hidden box keeps must not reach the
        // form or the preview. A rebuild of the parts puts the buttons in again.
        function syncPartButtons() {
            var buttons = isEveryPartButton() ? buttonsOf(sharedButtonBox) : [];

            buttonBoxes().forEach(function (box) {
                writeButtons(box, buttons);
            });
        }

        // The switch of the parts is a field of a split publication only, and a
        // box answers for the form just while it shows: a disabled field is not
        // submitted, so a part with no box goes to the channel with no buttons.
        function updateButtonBoxes() {
            var shown = isEveryPartButton();

            if (buttonEveryPartRow) {
                buttonEveryPartRow.classList.toggle('d-none', textParts().length < 2);
            }
            buttonBoxes().forEach(function (box) {
                box.classList.toggle('d-none', !shown);
                buttonRowsOf(box).forEach(function (row) {
                    buttonFieldsOf(row).forEach(function (field) {
                        field.disabled = !shown;
                    });
                });
                updateButtonRows(box, shown);
            });
            updateButtonRows(sharedButtonBox, true);
        }

        // The addresses of the text handed to the buttons of the same part. This
        // only ever appends: a row the person filled by hand stays where it stands,
        // and an address a box already carries is not added a second time.
        function fillButtonsFromText() {
            var fields = textParts();
            var boxes = buttonBoxes();
            var changed = false;

            if (fields.length > 1 && buttonEveryPartInput && !buttonEveryPartInput.checked) {
                // A part submits buttons only while its own box shows, so the parts
                // take their own fields along with the addresses of their own text.
                buttonEveryPartInput.checked = true;
                updateButtonBoxes();
            }

            fields.forEach(function (field, index) {
                var box = index === 0 ? sharedButtonBox : boxes[index - 1];
                var buttons = buttonsOf(box);
                var known = buttons.map(function (button) {
                    return linkAddressOf(button.url);
                });
                var fresh = [];

                addressesInText(field.value, formattingOf(field)).forEach(function (address) {
                    var value = linkAddressOf(address);

                    if (value === null || value === '' || known.indexOf(value) !== -1) {
                        return;
                    }
                    known.push(value);
                    fresh.push({ text: buttonLabelOf(value, __BUTTON_LABEL_BYTES), url: value });
                });

                if (fresh.length === 0) {
                    return;
                }
                writeButtons(box, buttons.concat(fresh));
                changed = true;
            });

            if (!changed) {
                return;
            }
            updateButtonBoxes();
            update();
        }

        // Ten seconds of the text left alone: long enough that a pause between two
        // sentences is not a finished draft, short enough to answer to the one it is.
        var __LINKS_TO_BUTTONS_IDLE = 10000;
        var linksToButtonsTimer = null;

        // The fill comes after the last keystroke, not with every one of them, so
        // each change of a text field puts the waiting off again.
        function armLinksToButtons() {
            if (linksToButtonsTimer) {
                clearTimeout(linksToButtonsTimer);
                linksToButtonsTimer = null;
            }
            if (!linksToButtonsInput || !linksToButtonsInput.checked) {
                return;
            }
            linksToButtonsTimer = setTimeout(function () {
                linksToButtonsTimer = null;
                fillButtonsFromText();
            }, __LINKS_TO_BUTTONS_IDLE);
        }

        // A rebuild of the parts rewrites every album, so a notice about links a
        // probe dropped earlier has nothing left to point at.
        function clearNotices() {
            albumNotices().concat(albumFileNotices()).forEach(function (notice) {
                if (notice) {
                    notice.classList.add('d-none');
                }
            });
        }

        // The two albums of a join come together in the place of the first one,
        // and the row of the part that went away closes up.
        function spliceImageGroups(groups, index) {
            var list = groups.slice();

            list[index] = unionGroups([list[index], list[index + 1]]);
            list.splice(index + 1, 1);

            return list;
        }

        function isNumbered() {
            return !!(numberPartsInput && numberPartsInput.checked);
        }

        // The heading travels inside the same message as the text, so it eats into
        // the length a text is split at too: a heading costs its own length plus
        // the blank line under it, and the longest one of the parts sets the
        // budget of all of them — a split never promises a message the channel
        // would refuse.
        function headingReserve() {
            var longest = readTitles().reduce(function (best, title) {
                return Math.max(best, title.trim().length);
            }, 0);

            return longest === 0 ? 0 : longest + 2;
        }

        // The «Часть N» prefix travels inside the message, so it eats into the
        // length a text is split at. The reserve covers the line of the number.
        function partLimit() {
            return __TEXT_PART_LIMIT - headingReserve()
                - (isNumbered() ? __NUMBERING_RESERVE : 0);
        }

        function stripPartNumber(text) {
            return text.slice(partNumberPrefix(text).length);
        }

        // Break at the last paragraph, then line, then word that still fits;
        // a text without any of those near the boundary is cut hard.
        function splitIntoParts(text) {
            var limit = partLimit();
            var parts = [];
            var rest = text;

            while (rest.length > limit) {
                var cut = rest.lastIndexOf('\n\n', limit);
                if (cut < Math.floor(limit / 2)) {
                    cut = rest.lastIndexOf('\n', limit);
                }
                if (cut < Math.floor(limit / 2)) {
                    cut = rest.lastIndexOf(' ', limit);
                }
                if (cut < Math.floor(limit / 2)) {
                    // Nothing of the text fits the boundary: it is cut where it
                    // stands, only never in the middle of an emoji.
                    cut = wholeCharacter(rest, limit);
                }
                parts.push(rest.slice(0, cut).replace(/\s+$/, ''));
                rest = rest.slice(cut).replace(/^\s+/, '');
            }

            if (rest !== '') {
                parts.push(rest);
            }

            return parts.length > 0 ? parts : [''];
        }

        function applyPartNumbers() {
            var fields = textParts();
            if (fields.length < 2) {
                return;
            }

            fields.forEach(function (field, index) {
                var bare = stripPartNumber(field.value);
                field.value = isNumbered() ? 'Часть ' + (index + 1) + '\n\n' + bare : bare;
            });
        }

        function updateCounters() {
            var titles = readTitles();

            textParts().forEach(function (field, index) {
                var counter = field.closest('.publication-text-block').querySelector('.publication-text-count');
                if (!counter) {
                    return;
                }
                // The number of the message the channel gets, not of the text in
                // the field: a heading the part carries is part of that message.
                var shown = composedMessage(titles[index] || '', field.value, formattingOf(field)).text.length;
                counter.textContent = shown + ' / ' + __TEXT_PART_LIMIT;
                counter.className = 'publication-text-count'
                    + (shown > __TEXT_PART_LIMIT ? ' text-danger' : ' text-muted');
            });
        }

        // Every part but the last one carries the row that merges it with the
        // neighbour below, so the last row of the form stays hidden.
        function mergeRows() {
            return Array.prototype.slice.call(partsBox.querySelectorAll('.publication-merge-row'));
        }

        function updateMergeRows() {
            var rows = mergeRows();

            rows.forEach(function (row, index) {
                row.classList.toggle('d-none', index === rows.length - 1);
            });
        }

        // Only the last part has no neighbour below it to hand its album to; the
        // first of the boxes always has one above, which is the shared field.
        function updateAlbumMoves() {
            var boxes = albumBoxes();

            boxes.forEach(function (box, index) {
                var button = box.querySelector('[data-move-album="1"]');

                if (button) {
                    button.classList.toggle('d-none', index === boxes.length - 1);
                }
            });
        }

        // `groups` is the album of every part, the shared field taking the first
        // of them. Without it the lists that are already in the form keep their
        // part, and only the parts that appear get an empty one. `files` holds
        // the picked files of every part the same way. `formats` is the
        // highlighting of every part, counted over the text of `values` — the
        // «Часть N» prefix is added after it, and the spans move along with it.
        // `titles` is the heading of every part; without it a part keeps the
        // heading that was in its field, and a part that appears gets none —
        // merging two parts keeps the heading of the one that stays, since a
        // message has one first line.
        function setTextParts(values, groups, files, formats, titles) {
            // The blocks that are about to be replaced carry the pickers and the
            // headings of the parts, so both are read while the fields still exist.
            var carriedFiles = files === undefined ? readFileGroups() : files;
            var carriedTitles = titles === undefined ? readTitles() : titles;

            // The fields are replaced, and with them the selection the popup points at.
            hideSelectionActions();
            // And the part the emoji panel types into, which is gone by the time the
            // next of them is clicked.
            closeEmojiPanel();

            Array.prototype.slice
                .call(partsBox.querySelectorAll('.publication-text-block'), 1)
                .forEach(function (block) {
                    block.parentNode.removeChild(block);
                });

            values.forEach(function (value, index) {
                if (index === 0) {
                    seedPart(source, value, (formats || [])[0]);
                    return;
                }
                var block = partTemplate.cloneNode(true);
                var field = block.querySelector('.publication-text-part');
                field.id = 'publicationTextInput' + (index + 1);
                block.querySelector('.publication-text-label').setAttribute('for', field.id);

                // A cloned block is one part of the list of headings the form
                // submits, so it loses the name of the shared field it was
                // cloned with.
                var title = block.querySelector('.publication-title-field');
                title.id = 'publicationPartTitle' + (index + 1);
                title.name = 'publicationPartTitle[]';
                block.querySelector('.publication-title-label').setAttribute('for', title.id);

                var album = block.querySelector('.publication-part-album-field');
                album.id = 'publicationPartImages' + (index + 1);
                album.disabled = false;
                block.querySelector('.publication-part-album label').setAttribute('for', album.id);

                // The picker of a part is named after its place in the list of
                // parts: the first part has none of its own, its album is the
                // shared field, so the names run one behind the parts.
                var picker = block.querySelector('.publication-part-album-files');
                picker.id = 'publicationPartImageFiles' + (index + 1);
                picker.name = 'publicationPartImageFiles' + (index - 1) + '[]';
                picker.disabled = false;
                block.querySelector('.publication-part-album-files-label').setAttribute('for', picker.id);

                block.querySelector('.publication-part-album').classList.remove('d-none');

                // The rows of a part are named after its place in the list too: the
                // first part is served by the shared field, so the names run one
                // behind the parts.
                var groupBoxes = block.querySelector('.publication-part-button .publication-button-rows');
                groupBoxes.setAttribute('data-text', 'publicationPartButtonText' + (index - 1));
                groupBoxes.setAttribute('data-url', 'publicationPartButtonUrl' + (index - 1));
                nameButtonRows(block.querySelector('.publication-part-button'));

                partsBox.appendChild(block);
                field.value = value;
                field.__formatting = (formats || [])[index] || [];
            });

            textParts().forEach(function (field, index) {
                var block = field.closest('.publication-text-block');
                var label = block.querySelector('.publication-text-label');
                label.textContent = values.length > 1 ? 'Часть ' + (index + 1) : 'Текст публикации';
                block.querySelector('.publication-part-album-field').disabled = index === 0;
                block.querySelector('.publication-part-album-files').disabled = index === 0;
            });

            titleFields().forEach(function (field, index) {
                field.value = carriedTitles[index] || '';
            });

            mountPartEditors();

            setAlbumFields(groups === undefined ? keepGroups(values.length) : groups);
            writeFileGroups(carriedFiles);
            updateAlbumBoxes();
            syncPartButtons();
            updateButtonBoxes();
            clearNotices();

            updateMergeRows();
            updateAlbumMoves();
            linkifyParts();
            applyPartNumbers();
            updateCounters();
            updateImages();
            writeFormattingFields();
            armLinksToButtons();
        }

        // Splitting runs when a text arrives from outside — a forum post, a
        // record opened for editing — and while the form still holds one field.
        // Parts the user split by hand are left alone unless one of them no
        // longer fits a message.
        function splitIfNeeded() {
            var limit = partLimit();
            var overflowing = partValues().filter(function (value) {
                return value.length > limit;
            });

            if (overflowing.length === 0) {
                return false;
            }

            var bare = bareParts();
            var text = bare.values.join('\n\n');
            var parts = splitIntoParts(text);

            // The whole text is cut anew, so the albums of the parts come together
            // in the first of them: none of the new parts is the one a list was
            // written for.
            setTextParts(parts,
                asTexts(flattenGroups(readImageGroups())),
                flattenGroups(readFileGroups()),
                formatsOfParts(parts, text, joinFormats(bare.values, bare.formats)));

            return true;
        }

        // The highlighting a record was saved with: the list of entities travels
        // in the attribute of its row, in the same shape the form submits it.
        function parseFormatting(raw) {
            return raw ? JSON.parse(raw) : [];
        }

        // The first line of a forum fill is the heading of the publication when the
        // switch of the form header asks for it: a topic stands its own name in
        // front of its text, and a post opens with a line its author wrote as one.
        // A text that leaves nothing behind its first line is handed over whole —
        // the heading of a part is optional, its text is not.
        function firstLineAsHeading(text) {
            var body = (titleFromFirstLineInput && titleFromFirstLineInput.checked)
                ? String(text).replace(/^\s+/, '') : '';
            var cut = body.indexOf("\n");
            var heading = (cut < 0 ? body : body.slice(0, cut)).trim();
            var rest = cut < 0 ? '' : body.slice(cut).replace(/^\s+/, '');

            return heading === '' || rest === ''
                ? { title: '', text: text }
                : { title: heading, text: rest };
        }

        // The text of a record or a forum post arrives as one run of it, and the
        // album that comes with it belongs to its first part — the field of the
        // fill writes that one, so the parts of this list start out empty.
        // `formatting` is the highlighting the record was saved with, cut up along
        // with the text the parts are made of. `title` is the heading of the
        // record, which opens the first of those parts; a forum post has none.
        function loadText(text, formatting, title) {
            var parts = splitIntoParts(text);

            setTextParts(parts,
                emptyGroups(parts.length),
                emptyFileGroups(parts.length),
                formatsOfParts(parts, text, formatting || []),
                [title || '']);
        }

        // The buttons a record was saved with: the jsonb column of its row holds
        // the list the form writes, and a record saved before the list existed
        // holds the one object of its single button.
        function parseButtons(raw) {
            if (!raw) {
                return [];
            }

            try {
                var stored = JSON.parse(raw);
            } catch (e) {
                return [];
            }
            if (!stored || typeof stored !== 'object') {
                return [];
            }

            var buttons = [];
            (Array.isArray(stored) ? stored : [stored]).forEach(function (item) {
                if (!item || typeof item !== 'object') {
                    return;
                }
                var button = {
                    text: typeof item.text === 'string' ? item.text : '',
                    url: typeof item.url === 'string' ? item.url : '',
                };
                if (!isEmptyButton(button)) {
                    buttons.push(button);
                }
            });

            return buttons;
        }

        // The record is one part, and its buttons are the shared field of the form.
        // A long text fills several parts from it, so the boxes of those parts
        // come back to the buttons the record really holds.
        function fillButtons(raw) {
            writeButtons(sharedButtonBox, parseButtons(raw));
            syncPartButtons();
            updateButtonBoxes();
            update();
        }

        // --- manual split of one part into two --------------------------------

        // A cut is only accepted when it leaves text on both sides, so no snap
        // can produce an empty or a blank part.
        function usableCut(text, cut) {
            return cut > 0 && cut < text.length
                && text.slice(0, cut).trim() !== '' && text.slice(cut).trim() !== '';
        }

        // A sentence ends at . ! ? … followed by whitespace.
        function endsSentence(text, cut) {
            if (cut <= 0 || cut >= text.length) {
                return false;
            }

            return '.!?…'.indexOf(text[cut - 1]) !== -1 && /\s/.test(text[cut]);
        }

        function endsLine(text, cut) {
            return cut > 0 && cut < text.length && text[cut - 1] === '\n';
        }

        function endsParagraph(text, cut) {
            return endsLine(text, cut) && text[cut - 2] === '\n';
        }

        // The first character of a word after whitespace: cutting there keeps the
        // words on both sides whole.
        function endsWord(text, cut) {
            return cut > 0 && cut < text.length && /\s/.test(text[cut - 1]) && !/\s/.test(text[cut]);
        }

        // Closest accepted position to `from`. The side before it is tried first,
        // so an equal distance keeps the bigger piece in the part being split.
        function snapCut(text, from, isCut, range) {
            for (var distance = 0; distance <= range; distance++) {
                var before = from - distance;
                if (isCut(text, before) && usableCut(text, before)) {
                    return before;
                }

                var after = from + distance;
                if (distance > 0 && isCut(text, after) && usableCut(text, after)) {
                    return after;
                }
            }

            return -1;
        }

        // A sentence far away from the caret is not the place the user meant, so
        // the snap only reaches as far as __SNAP_RANGE and then leaves the caret
        // where it is.
        function manualCut(text, caret) {
            if (caret <= 0 || caret >= text.length) {
                return -1;
            }

            var sentence = snapCut(text, caret, endsSentence, __SNAP_RANGE);
            if (sentence !== -1) {
                return sentence;
            }

            var word = snapCut(text, caret, endsWord, __SNAP_RANGE);
            if (word !== -1) {
                return word;
            }

            var at = wholeCharacter(text, caret);

            return usableCut(text, at) ? at : -1;
        }

        // Without a caret the text is halved at the closest break: a blank line,
        // then the end of a line, then a word edge.
        function middleCut(text) {
            var half = Math.floor(text.length / 2);
            var breaks = [endsParagraph, endsLine, endsWord];

            for (var index = 0; index < breaks.length; index++) {
                var cut = snapCut(text, half, breaks[index], text.length);
                if (cut !== -1) {
                    return cut;
                }
            }

            var at = wholeCharacter(text, half);

            return usableCut(text, at) ? at : -1;
        }

        // The field the caret was in last: a click on the button pulls the focus
        // out of the textarea before the handler runs, so activeElement is the
        // button by then and the remembered field is what carries the caret.
        var lastEditedField = null;

        function splitTarget(fields, values) {
            var index = fields.indexOf(lastEditedField);
            if (index !== -1 && values[index].trim() !== '') {
                return index;
            }

            // Nothing focused to go by: the longest part is the one worth halving.
            var longest = -1;
            values.forEach(function (value, position) {
                if (value.trim() === '') {
                    return;
                }

                if (longest === -1 || value.length > values[longest].length) {
                    longest = position;
                }
            });

            return longest;
        }

        function splitPartAtCaret() {
            var bare = bareParts();
            var index = splitTarget(bare.fields, bare.values);

            if (index === -1) {
                return;
            }

            var text = bare.values[index];
            // The «Часть N» prefix travels in the shown value, so the caret offset
            // has to be moved out of it before it points into the text.
            var caret = (bare.fields[index].selectionStart || 0) - (bare.fields[index].value.length - text.length);
            var cut = manualCut(text, caret);
            if (cut === -1) {
                cut = middleCut(text);
            }

            if (cut === -1) {
                return;
            }

            var head = text.slice(0, cut).replace(/\s+$/, '');
            var tail = text.slice(cut).replace(/^\s+/, '');
            // The highlighting is cut with the text: each half keeps the spans
            // standing on it, and the whitespace the seam swallows takes theirs
            // out of the list along with itself.
            var halves = splitEntitiesAt(bare.formats[index], cut);
            var formats = bare.formats.slice();

            formats.splice(index, 1,
                takeEntities(halves.head, 0, head.length),
                takeEntities(halves.tail, text.length - cut - tail.length, Number.MAX_SAFE_INTEGER)
            );

            var groups = alignGroups(readImageGroups(), bare.values.length);
            var files = alignGroups(readFileGroups(), bare.values.length);
            var titles = readTitles();

            // The part that starts below the caret begins without pictures of its
            // own: the album stays with the text it was attached to, and so does
            // the heading it opened with.
            groups.splice(index + 1, 0, []);
            files.splice(index + 1, 0, []);
            titles.splice(index + 1, 0, '');

            setTextParts(bare.values.slice(0, index)
                .concat([head, tail])
                .concat(bare.values.slice(index + 1)), asTexts(groups), files, formats, titles);

            caretOfPart(textParts()[index + 1], 0);
        }

        // --- manual split of the whole text at boundaries of one kind ---------

        // A run of newlines is one seam, so neither an empty line nor a run of
        // breaks leaves an empty part behind.
        function breakCuts(text, seams) {
            var cuts = [];
            var pattern = new RegExp(seams, 'g');
            var match;

            while ((match = pattern.exec(text)) !== null) {
                cuts.push(match.index);
            }

            return cuts;
        }

        // Sentences are not a newline pattern: a part ends at a stop, an exclamation,
        // a question or an ellipsis, wherever the line breaks happen to fall.
        function sentenceCuts(text) {
            var cuts = [];

            for (var index = 1; index < text.length; index++) {
                if (endsSentence(text, index)) {
                    cuts.push(index);
                }
            }

            return cuts;
        }

        function wholeTextCuts(text, mode) {
            if (mode === 'sentences') {
                return sentenceCuts(text);
            }
            if (mode === 'lines') {
                return breakCuts(text, '\\n+');
            }

            return breakCuts(text, '\\n{2,}');
        }

        // What stands between two cuts, with the seam whitespace around it dropped.
        function piecesAt(text, cuts) {
            var pieces = [];
            var from = 0;

            cuts.concat([text.length]).forEach(function (cut) {
                var piece = text.slice(from, cut).trim();

                from = cut;
                if (piece !== '') {
                    pieces.push(piece);
                }
            });

            return pieces;
        }

        // One part per paragraph, line or sentence, however short it comes out —
        // unlike the automatic split, which never leaves a part under half a message.
        // A piece that does not fit one message is still cut down by that rule,
        // because the server rejects a longer part.
        function splitWholeText(mode) {
            var bare = bareParts();
            var text = bare.values.join('\n\n');
            var parts = [];

            piecesAt(text, wholeTextCuts(text, mode)).forEach(function (piece) {
                splitIntoParts(piece).forEach(function (one) {
                    parts.push(one);
                });
            });

            setTextParts(parts.length > 0 ? parts : [''],
                asTexts(flattenGroups(readImageGroups())),
                flattenGroups(readFileGroups()),
                formatsOfParts(parts, text, joinFormats(bare.values, bare.formats)),
                // The whole run is laid out again, so the only heading that survives
                // is the one the publication opens with.
                [readTitles()[0] || '']);
        }

        // --- joining two neighbouring parts -------------------------------------

        // The seam of a join is a paragraph: both sides keep their own text.
        function joinParts(first, second) {
            var head = first.replace(/\s+$/, '');
            var tail = second.replace(/^\s+/, '');

            if (head === '') {
                return tail;
            }

            return tail === '' ? head : head + '\n\n' + tail;
        }

        // The highlighting of the same join: the spans of the second part stand
        // behind the seam the first one grew, and whatever the seam swallowed of
        // them goes away with the whitespace it was made of.
        function joinedFormats(first, firstFormats, second, secondFormats) {
            var head = first.replace(/\s+$/, '');
            var tail = second.replace(/^\s+/, '');
            // Where the tail of the second part begins in the joined text: read off
            // the join itself, so the seam stays defined in one place.
            var at = joinParts(first, second).length - tail.length;

            return sortEntities(
                takeEntities(firstFormats, 0, head.length)
                    .concat(shiftEntities(
                        takeEntities(secondFormats, second.length - tail.length, Number.MAX_SAFE_INTEGER),
                        at
                    ))
            );
        }

        // Two neighbouring parts into one: the seam becomes a paragraph, exactly
        // the way the automatic split and the moved selections join text.
        function mergeParts(index) {
            var bare = bareParts();

            if (index < 0 || index + 1 >= bare.values.length) {
                return;
            }

            var seam = bare.values[index].replace(/\s+$/, '').length;
            var groups = alignGroups(readImageGroups(), bare.values.length);

            // The album of the part that goes away joins the one it grew into.
            groups[index] = unionGroups([groups[index], groups[index + 1]]);
            groups.splice(index + 1, 1);

            var files = spliceImageGroups(alignGroups(readFileGroups(), bare.values.length), index);
            // The heading of the part that goes away goes with it: one message has
            // one first line, and the part it grew into keeps its own.
            var titles = readTitles();

            titles.splice(index + 1, 1);

            var merged = joinedFormats(
                bare.values[index],
                bare.formats[index],
                bare.values[index + 1],
                bare.formats[index + 1]
            );

            bare.values[index] = joinParts(bare.values[index], bare.values[index + 1]);
            bare.formats[index] = merged;
            bare.values.splice(index + 1, 1);
            bare.formats.splice(index + 1, 1);
            setTextParts(bare.values, asTexts(groups), files, bare.formats, titles);

            // The joined text does not have to fit one message, so it goes back
            // through the split when it stopped fitting.
            if (splitIfNeeded()) {
                return;
            }

            // The caret marks the place the two parts grew together at.
            caretOfPart(textParts()[index], seam);
        }

        // A position in the text of a part, moved into the field as the user sees
        // it: the line of the number is part of what stands there, and the caret
        // of an editor answers for the hidden field it mirrors.
        function caretOfPart(field, position) {
            if (!field) {
                return;
            }

            var at = position + (field.value.length - stripPartNumber(field.value).length);

            field.focus();
            field.setSelectionRange(at, at);
        }

        // --- moving a selection of text between two parts ----------------------

        // Forward puts the selection in front of the next part, backward — after
        // the previous one, so the reading order survives. A part the move empties
        // is dropped, and at the edge of the form the text gets a part of its own.
        function shiftSelectedText(values, formats, index, from, to, forward) {
            var text = values[index];
            var list = formats[index];
            var raw = text.slice(from, to);
            var moved = raw.replace(/^\s+/, '').replace(/\s+$/, '');

            if (moved === '') {
                return null;
            }

            // Whitespace the cut carried away comes back as a single separator, so
            // the words on both sides of it do not grow together.
            var before = text.slice(0, from);
            var after = text.slice(to);
            var head = before.replace(/\s+$/, '');
            var tail = after.replace(/^\s+/, '');
            var wholeWords = head === before && tail === after;
            var source = wholeWords || head === '' || tail === ''
                ? head + tail
                : head + ' ' + tail;
            // The highlighting of what stays: the spans before the cut hold their
            // place, the ones behind it close the gap the selection left.
            var sourceFormats = sortEntities(
                takeEntities(list, 0, head.length)
                    .concat(shiftEntities(
                        takeEntities(list, to, Number.MAX_SAFE_INTEGER),
                        source.length - tail.length
                    ))
            );
            // The piece that travels carries the spans it stood under, cut out of
            // the part and counted from its own beginning again.
            var lead = raw.length - raw.replace(/^\s+/, '').length;
            var piece = takeEntities(
                shiftEntities(takeEntities(list, from, to), -lead),
                0,
                moved.length
            );
            var target = forward ? index + 1 : index - 1;
            var next = values.slice();
            var nextFormats = formats.slice();
            next[index] = source;
            nextFormats[index] = sourceFormats;

            if (target < 0) {
                next.unshift(moved);
                nextFormats.unshift(piece);
                target = 0;
            } else if (target >= next.length) {
                next.push(moved);
                nextFormats.push(piece);
            } else {
                next[target] = forward
                    ? joinParts(moved, next[target])
                    : joinParts(next[target], moved);
                nextFormats[target] = forward
                    ? joinedFormats(moved, piece, values[target], formats[target])
                    : joinedFormats(values[target], formats[target], moved, piece);
            }

            var caret = forward ? moved.length : next[target].length;

            if (source === '') {
                next.splice(index, 1);
                nextFormats.splice(index, 1);
                if (index < target) {
                    target -= 1;
                }
            }

            return { values: next, formats: nextFormats, index: target, caret: caret };
        }

        var selectionPopup = document.getElementById('publicationSelectionActions');
        var movePrevPartButton = document.getElementById('publicationMovePrevPart');
        var moveNextPartButton = document.getElementById('publicationMoveNextPart');
        // The field the popup was shown over: its selection is what moves.
        var selectionField = null;

        function hideSelectionActions() {
            selectionField = null;

            if (selectionPopup) {
                selectionPopup.classList.add('d-none');
            }
        }

        // Selection offsets of a field in the text without the «Часть N» prefix,
        // which is what the parts are stored as.
        function selectionRange(field) {
            var text = stripPartNumber(field.value);
            var prefix = field.value.length - text.length;

            return {
                text: text,
                from: Math.max(0, (field.selectionStart || 0) - prefix),
                to: Math.max(0, Math.min(text.length, (field.selectionEnd || 0) - prefix)),
            };
        }

        // The popup goes above the end of the selection, below it when the top of
        // the viewport is in the way, and inside the viewport on both axes.
        function popupPoint(caret, size, viewport) {
            var margin = 8;
            var left = Math.max(margin, Math.min(
                caret.x - size.width / 2,
                viewport.width - size.width - margin
            ));
            var top = caret.y - size.height - margin;

            if (top < margin) {
                top = caret.y + margin;
            }

            if (top + size.height > viewport.height - margin) {
                top = Math.max(margin, viewport.height - size.height - margin);
            }

            return { left: Math.round(left), top: Math.round(top) };
        }

        // The editor knows where a position of its text paints: the bounds of the
        // zero width span there, measured against the box that scrolls.
        function caretPoint(field, position) {
            var editor = field.__editor;
            var root = editorRootOf(field);

            if (!editor || !root) {
                return { x: 0, y: 0 };
            }

            var box = root.getBoundingClientRect();
            var bounds = editor.getBounds(
                Math.max(0, Math.min(position, editor.getLength() - 1)),
                0
            ) || { left: 0, top: 0, height: 0 };

            return { x: box.left + bounds.left, y: box.top + bounds.top + bounds.height };
        }

        function showSelectionActions(field) {
            var selection = selectionRange(field);

            if (!selectionPopup || textParts().length < 2
                || selection.text.slice(selection.from, selection.to).trim() === '') {
                hideSelectionActions();

                return;
            }

            // Measured while shown: a hidden popup has no size to place by. Both
            // happen in one task, so the old position never paints.
            selectionPopup.classList.remove('d-none');
            selectionField = field;

            var point = popupPoint(
                caretPoint(field, selection.to),
                { width: selectionPopup.offsetWidth, height: selectionPopup.offsetHeight },
                { width: window.innerWidth, height: window.innerHeight }
            );
            selectionPopup.style.left = point.left + 'px';
            selectionPopup.style.top = point.top + 'px';
        }

        function moveSelectedText(forward) {
            var index = textParts().indexOf(selectionField);

            if (index === -1) {
                hideSelectionActions();

                return;
            }

            var selection = selectionRange(selectionField);
            var bare = bareParts();
            var moved = shiftSelectedText(
                bare.values,
                bare.formats,
                index,
                selection.from,
                selection.to,
                forward
            );
            hideSelectionActions();

            if (!moved) {
                return;
            }

            // An album goes with the text it belongs to: a part the move emptied
            // leaves its pictures to the neighbour that took the selection, and a
            // part born at the edge of the form starts without any.
            var groups = alignGroups(readImageGroups(), bare.values.length);
            var files = alignGroups(readFileGroups(), bare.values.length);
            var titles = readTitles();

            if (moved.values.length === bare.values.length + 1) {
                groups.splice(moved.index, 0, []);
                files.splice(moved.index, 0, []);
                titles.splice(moved.index, 0, '');
            } else if (moved.values.length === bare.values.length - 1) {
                var joined = forward ? index : index - 1;
                groups = spliceImageGroups(groups, joined);
                files = spliceImageGroups(files, joined);
                titles.splice(joined + 1, 1);
            }

            setTextParts(moved.values, asTexts(groups), files, moved.formats, titles);

            // The caret stays where the moved text came to rest.
            caretOfPart(textParts()[moved.index], moved.caret);
        }

        var preview = document.getElementById('publicationPreview');
        var forumTypeInput = document.getElementById('forumEntityType');
        var forumIdInput = document.getElementById('forumEntityId');
        var imagesInput = document.getElementById('publicationImages');
        var imagesNotice = document.getElementById('publicationImagesNotice');
        var imageFilesInput = document.getElementById('publicationImageFiles');
        var imageFilesNotice = document.getElementById('publicationImageFilesNotice');
        var imagesPreview = document.getElementById('publicationImagesPreview');
        var previewImages = document.getElementById('publicationPreviewImages');
        var previewCardImgEl = document.getElementById('previewCardImgEl');
        var publicationAtInput = document.getElementById('publicationAt');
        var previewPublicationAt = document.getElementById('previewPublicationAt');
        var sourceTypeInput = document.getElementById('publicationSource');
        var sourceIdInput = document.getElementById('publicationSourceId');
        var publishedAtInput = document.getElementById('publicationAt');
        var scheduleModal = document.getElementById('scheduleModal');

        function updatePreviewPublicationAt() {
            if (!previewPublicationAt) return;
            var val = publicationAtInput ? publicationAtInput.value : '';
            previewPublicationAt.textContent = val || '';
        }

        var parseImageUrls = function (raw) {
            return (raw || '').split('\n').map(function (line) {
                return line.trim();
            }).filter(function (line) {
                return line !== '';
            });
        };

        // A picked file shows as a picture of its own: the previews are redrawn
        // on every keystroke, so one file keeps one object URL for as long as the
        // page holds it instead of buying a new one each time.
        var fileUrls = new WeakMap();

        function pictureUrl(picture) {
            if (typeof picture === 'string') {
                return picture;
            }

            var url = fileUrls.get(picture);
            if (!url) {
                url = URL.createObjectURL(picture);
                fileUrls.set(picture, url);
            }

            return url;
        }

        // The pictures of an album: links and files of one field side by side,
        // the links first, then what the picker of that field holds.
        var renderImagesPreview = function (container, pictures) {
            if (!container) {
                return;
            }
            var limit = __PREVIEW_LIMIT;
            container.innerHTML = '';
            if (!pictures.length) {
                container.classList.add('d-none');
                return;
            }
            pictures.slice(0, limit).forEach(function (picture) {
                var img = document.createElement('img');
                img.src = pictureUrl(picture);
                img.alt = 'Изображение публикации';
                container.appendChild(img);
            });
            if (pictures.length > limit) {
                var plus = document.createElement('span');
                plus.className = 'plus bg-danger';
                plus.textContent = '+' + (pictures.length - limit);
                container.appendChild(plus);
            }
            container.classList.remove('d-none');
        };

        var updateSingleImagePreview = function (pictures) {
            if (!previewCardImgEl) {
                return;
            }
            if (pictures.length === 1) {
                previewCardImgEl.src = pictureUrl(pictures[0]);
                previewCardImgEl.classList.remove('d-none');
                if (previewImages) {
                    previewImages.classList.add('d-none');
                }
            } else {
                previewCardImgEl.classList.add('d-none');
                previewCardImgEl.src = '';
            }
        };

        // The even split the album of the first part is handed out by: contiguous
        // slices that differ in size by at most one image.
        var groupImages = function (urls, partCount) {
            var groups = [];
            var base = Math.floor(urls.length / partCount);
            var extra = urls.length % partCount;
            var offset = 0;

            for (var index = 0; index < partCount; index++) {
                var size = base + (index < extra ? 1 : 0);
                groups.push(urls.slice(offset, offset + size));
                offset += size;
            }

            return groups;
        };

        // The strip under an album field shows the pictures of that field: its
        // links with the files picked for it, so every part of the preview
        // carries its own.
        var updateAlbumStrips = function () {
            var pictures = albumPictures();

            albumStrips().forEach(function (strip, index) {
                renderImagesPreview(strip, pictures[index + 1] || []);
            });
        };

        var updateImages = function () {
            renderImagesPreview(imagesPreview, albumPictures()[0] || []);
            updateAlbumStrips();
            update();
        };

        // The forum keeps the image links it once read, and a link can outlive
        // the file behind it. The only way to notice before the album goes to
        // the channel is to ask for every one of them; __IMAGE_PROBE_TIMEOUT
        // says how long an answer may take.
        // One counter per album of the form: it counts how often the list of that
        // place was rewritten, which is what a probe that came later answers for.
        var albumWrites = [];

        var probeImage = function (url) {
            return new Promise(function (resolve) {
                var settled = false;
                var timer = 0;
                var img = new Image();

                function finish(alive) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    clearTimeout(timer);
                    img.onload = null;
                    img.onerror = null;
                    resolve(alive);
                }
                timer = setTimeout(function () {
                    // A link that never answered is not proven dead: it stays.
                    finish(true);
                }, __IMAGE_PROBE_TIMEOUT);
                img.onload = function () { finish(true); };
                img.onerror = function () { finish(false); };
                img.src = url;
            });
        };

        var renderNotice = function (notice, deadUrls) {
            if (!notice) {
                return;
            }
            if (deadUrls.length === 0) {
                notice.classList.add('d-none');

                return;
            }
            notice.textContent = 'Мёртвые ссылки в изображения не добавлены ('
                + deadUrls.length + '): ' + deadUrls.join(', ');
            notice.classList.remove('d-none');
        };

        // The refusal of a pick is named under the field it came to: the album
        // keeps the size the settings page sets, and the rest waits for the user
        // to take it out of the picker himself.
        var renderFilesNotice = function (notice, tooBig, extra) {
            if (!notice) {
                return;
            }
            var lines = [];

            if (tooBig.length > 0) {
                lines.push('Файлы крупнее ' + __UPLOAD_MAX_MB + ' МБ в альбом не добавлены ('
                    + tooBig.length + '): ' + tooBig.join(', '));
            }
            if (extra.length > 0) {
                lines.push('Файлов больше ' + __UPLOAD_LIMIT + ' в альбоме не будет ('
                    + extra.length + '): ' + extra.join(', '));
            }
            if (lines.length === 0) {
                notice.classList.add('d-none');

                return;
            }
            notice.textContent = lines.join(' ');
            notice.classList.remove('d-none');
        };

        // What the browser just handed over is measured against the settings: the
        // files that fit stay in the picker, so FormData carries them to the form
        // the same way the links of the field do.
        var acceptPickedFiles = function (target) {
            var index = fileTargets().indexOf(target);

            if (index < 0) {
                return;
            }
            var maxBytes = __UPLOAD_MAX_MB * 1024 * 1024;
            var kept = [];
            var tooBig = [];
            var extra = [];

            readFileGroups()[index].forEach(function (file) {
                if (file.size > maxBytes) {
                    tooBig.push(file.name);
                } else if (kept.length >= __UPLOAD_LIMIT) {
                    extra.push(file.name);
                } else {
                    kept.push(file);
                }
            });

            writeFileGroup(index, kept);
            renderFilesNotice(albumFileNotices()[index], tooBig, extra);
            updateImages();
        };

        // The whole album goes into a field at once, so a form submitted while
        // the links are still being probed cannot lose a live image; the field
        // narrows to the ones that answered as soon as all of them have.
        var writeImages = function (index, raw) {
            if (!imageTargets()[index]) {
                return;
            }
            var urls = parseImageUrls(raw);
            var writes = albumWrites[index] || 0;

            writeImageGroup(index, urls);
            renderNotice(albumNotices()[index], []);
            updateImages();
            if (urls.length === 0) {
                return;
            }
            Promise.all(urls.map(probeImage)).then(function (answered) {
                // A list that was edited since — by hand or by another fill — is
                // not this one to cut down: the probe knows nothing about it.
                if ((albumWrites[index] || 0) !== writes + 1) {
                    return;
                }
                var kept = [];
                var dead = [];

                urls.forEach(function (url, position) {
                    (answered[position] ? kept : dead).push(url);
                });

                writeImageGroup(index, kept);
                renderNotice(albumNotices()[index], dead);
                updateImages();
            });
        };

        // A fill brings its album along with one text: until the parts of it are
        // known the links stand in the album of the first part, which is the
        // shared field of the form.
        var fillImages = function (raw) {
            writeImages(0, raw);
        };

        // The album of the first part handed out over the parts of the form the
        // way the channel will receive them: the pictures keep their order, the
        // slices come out contiguous and differ in size by at most one image.
        var distributeImages = function () {
            var groups = readImageGroups();
            var album = albumPictures()[0] || [];

            if (album.length === 0 || groups.length < 2) {
                return;
            }

            groupImages(album, groups.length).forEach(function (pictures, index) {
                writeImageGroup(index, pictures.filter(function (picture) {
                    return typeof picture === 'string';
                }));
                writeFileGroup(index, pictures.filter(function (picture) {
                    return typeof picture !== 'string';
                }));
            });

            // The option has done its work inside the form: what the fields hold
            // now is what travels to the server.
            if (distributeImagesInput) {
                distributeImagesInput.checked = false;
            }
            updateImages();
        };

        // The preview shows the highlighting the channel will show: the text is cut
        // at the edge of every span and each piece is wrapped in the tags of the
        // spans standing over it, nested in the order the list keeps them in.
        function renderFormattedText(node, text, entities) {
            var spans = (entities || []).filter(function (entity) {
                var tag = FORMAT_TAGS[entity.type];

                return !!tag
                    && entity.length > 0
                    && entity.offset >= 0
                    && entity.offset + entity.length <= text.length;
            });

            if (spans.length === 0) {
                node.textContent = text;

                return;
            }

            var edges = [0, text.length];
            spans.forEach(function (entity) {
                edges.push(entity.offset, entity.offset + entity.length);
            });
            var stops = edges.filter(function (edge, index) {
                return edges.indexOf(edge) === index;
            }).sort(function (a, b) {
                return a - b;
            });

            var fragment = document.createDocumentFragment();

            stops.slice(0, -1).forEach(function (from, index) {
                var to = stops[index + 1];
                var covering = spans.filter(function (entity) {
                    return entity.offset <= from && entity.offset + entity.length >= to;
                }).sort(function (a, b) {
                    return ENTITY_ORDER.indexOf(a.type) - ENTITY_ORDER.indexOf(b.type);
                });
                var piece = document.createTextNode(text.slice(from, to));

                covering.forEach(function (entity) {
                    var tag = document.createElement(FORMAT_TAGS[entity.type]);

                    if (entity.type === 'text_link') {
                        tag.setAttribute('href', entity.url || '#');
                    }
                    tag.appendChild(piece);
                    piece = tag;
                });
                fragment.appendChild(piece);
            });

            node.appendChild(fragment);
        }

        // The keyboard of a message: the buttons go into no more than
        // __KEYBOARD_MAX_ROWS rows, shared between them evenly, and the row that
        // takes the odd button is the last one — the same packing the sender puts
        // into the inline keyboard, in the order the form holds them. Half a pair
        // is no button: the form refuses it, and the preview shows none.
        function renderPreviewKeyboard(buttons) {
            var whole = (buttons || []).filter(function (button) {
                return button.text !== '' && button.url !== '';
            });

            if (whole.length === 0) {
                return null;
            }

            var keyboard = document.createElement('div');
            var rows = Math.min(__KEYBOARD_MAX_ROWS, whole.length);
            var even = Math.floor(whole.length / rows);
            var widest = whole.length % rows;
            var start = 0;

            for (var index = 0; index < rows; index++) {
                var size = even + (index >= rows - widest ? 1 : 0);
                var piece = whole.slice(start, start + size);
                start += size;

                var row = document.createElement('div');
                row.className = 'telegram-preview-keyboard-row';

                piece.forEach(function (button) {
                    var node = document.createElement('span');
                    node.className = 'telegram-preview-button';
                    node.textContent = button.text;
                    row.appendChild(node);
                });

                keyboard.appendChild(row);
            }

            return keyboard;
        }

        var update = function () {
            if (!preview) {
                return;
            }

            var albums = albumPictures();
            var fields = textParts();
            var buttons = readButtonGroups();
            var titles = readTitles();
            var shown = [];

            partValues().forEach(function (value, index) {
                if (value !== '') {
                    // Every card shows the message the channel will get: the
                    // heading of the part over its text, bold, with the number of
                    // a numbered part at the end of the heading.
                    var message = composedMessage(titles[index] || '', value, formattingOf(fields[index]));
                    shown.push({
                        text: message.text,
                        pictures: albums[index] || [],
                        entities: message.entities,
                        buttons: buttons[index] || [],
                    });
                }
            });

            var placeholder = preview.getAttribute('data-placeholder') || '';
            if (shown.length === 0) {
                shown.push({
                    text: placeholder,
                    pictures: albums[0] || [],
                    entities: [],
                    buttons: buttons[0] || [],
                });
            }

            // A publication that stands in several fields goes out as several
            // messages, so every one of them carries its own album.
            var many = shown.length > 1;

            preview.textContent = '';
            shown.forEach(function (one) {
                var part = document.createElement('div');
                part.className = 'event-content bg-light-subtle rounded-3 p-3 flex-grow-1 telegram-preview-text';
                if (many && one.pictures.length > 0) {
                    // The photos go first, the text of the part is their caption.
                    var box = document.createElement('div');
                    box.className = 'stacked-images publication-part-images';
                    part.appendChild(box);
                    renderImagesPreview(box, one.pictures);
                }
                var body = document.createElement('p');
                body.className = 'publication-preview-part';
                renderFormattedText(body, one.text, one.entities);
                part.appendChild(body);
                var keyboard = renderPreviewKeyboard(one.buttons);
                if (keyboard) {
                    part.appendChild(keyboard);
                }
                preview.appendChild(part);
            });

            // One part keeps the album in the strip under the fields, where a
            // single picture of it grows into the card of the preview.
            var first = shown[0].pictures;

            renderImagesPreview(previewImages, many ? [] : first);
            updateSingleImagePreview(many ? [] : first);
        };
        // One listener for every part field, including the ones cloned later. The
        // editor writes the field it mirrors through, so this is what a keystroke
        // and a click on Bold both end in.
        partsBox.addEventListener('input', function (event) {
            if (!isPartField(event.target)) {
                return;
            }
            // Typing replaces the selection the popup was pointing at.
            hideSelectionActions();
            writeFormattingFields();
            updateCounters();
            update();
            armLinksToButtons();
            if (textParts().length === 1) {
                splitIfNeeded();
            }
        });
        // A heading costs the part some of its text, so a long enough one makes a
        // part that fit overflow: the list is cut anew the way a new text cuts it.
        partsBox.addEventListener('input', function (event) {
            if (!event.target.classList || !event.target.classList.contains('publication-title-field')) {
                return;
            }
            splitIfNeeded();
            updateCounters();
            update();
        });
        // The albums of the parts, the cloned ones included: a list typed by hand
        // outranks the probe that was still asking about the one it replaced.
        partsBox.addEventListener('input', function (event) {
            var index = imageTargets().indexOf(event.target);

            if (index < 1) {
                return;
            }
            noteImageWrite(index);
            updateImages();
        });
        // A pick is a change of the field it filled, and only the part fields
        // live inside the box; the shared one stands below the list.
        partsBox.addEventListener('change', function (event) {
            if (event.target.classList && event.target.classList.contains('publication-part-album-files')) {
                acceptPickedFiles(event.target);
            }
        });
        if (imageFilesInput) {
            imageFilesInput.addEventListener('change', function () {
                acceptPickedFiles(imageFilesInput);
            });
        }
        partsBox.addEventListener('focusout', hideSelectionActions);
        if (numberPartsInput) {
            numberPartsInput.addEventListener('change', function () {
                splitIfNeeded();
                applyPartNumbers();
                updateCounters();
                update();
            });
        }
        if (distributeImagesInput) {
            distributeImagesInput.addEventListener('change', distributeImages);
        }
        // The preview answers to the buttons of the first part as it does to its
        // text: a row of the shared field is read on every keystroke, and the parts
        // that were handed these buttons follow it there.
        sharedButtonBox.addEventListener('input', function (event) {
            if (event.target.closest('.publication-button-row')) {
                syncPartButtons();
                update();
            }
        });
        // An icon of a box of the parts adds a row to that box or takes the row its
        // own icon stands in out of the form, the cloned boxes included.
        function takeButtonClick(box, event) {
            if (event.target.closest('.publication-button-add')) {
                addButtonRow(box);
            } else if (event.target.closest('.publication-button-remove')) {
                removeButtonRow(event.target.closest('.publication-button-remove'));
            } else {
                return;
            }
            if (box === sharedButtonBox) {
                syncPartButtons();
            }
            updateButtonBoxes();
            update();
        }

        sharedButtonBox.addEventListener('click', function (event) {
            takeButtonClick(sharedButtonBox, event);
        });
        partsBox.addEventListener('click', function (event) {
            var box = event.target.closest('.publication-part-button');
            if (box) {
                takeButtonClick(box, event);
            }
        });
        // The switch hands the buttons of the shared field out to the parts, and
        // takes them back from the parts when it goes off.
        if (buttonEveryPartInput) {
            buttonEveryPartInput.addEventListener('change', function () {
                syncPartButtons();
                updateButtonBoxes();
                update();
            });
        }
        // The boxes of the parts, the cloned ones included.
        partsBox.addEventListener('input', function (event) {
            if (event.target.closest('.publication-part-button')) {
                update();
            }
        });
        // The window of a link: Enter means the same as «Применить», and closing
        // the window by any other way leaves the selection untouched.
        if (linkApplyButton) {
            linkApplyButton.addEventListener('click', function () {
                var address = linkAddressOf(linkAddressInput.value);

                if (address === null) {
                    showFlash('error', 'Ссылка принимает адрес http:// или https://.');
                    return;
                }
                writeLinkOfSelection(address);
            });
        }
        if (linkRemoveButton) {
            linkRemoveButton.addEventListener('click', function () {
                writeLinkOfSelection(false);
            });
        }
        if (linkAddressInput) {
            linkAddressInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    linkApplyButton.click();
                }
            });
        }
        if (linkModal) {
            linkModal.addEventListener('shown.bs.modal', function () {
                if (linkAddressInput) {
                    linkAddressInput.focus();
                    linkAddressInput.select();
                }
            });
            linkModal.addEventListener('hidden.bs.modal', function () {
                linkTarget = null;
            });
        }
        if (splitButton) {
            splitButton.addEventListener('click', splitPartAtCaret);
        }
        if (splitModesBox) {
            splitModesBox.addEventListener('click', function (event) {
                var button = event.target.closest('[data-split-whole]');
                if (!button) {
                    return;
                }
                splitWholeText(button.getAttribute('data-split-whole'));
            });
        }
        // One listener for the rows of every part, including the cloned ones.
        partsBox.addEventListener('click', function (event) {
            var row = event.target.closest('.publication-merge-row');

            if (row) {
                mergeParts(mergeRows().indexOf(row));
            }
        });
        // The album buttons of every part, the cloned ones included.
        partsBox.addEventListener('click', function (event) {
            var button = event.target.closest('[data-move-album]');

            if (!button) {
                return;
            }

            var album = button.closest('.publication-part-album');
            moveImageGroup(
                imageTargets().indexOf(album.querySelector('.publication-part-album-field')),
                parseInt(button.getAttribute('data-move-album'), 10)
            );
        });
        if (selectionPopup) {
            // The popup is placed against the viewport, which a transformed
            // ancestor of the form would quietly replace with itself.
            document.body.appendChild(selectionPopup);
            // The buttons must keep the focus in the field the text is selected in.
            selectionPopup.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            movePrevPartButton.addEventListener('click', function () {
                moveSelectedText(false);
            });
            moveNextPartButton.addEventListener('click', function () {
                moveSelectedText(true);
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hideSelectionActions();
            }
        });
        // Viewport coordinates: the popup does not follow what moves under it.
        window.addEventListener('resize', hideSelectionActions);
        window.addEventListener('scroll', hideSelectionActions, true);

        if (emojiPanel) {
            buildEmojiCategories();
            // Placed against the viewport, for the same reason as the popup above.
            document.body.appendChild(emojiPanel);
            // The cells and the categories are clicked without taking the caret of
            // the search field away, as the buttons of the selection popup do not
            // take the caret of the text away. The field itself is the exception:
            // that is where a word is typed.
            emojiPanel.addEventListener('mousedown', function (event) {
                if (event.target !== emojiQueryInput) {
                    event.preventDefault();
                }
            });
            emojiPanel.addEventListener('keydown', function (event) {
                emojiKeys(event);
            });
            emojiCategoriesBox.addEventListener('click', function (event) {
                var button = event.target.closest('[data-emoji-category]');

                if (!button) {
                    return;
                }

                // A category is asked instead of a word, not after it.
                if (emojiQueryInput) {
                    emojiQueryInput.value = '';
                }
                emojiCategory = button.dataset.emojiCategory;
                renderEmojiPanel();
            });
            emojiGridBox.addEventListener('click', function (event) {
                var cell = event.target.closest('.publication-emoji-cell');
                var index = cell ? Array.prototype.indexOf.call(emojiGridBox.children, cell) : -1;

                if (index >= 0) {
                    insertEmoji(emojiShown[index]);
                }
            });
        }
        if (emojiQueryInput) {
            emojiQueryInput.addEventListener('input', function () {
                renderEmojiPanel();
            });
        }
        // The panel belongs to one caret and one word: a click anywhere that is
        // neither the panel nor the button that opened it leaves it behind. The
        // button answers its own mousedown first, so it is not its own dismissal.
        document.addEventListener('mousedown', function (event) {
            if (!emojiTarget) {
                return;
            }
            if (event.target.closest
                && (event.target.closest('.publication-emoji-panel') || event.target.closest('.ql-emoji'))) {
                return;
            }

            closeEmojiPanel();
        });
        window.addEventListener('resize', closeEmojiPanel);
        window.addEventListener('scroll', function (event) {
            // The grid scrolling its own list does not move the text under it.
            if (event.target && emojiPanel && emojiPanel.contains(event.target)) {
                return;
            }

            closeEmojiPanel();
        }, true);

        if (imagesInput) {
            imagesInput.addEventListener('input', updateImages);
        }
        if (publicationAtInput) {
            publicationAtInput.addEventListener('input', updatePreviewPublicationAt);
            publicationAtInput.addEventListener('change', updatePreviewPublicationAt);
            publicationAtJq
                .off('.previewPub')
                .on('apply.daterangepicker.previewPub hide.daterangepicker.previewPub cancel.daterangepicker.previewPub', function () {
                    updatePreviewPublicationAt();
                });
        }
        // The part the form starts with is not written by anyone, so its editor
        // is mounted here rather than by setTextParts.
        mountPartEditors();
        update();
        updateCounters();
        updateImages();
        updatePreviewPublicationAt();

        var scrollToMiddle = function (log) {
            var scroller = log.closest('.scroll350');
            if (!scroller) {
                return;
            }
            var viewport = scroller.querySelector('.os-viewport') || scroller;
            var logRect = log.getBoundingClientRect();
            var viewRect = viewport.getBoundingClientRect();
            var delta = logRect.top + logRect.height / 2 - (viewRect.top + viewRect.height / 2);
            viewport.scrollTop += delta;
            if (scroller.scrollTop !== undefined && scroller !== viewport) {
                scroller.scrollTop += delta;
            }
        };

        var editingLog = null;

        // Populate hidden fields (timezone) before form submission
        // Filters are saved to session via AJAX, no need to pass via POST
        function populateHiddenFields() {
            var tz = getPortalTimezone();
            if (tz) {
                var tzField1 = document.getElementById('publicationTz');
                if (tzField1) tzField1.value = tz;
                var tzField2 = document.getElementById('schedulePublicationTz');
                if (tzField2) tzField2.value = tz;
            }
        }

        // Attach to forms
        var newPostForm = document.querySelector('form[action*="publication-create"]');
        if (newPostForm) {
            newPostForm.addEventListener('submit', populateHiddenFields);
        }
        var scheduleFormEl = document.getElementById('scheduleForm');
        if (scheduleFormEl) {
            scheduleFormEl.addEventListener('submit', populateHiddenFields);
        }

        var setEditing = function (log) {
            if (editingLog === log) {
                return;
            }
            if (editingLog && editingLog.isConnected) {
                editingLog.querySelector('.editing-badge').classList.add('d-none');
            }
            editingLog = log;
            log.querySelector('.editing-badge').classList.remove('d-none');
            loadText(
                log.getAttribute('data-text') || '',
                parseFormatting(log.getAttribute('data-formatting')),
                log.getAttribute('data-title') || ''
            );
            fillImages(log.getAttribute('data-image-urls') || '');
            fillButtons(log.getAttribute('data-button'));
            scrollToMiddle(log);

            if (sourceTypeInput && sourceIdInput) {
                sourceTypeInput.value = log.getAttribute('data-source-type') || 'new';
                sourceIdInput.value = log.getAttribute('data-source-id') || '';
            }
            if (publishedAtInput) {
                var newVal = utcToPickerValue(log.getAttribute('data-published-at-utc'))
                    || publishedAtInput.value;
                publishedAtInput.value = newVal;
                try {
                    var picker = publicationAtJq.data('daterangepicker');
                    if (picker && newVal) {
                        var m = moment(newVal, pickerFormat);
                        if (m.isValid()) {
                            picker.setStartDate(m);
                            picker.setEndDate(m.clone().add(__HORIZON_HOURS, 'hour'));
                        }
                    }
                } catch (e) {}
                updatePreviewPublicationAt();
            }
            if (forumTypeInput && forumIdInput) {
                forumTypeInput.value = '';
                forumIdInput.value = '';
            }
        };

        var resetForm = document.querySelector('form[action*="publication-create"]');
        if (resetForm) {
            resetForm.addEventListener('submit', function () {
                // A part the user made too long by hand is broken again right
                // before the fields are read for the request.
                splitIfNeeded();
                applyPartNumbers();
                if (sourceTypeInput && sourceIdInput && sourceTypeInput.value === 'new') {
                    sourceIdInput.value = '';
                }
                if (forumTypeInput && forumIdInput && forumTypeInput.value === '') {
                    forumIdInput.value = '';
                }
            });
        }

        var hideScheduleModal = function () {
            if (!scheduleModal || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                return;
            }
            try {
                bootstrap.Modal.getOrCreateInstance(scheduleModal).hide();
            } catch (e) {}
        };

        // Back to the blank "new record" state the page reload used to leave behind,
        // otherwise the next submit would overwrite the record just saved.
        var clearEditingState = function () {
            if (editingLog && editingLog.isConnected) {
                editingLog.querySelector('.editing-badge').classList.add('d-none');
            }
            editingLog = null;
            if (buttonEveryPartInput) {
                buttonEveryPartInput.checked = false;
            }
            // The buttons of the record come out of the form the same way the text
            // does: the shared field first, the boxes of the parts behind it.
            fillButtons('');
            setTextParts([''], [''], [], undefined, ['']);
            if (numberPartsInput) {
                numberPartsInput.checked = false;
            }
            if (distributeImagesInput) {
                distributeImagesInput.checked = false;
            }
            // The record just saved has its addresses in the rows already; the next
            // one starts from a blank text, so the waiting for them starts over. The
            // switch itself is not part of a record: its state belongs to the session,
            // and taking it off here would leave the page disagreeing with it.
            armLinksToButtons();
            writeImages(0, '');
            if (sourceTypeInput) sourceTypeInput.value = 'new';
            if (sourceIdInput) sourceIdInput.value = '';
            if (forumTypeInput) forumTypeInput.value = '';
            if (forumIdInput) forumIdInput.value = '';
            if (publishedAtInput) {
                var next = computeNextPublicationSlot().format(pickerFormat);
                publishedAtInput.value = next;
                try {
                    var picker = publicationAtJq.data('daterangepicker');
                    if (picker) {
                        var m = moment(next, pickerFormat);
                        picker.setStartDate(m);
                        picker.setEndDate(m.clone().add(__HORIZON_HOURS, 'hour'));
                    }
                } catch (e) {}
                updatePreviewPublicationAt();
            }
        };

        var ajaxInFlight = false;

        // Publications forms post over AJAX and the lists are swapped in place.
        // Without JavaScript the controller keeps answering with a redirect.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.tagName !== 'FORM' || !form.hasAttribute('data-ajax')) {
                return;
            }
            if (ajaxInFlight) {
                event.preventDefault();
                return;
            }
            if (typeof FormData !== 'function') {
                return;
            }
            event.preventDefault();
            ajaxInFlight = true;
            postForBlocks(form.getAttribute('action'), new FormData(form, event.submitter))
                .then(function (payload) {
                    if (!payload || !payload.ok) {
                        return;
                    }
                    if (form.hasAttribute('data-clear-editing')) {
                        clearEditingState();
                    }
                    if (form.id === 'scheduleForm') {
                        hideScheduleModal();
                    }
                })
                .then(function () {
                    ajaxInFlight = false;
                });
        });

        // The lists are swapped in place after every action, so all of their
        // controls are wired through delegated listeners on the document.
        document.addEventListener('dblclick', function (event) {
            var log = event.target.closest ? event.target.closest('.activity-log') : null;
            if (log) {
                setEditing(log);
            }
        });

        document.addEventListener('click', function (event) {
            var editLink = event.target.closest ? event.target.closest('a[title="Редактировать"]') : null;
            if (editLink) {
                var log = editLink.closest('.activity-log');
                if (log) {
                    event.preventDefault();
                    setEditing(log);
                }
                return;
            }
            var forumBtn = event.target.closest ? event.target.closest('.forum-publish-btn') : null;
            if (forumBtn) {
                fillFormFromForum(forumBtn);
                return;
            }
            var threadBtn = event.target.closest ? event.target.closest('.forum-thread-btn') : null;
            if (threadBtn) {
                fillFormFromThread(threadBtn);
            }
        });

        var fillFormFromForum = function (btn) {
            var text = btn.getAttribute('data-text') || '';
            var title = btn.getAttribute('data-title') || '';
            if (title !== '') {
                text = title + "\n\n" + text;
            }
            var heading = firstLineAsHeading(text);
            loadText(heading.text, undefined, heading.title);
            fillImages(btn.getAttribute('data-image-urls') || '');
            if (sourceTypeInput && sourceIdInput) {
                sourceTypeInput.value = 'new';
                sourceIdInput.value = '';
            }
            if (forumTypeInput && forumIdInput) {
                forumTypeInput.value = btn.getAttribute('data-forum-type') || '';
                forumIdInput.value = btn.getAttribute('data-forum-id') || '';
            }
            if (editingLog && editingLog.isConnected) {
                editingLog.querySelector('.editing-badge').classList.add('d-none');
            }
            editingLog = null;
            source.focus();
        };

        // --- loading a whole thread into the form ------------------------------

        // The two buttons of a topic differ only in what they do with the texts
        // once these have arrived, so the request itself is the same.
        var threadInFlight = false;

        // The topic of the row is already on its publish button, so the server
        // answers the posts alone. Every entity of the thread keeps its album:
        // a post that only holds images has nothing to say, but its links
        // still belong to the publication.
        var threadEntities = function (topicBtn, posts) {
            var entities = [];

            var add = function (title, text, urls) {
                entities.push({
                    text: (title === '' ? text : title + "\n\n" + text).trim(),
                    urls: urls,
                });
            };

            add(
                topicBtn.getAttribute('data-title') || '',
                topicBtn.getAttribute('data-text') || '',
                parseImageUrls(topicBtn.getAttribute('data-image-urls') || '')
            );
            (posts || []).forEach(function (post) {
                add('', post.text || '', post.images || []);
            });

            return entities;
        };

        // An entity with no text is dropped here: the form would carry a part
        // the service refuses to save.
        var threadTexts = function (entities) {
            return entities.filter(function (entity) {
                return entity.text !== '';
            }).map(function (entity) {
                return entity.text;
            });
        };

        var threadImages = function (entities) {
            return unionGroups(entities.map(function (entity) {
                return entity.urls;
            })).join('\n');
        };

        var threadTextParts = function (entities) {
            var parts = [];
            threadTexts(entities).forEach(function (text) {
                // One entity may itself hold more text than a message does.
                splitIntoParts(text).forEach(function (one) {
                    parts.push(one);
                });
            });

            return parts.length > 0 ? parts : [''];
        };

        // The album of every part of the thread. The links of an entity go to the
        // part its text starts in — the pieces an entity is cut into share one
        // album — and an entity that has nothing to say leaves its pictures to
        // the next part of the form, since a text that is not there has no part.
        var threadImageGroups = function (entities) {
            var groups = [];
            var pending = [];

            entities.forEach(function (entity) {
                pending = unionGroups([pending, entity.urls]);
                if (entity.text === '') {
                    return;
                }

                var pieces = splitIntoParts(entity.text);

                // The whole album of an entity stays with the first of its parts.
                groups.push(pending);
                pending = [];

                pieces.slice(1).forEach(function () {
                    groups.push([]);
                });
            });

            if (groups.length === 0) {
                return [pending];
            }

            // Links that came after the last word of the thread have no part of
            // their own: the last of them takes them.
            groups[groups.length - 1] = unionGroups([groups[groups.length - 1], pending]);

            return groups;
        };

        var fillFormFromThread = function (btn) {
            if (threadInFlight) {
                return;
            }
            var row = btn.closest('.thread');
            var topicBtn = row ? row.querySelector('.forum-publish-btn') : null;
            if (!topicBtn) {
                return;
            }

            var splits = btn.getAttribute('data-thread') === 'parts';
            var topicId = btn.getAttribute('data-topic') || '';
            threadInFlight = true;

            postForJson(__THREAD_URL, { topic: topicId }).then(function (payload) {
                var entities = threadEntities(topicBtn, payload.posts);

                if (splits) {
                    // One entity, one part: the album of every one of them lands
                    // in the field of its own part.
                    // A thread is text alone, so it brings no heading — and leaves
                    // none of the parts it fills with the heading of a record. The
                    // first line of its first part goes to the heading only while
                    // the switch of the form header asks for that.
                    var parts = threadTextParts(entities);
                    var heading = firstLineAsHeading(parts[0]);
                    setTextParts([heading.text].concat(parts.slice(1)),
                        undefined, undefined, undefined, [heading.title]);
                    asTexts(threadImageGroups(entities)).forEach(function (raw, index) {
                        writeImages(index, raw);
                    });
                } else {
                    // The thread is one text here, so its pictures are one album
                    // of the first part.
                    var whole = firstLineAsHeading(threadTexts(entities).join("\n\n"));
                    loadText(whole.text, undefined, whole.title);
                    fillImages(threadImages(entities));
                }
                if (sourceTypeInput && sourceIdInput) {
                    sourceTypeInput.value = 'new';
                    sourceIdInput.value = '';
                }
                if (forumTypeInput && forumIdInput) {
                    forumTypeInput.value = 'topic';
                    forumIdInput.value = topicId;
                }
                if (editingLog && editingLog.isConnected) {
                    editingLog.querySelector('.editing-badge').classList.add('d-none');
                }
                editingLog = null;
                source.focus();
            }).catch(function (error) {
                showFlash('error', 'Не удалось загрузить тред: '
                    + (error && error.message ? error.message : error));
            }).then(function () {
                threadInFlight = false;
            });
        };

        if (scheduleModal) {
            scheduleModal.addEventListener('show.bs.modal', function (event) {
                var trigger = event.relatedTarget;
                var recordId = trigger ? trigger.getAttribute('data-draft-id') || '' : '';
                var sourceVal = trigger ? trigger.getAttribute('data-source') || 'draft' : 'draft';
                var recordIdInput = document.getElementById('scheduleDraftId');
                var sourceInput = document.getElementById('scheduleSource');
                if (recordIdInput) {
                    recordIdInput.value = recordId;
                }
                if (sourceInput) {
                    sourceInput.value = sourceVal;
                }
                var scheduleInput = document.getElementById('scheduleAt');
                if (scheduleInput) {
                    var scheduleVal = scheduleInput.value;
                    var startM = scheduleVal ? moment(scheduleVal, pickerFormat) : roundUpToMinuteStep(moment());
                    if (!startM.isValid()) startM = roundUpToMinuteStep(moment());
                    try {
                        var schPicker = scheduleAtJq.data('daterangepicker');
                        if (schPicker) {
                            schPicker.setStartDate(startM);
                            schPicker.setEndDate(startM.clone().add(__HORIZON_HOURS, 'hour'));
                            scheduleInput.value = startM.format(pickerFormat);
                        }
                    } catch (e) {}
                }
            });
        }

        // Both count sliders of the card behave the same: the label follows the
        // thumb while dragging, ∞ says the filter is off, and the lists refresh
        // when the reader releases the slider.
        var countSliders = [];
        [['forumFilterImagesCount', 'forumFilterImagesCountValue'],
            ['forumFilterLinksCount', 'forumFilterLinksCountValue']].forEach(function (pair) {
                var slider = document.getElementById(pair[0]);
                var label = document.getElementById(pair[1]);
                if (!slider || !label) {
                    return;
                }
                var showCount = function (value) {
                    label.textContent = value == 0 ? '∞' : value;
                };
                showCount(slider.value);
                // Apply filters on change (when user releases the slider)
                slider.addEventListener('change', function () {
                    showCount(this.value);
                    applyFiltersDirect();
                });
                // The label follows the thumb while dragging; the lists refresh on release
                slider.addEventListener('input', function () {
                    showCount(this.value);
                });
                countSliders.push({ slider: slider, label: label });
            });

        var clearFiltersBtn = document.getElementById('forumFilterClearBtn');
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', function (event) {
                event.preventDefault();
                var imagesSwitch = document.getElementById('forumFilterWithImages');
                var postsSwitch = document.getElementById('forumFilterWithPosts');
                var linksSwitch = document.getElementById('forumFilterWithLinks');
                if (imagesSwitch) imagesSwitch.checked = false;
                if (postsSwitch) postsSwitch.checked = false;
                if (linksSwitch) linksSwitch.checked = false;
                countSliders.forEach(function (bound) {
                    bound.slider.value = 0;
                    bound.label.textContent = '∞';
                });
                updateFilterCount(0);
                postForBlocks(clearFiltersBtn.getAttribute('href'), {});
            });
        }

        // The first page is already in the markup; the totals say whether a
        // second one is worth reading.
        __PAGED_BLOCKS.forEach(function (name) {
            resetPaging(name, __BLOCK_TOTALS);
        });

        // Switching the order is the server's call: it has to decide which end
        // of the list both the redrawn block and its later pages are read
        // from.
        watchSortSwitches();
        watchTextOrderButtons();

        // The heading switch of the form is remembered the same way, although
        // nothing of the page has to be redrawn for it.
        watchHeadingSwitch();

        // So is the switch of the addresses, and it is handed the waiting of the
        // fill it owns: the two states of one turn have to agree with each other.
        watchLinksSwitch(armLinksToButtons);

        // Scroll does not bubble, so one capture listener on the document sees
        // the viewport of every block, whenever OverlayScrollbars rebuilt it.
        document.addEventListener('scroll', function (event) {
            var scroller = event.target;
            var replies = typeof scroller.closest === 'function' ? scroller.closest('.thread-replies') : null;

            // A discussion box sits inside the list of topics, so its scroll has
            // to be answered first: otherwise it would read as the bottom of the
            // list and ask for another topic.
            if (replies) {
                if (nearBottom(replies)) {
                    loadNextReplies(replies);
                }

                return;
            }
            var name = pagedBlockOf(scroller);

            if (name === '' || !nearBottom(scroller)) {
                return;
            }
            loadNextPage(name);
        }, true);

        renderUtcTimes(document);
    }
    setTimeout(initAll, 0);
    setTimeout(initAll, 100);
    jQuery(window).on('load', function () { setTimeout(initAll, 0); });
});
JS
);
?>
