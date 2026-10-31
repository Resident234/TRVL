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
/** @var bool $albumRows whether the album of the form shows its pictures as rows */
/** @var bool $partsPublication whether every part gets its own time and its own button */
/** @var bool $distributeImages whether the albums of the form are handed out over its parts on their own */
/** @var bool $buttonsPerPart whether every part wears a block of link buttons of its own */
/** @var bool $numberParts whether the number of a part is written at all */
/** @var string $headingNumberTemplate the shape a part heading wears its number in: two tokens the reader may put words around */
/** @var bool $headingNumberCustom whether that shape is the reader's own string rather than the preset of the two rows */
/** @var string[] $headingFormatting the marks the block of the heading stands with: 'bold', 'italic', 'strike', 'underline' */
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
// The shape of the number is one string, and the two rows of radios under the switch of
// the numbering are drawn back out of it: a session that holds ««Текст» - Часть 1» shows
// the row of the tail standing and the row of the dash standing, and the field of the
// reader holds the same words the two rows would have written.
$headingNumberPlace = PublicationSettingsService::headingNumberPlaceOf($headingNumberTemplate);
$headingNumberStyle = PublicationSettingsService::headingNumberStyleOf($headingNumberTemplate);

$this->registerCss(
    <<<CSS
/* The album of a part goes to the channel above its caption, and the channel
   lays it out by how many pictures it holds: one across the message, two side by
   side, and three, four, five or six with the first lying across the whole message
   on top of the rest — the four are laid out by the rules for `album-3`,
   `album-4`, `album-5` and `album-6` below. An album longer than six has no rule of
   its own and stands on this base grid: two square tiles to a row, as many rows as
   the album asks for, which is what the channel has been read at for two pictures.
   The preview
   shows the whole album, and only the ten pictures of one media group bound it —
   what does not fit goes behind the «+N» of the last tile, because the sender
   carries those pictures into an album of its own.
   The grid is pulled out of the padding of the bubble, because a photo in the
   channel touches its edges, and only the corners of the whole group are
   rounded — the seams between the tiles stay straight.
   The columns are bounded by zero rather than by their content: a tile that keeps
   no ratio of its own drives the width of the column it sits in instead, which is
   what the left tile of a three-picture album used to do (columns of 614px and
   306px inside a box of 614px). Every tile sizes itself today, so the floor holds
   nothing up — and stays for the tile that one day stops doing that. */
.publication-preview-album {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 2px;
    margin: -1rem -1rem 0.5rem;
    overflow: hidden;
    border-radius: var(--bs-border-radius-lg) var(--bs-border-radius-lg) 0 0;
}

.publication-preview-album.album-1 {
    grid-template-columns: 1fr;
}

/* An album with nothing under it is the whole message, so it keeps the rounding
   of the bubble on all four of its corners. */
.publication-preview-album.album-only {
    margin-bottom: -1rem;
    border-radius: var(--bs-border-radius-lg);
}

.publication-album-cell {
    position: relative;
    aspect-ratio: 1;
}

/* The first tile of three lies across both columns, and the channel crops it
   instead of keeping its proportions, so it takes a ratio of its own rather than
   the square the other tiles keep. Two to one is what makes the whole group a
   square: the two tiles under it are squares half the message wide. */
.publication-preview-album.album-3 .publication-album-cell:first-child {
    grid-column: span 2;
    aspect-ratio: 2 / 1;
}

.publication-album-cell img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Four pictures the channel lays out as one across the top and three under it,
   not as a square of two by two. Six columns carry both rows: the top tile spans
   all six, each of the three below takes two of them. The ratios are the ones the
   channel itself measures at — its bubble is 430px of content wide, and there the
   top tile stands 430 × 281 and each of the three under it 141 × 106 — so the
   group keeps the shape of the post it copies rather than the square the old grid
   made of it. */
.publication-preview-album.album-4 {
    grid-template-columns: repeat(6, minmax(0, 1fr));
}

.publication-preview-album.album-4 .publication-album-cell:first-child {
    grid-column: span 6;
    aspect-ratio: 3 / 2;
}

.publication-preview-album.album-4 .publication-album-cell:not(:first-child) {
    grid-column: span 2;
    aspect-ratio: 4 / 3;
}

/* Five pictures the channel lays out as one across the top over a square of two
   by two. The grid is already two columns wide, so only the first cell moves and
   the ratios change: the numbers are the ones the channel itself measures at — its
   bubble is 430px of content wide, and there the top tile stands 430 × 285 while
   each of the four under it holds 213 × 141 — both three to two, so the whole
   group keeps the 430 × 575 shape of the post it copies rather than the three rows
   of squares the base grid made of it. */
.publication-preview-album.album-5 .publication-album-cell {
    aspect-ratio: 3 / 2;
}

.publication-preview-album.album-5 .publication-album-cell:first-child {
    grid-column: span 2;
}

/* Six pictures the channel lays out in three rows: one across the whole message,
   then a square beside a wide tile, then a square beside two wide ones. The rows
   share no height — a row stands as tall as its own tiles ask — so no small number
   of columns carries them, and the grid takes forty: the top tile spans all forty,
   the row of two splits 16 and 24, the row of three 10, 15 and 15. Those are the
   seams the shot measures at — at 430px of content the top tile stands 430 × 288,
   the row under it holds 171 and 255 of tile at 171 of height, the last row 106,
   158 and 158 at 106, the whole group 430 × 573. Which two of the six the channel
   cut to squares is the shape of the album it was given: a picture it cannot fill
   wide without cutting keeps the square, so the two squares here are this post's
   own portrait pictures, not a rule every six will wear. */
.publication-preview-album.album-6 {
    grid-template-columns: repeat(40, minmax(0, 1fr));
}

.publication-preview-album.album-6 .publication-album-cell {
    grid-column: span 15;
    aspect-ratio: 3 / 2;
}

.publication-preview-album.album-6 .publication-album-cell:nth-child(1) {
    grid-column: span 40;
}

.publication-preview-album.album-6 .publication-album-cell:nth-child(2) {
    grid-column: span 16;
    aspect-ratio: 1;
}

.publication-preview-album.album-6 .publication-album-cell:nth-child(3) {
    grid-column: span 24;
}

.publication-preview-album.album-6 .publication-album-cell:nth-child(4) {
    grid-column: span 10;
    aspect-ratio: 1;
}

/* A single picture keeps its own proportions, the way the channel shows it, and
   is cut back only when it is taller than the bubble should get. */
.publication-preview-album.album-1 .publication-album-cell {
    aspect-ratio: auto;
}

.publication-preview-album.album-1 .publication-album-cell img {
    height: auto;
    max-height: 18rem;
}

/* Whatever the four tiles have no room for is counted on the last of them. */
.publication-album-more {
    position: absolute;
    right: 0.375rem;
    bottom: 0.375rem;
    padding: 0 0.4375rem;
    border-radius: 0.625rem;
    background-color: rgba(0, 0, 0, 0.6);
    color: #fff;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1.5;
}

/* The strip under the fields of an album is the stacked images of the UI-kit, the
   theme drawing it: circles of 48px overlapping by their own margin and growing
   under the cursor. Only two things are the page's own — the ring of the cursor,
   because a picture of the strip opens the slider of the album, and the way a
   photo is cut: the vendored rule sizes a square avatar, so a landscape picture
   without a fit would be squeezed into that square. */
.publication-album-strip img,
.publication-album-strip .plus {
    cursor: pointer;
}

.publication-album-strip img {
    object-fit: cover;
}

/* The frame the slider stands in is as wide as the card of the form, so the
   picture of a slide is held by its height, not by its width: the strip cuts a
   photo into a round mark, while the point of the slider is to see the photo —
   so it keeps its own proportions and the space it did not take stays the
   background the utility class gives it. */
.publication-album-slide {
    max-height: 24rem;
    object-fit: contain;
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

/* The hour the channel will stand in the corner of the message, under its text
   and over its buttons, in the colour the rest of the metadata wears. The card
   keeps it in the flow rather than in an absolute corner: a long last line of a
   caption would otherwise run under it, and shortening one line is not
   something a box laid out over the whole width can be asked to do. */
.telegram-preview-time {
    display: block;
    margin-top: 0.25rem;
    color: var(--bs-secondary-color);
    font-size: 0.8125rem;
    line-height: 1.25;
    text-align: right;
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

/* A group of fields of this form is framed as a card of the board, but it is not
   a card of a board: standing inside `.kanban-items` it would catch the dashed
   orange hover and the move cursor of a draggable item while the form is being
   filled. The three classes of the board rule are repeated here so this one wins
   on specificity, not on the order the sheets were loaded in. The frame is named
   for what it holds — buttons, the shared album, the album of a part — and the
   colour it is drawn with is read off a token of its own instead of being typed
   here: two of the three frames hold the images of the publication, and a frame
   that wears a blue line must not flash the grey of the board back the moment the
   pointer crosses it. The hover rule pins the style as well as the colour, the way
   the shorthand it replaces did: the board gives a card it crosses a dashed accent,
   and a rule that only answers the colour would leave the line of the form dashed
   while the pointer rests on it. */
.kanban-board .kanban-items .kanban-item.publication-field-frame {
    border-color: var(--publication-frame-border);
}

.kanban-board .kanban-items .kanban-item.publication-field-frame:hover {
    border-style: solid;
    border-color: var(--publication-frame-border);
    cursor: auto;
}

/* The line of a frame is one of three colours, and which one is answered by what
   stands inside it: the grey the board gives every card of its column, the blue of the
   theme for the frames a file picker stands in, and the purple of the palette for the frame
   the buttons of the post stand in — which is the difference between the album of a
   publication and the addresses that go out under it.
   `:has()` asks the frame about its own descendants, so the album of every part
   carries the colour with it when the template is cloned and no script has to keep
   a class in step with the markup. */
.publication-field-frame {
    --publication-frame-border: #dfe5ea;
}

.publication-field-frame:has(input[type=file]) {
    --publication-frame-border: rgba(var(--bs-primary-rgb), 1);
}

/* The third key, and the one that speaks about the addresses. This frame holds no image and
   no file: it holds the label and the URL of every button that goes out under the post, and
   from this revision it says so with a line of its own instead of the grey an unframed block
   wears. The colour is the purple the ui-kit ships in its palette and draws nothing with —
   the only colour of that palette this page had not spent, and the one a reader's own browser
   keeps for a link he has followed. It is read off the token rather than typed, because the
   line of a frame and its hover both read that token: a frame that turns purple must not
   flash the grey of the board back the moment a pointer crosses it. The key is the box the
   rows of buttons stand in, which is the same reading the rule above gives a picker, and the
   two keys never fall on one block at once: no frame of this form holds both a picker and the
   rows of a button — the album of a part and the buttons of a part are two frames that stand one
   under another in the same block. */
.publication-field-frame:has(.publication-button-box) {
    --publication-frame-border: var(--bs-purple);
}

/* The block of the addresses does not stop at its line: everything it holds is drawn with the
   same purple. The frame and the handles inside it answer one question — which links go out
   under this post — and a toggle ringed in the theme's blue or a cross filled with its coral
   said a row of that block belonged to something else. The keys read the frame exactly as the
   rule above reads it, so the block of the form and the frame of every part arrive coloured
   with their own markup and the script of the page learns nothing about a colour.
   A button of the portal is recoloured through the variables its own states read, not by typing
   `background` or `color`: the sheet answers hover, press and sleep off those names, and a rule
   that painted only the still state would hand a purple button back the blue of the theme the
   moment a pointer crossed it. The ink of a filled control is the white of the palette and not
   the dark ink that pair carries by default — over a purple ground that is the one colour which
   cannot be read off it. The two arrows are outline, so they wear the purple as their line and
   their ink and fill with it only when crossed, which is also how a row of the album is drawn.
   A switch is answered in its colours alone, as the blue ring above is: the thickness, the
   corner and the knob of a track belong to the vendored sheet, and a rule that retyped either
   would move a control the person clicks. The halo a control takes on focus is left to the
   theme for the same reason it was left for the blue ring — it is the one state that is not a
   colour of the block but the page saying a control is under the keyboard, and the palette ships
   no purple triple to redraw it with. A sleeping arrow is read off the opacity the theme gives a
   disabled button, not off a colour: every state of this block is one colour. */
.publication-field-frame:has(.publication-button-box) .form-check-input {
    border-color: var(--bs-purple);
}

.publication-field-frame:has(.publication-button-box) .form-check-input:checked {
    background-color: var(--bs-purple);
}

.publication-field-frame:has(.publication-button-box) .publication-button-add,
.publication-field-frame:has(.publication-button-box) .publication-button-remove {
    --bs-btn-bg: var(--bs-purple);
    --bs-btn-border-color: var(--bs-purple);
    --bs-btn-color: var(--bs-white);
    --bs-btn-hover-bg: var(--bs-purple);
    --bs-btn-hover-border-color: var(--bs-purple);
    --bs-btn-hover-color: var(--bs-white);
    --bs-btn-active-bg: var(--bs-purple);
    --bs-btn-active-border-color: var(--bs-purple);
    --bs-btn-active-color: var(--bs-white);
    --bs-btn-disabled-bg: var(--bs-purple);
    --bs-btn-disabled-border-color: var(--bs-purple);
    --bs-btn-disabled-color: var(--bs-white);
}

.publication-field-frame:has(.publication-button-box) .publication-button-up,
.publication-field-frame:has(.publication-button-box) .publication-button-down {
    --bs-btn-color: var(--bs-purple);
    --bs-btn-border-color: var(--bs-purple);
    --bs-btn-hover-bg: var(--bs-purple);
    --bs-btn-hover-border-color: var(--bs-purple);
    --bs-btn-hover-color: var(--bs-white);
    --bs-btn-active-bg: var(--bs-purple);
    --bs-btn-active-border-color: var(--bs-purple);
    --bs-btn-active-color: var(--bs-white);
    --bs-btn-disabled-color: var(--bs-purple);
    --bs-btn-disabled-border-color: var(--bs-purple);
}

.publication-field-frame:has(.publication-button-box) .publication-button-url {
    border-color: var(--bs-purple);
}

/* The switch that hands the images of the first part out to the parts, and the switch that
   lays an album out as rows, work on the images themselves, so their toggles are ringed with
   the same blue as the frames they stand in. It is the toggle that is a control and the text
   beside it is not, so the ring sits on the input and nothing is drawn around the row. The
   rule answers only its colour: the corner and the thickness of a switch belong to the
   vendored sheet, and a rule that retyped either would move a control the person clicks.
   Keying the selector on `.form-check-input` alongside the class puts the ring at the weight
   the vendored state rules are written at, and the sheet of this form is read after them —
   the focus line the vendored sheet paints for a switch loses to the ring, and the rule for
   a switch that is on gives it the blue of the theme on its own, so a switch of the images
   reads one blue off and on. The class rides the template node a part is cloned with, which
   is how the album of every part arrives ringed while the script of the page learns nothing
   about it. */
.form-check-input.publication-image-control {
    border-color: rgba(var(--bs-primary-rgb), 1);
}

/* The block one part of the form holds is framed the way a card of the board is,
   and framed all the time: the parts are read one under another, and where one
   ended and the next began was only guessable from the «Часть N» line, while the
   fields of the part — its heading, its text, its album — stood loose on the card.
   The three numbers are the board card's own: the dashed accent its hover rule
   draws, the `p-3` padding it carries, the `rounded-2` corner it rounds. The
   cursor of a draggable card is not taken with them: a part of this form cannot be
   dragged anywhere, and the frame is a reading aid, not a handle.
   Only parts with a sibling part below them are framed. The merge row between
   them stays outside both frames. */
#publicationTextParts:has(> .publication-text-block + .publication-merge-row + .publication-text-block) > .publication-text-block {
    border: 1px dashed #ff7d5c;
    padding: 1rem;
    border-radius: var(--bs-border-radius);
}

/* The row that merges a part with the one under it belongs to neither frame, and its two
   margins are read off each other: the block above leaves `1rem` under itself, the row
   climbs a quarter of that room, and the rest of it is what the row keeps under itself —
   so the handle stands as far from the line above as from the line below instead of
   hugging the upper frame. */
.publication-merge-row {
    margin: -.25rem 0 .75rem;
}

/* The album of the first part is the shared block of the form, and a cut carries that block into
   the card of the part it belongs to. At home the block needs no room above itself: the block
   before it leaves `1rem` under itself, and two margins standing one after another are read off
   each other. Inside a card the neighbour above is the text of the part, and a text leaves
   nothing, so on 2026-10-23 the moved block stood glued to the last line of the editor while the
   album of every other part keeps its `mt-2` and stands 8px off. The number given here is that
   same number, and the bottom one is taken away for the mirror reason: `mb-3` says where the next
   block of the form stands, and inside a card there is no next block, only the padding of it.
   Measured in a browser the same day, over the served sheet: the top seam came to 8px against the
   0px of the uncut page, and the bottom of a row's album stands 1px off the edge of its card, so
   the two numbers the seams of the first part had to answer are these.
   The `!important` is not a mannerism either, and it is only the bottom that needs it: the theme
   ships `main.min.css` with `.mb-3{margin-bottom:1rem!important}`, so the plain `margin-bottom: 0`
   this sheet writes is matched, read and overruled — the block keeps its 16px and the card gains a
   gap none of the other parts have. Nothing of the block's own classes says a word about the margin
   above it, which is why the top number stays plain. The hiding rules of `.publication-part-moves`
   and of the frames answered the same utility on the same terms.
   Keyed on the mark the move already keeps, so the reading at home is untouched and the script of
   the page learns nothing new. */
#publicationAlbumBlock[data-in-part="1"] {
    margin-top: 0.5rem;
    margin-bottom: 0 !important;
}

/* The two arrows of a part trade it with a neighbour, so a text that was never cut has
   nothing to trade and the box holding them is not drawn while the parts box keeps a
   single block. The three handles a part is saved, deleted and cleared by answer the
   same question in the same rule: «часть» of one uncut text is no part of anything, and
   a reader who has not cut the text has no part to move out of the form. The question is
   the one the frames of the parts and the green line of the group already ask — `:has()`
   over two blocks with a merge row between them — so a split, a merge, a rebuild and the
   cloning of a part move the arrows and the three handles with the number of blocks left
   behind, and the script of the page keeps no class of its own to refresh.
   Only the drawing is answered here: which of the two arrows stands out of reach is what
   the rebuild says by disabling it, and that rule is untouched.
   The `!important` is not a mannerism: the theme ships its display utilities important —
   `main.min.css` carries `.d-flex{display:flex!important}` — and both rows are drawn by that
   very class. Importance outranks specificity, so a plain `display: none` loses to it even
   from a selector that opens on an id: the rule matches the row, the browser agrees it
   matches, and the row keeps standing. The hiding has to answer the utility on its own
   terms, which is the one thing a served sheet cannot show and a live page shows at once. */
#publicationTextParts:not(:has(> .publication-text-block + .publication-merge-row + .publication-text-block)) :is(.publication-part-moves, .publication-part-actions) {
    display: none !important;
}

/* The three handles are a separate operation on a single part, and the person asked on
   2026-10-21 that they wait for the switch of the parts the way the queue row of a part
   already does: «Кнопки у каждой части "Сохранить", "Удалитть" "Очистить" должны быть
   при активированном чебоксе, сейчас оон влияет только на кнопку публикации, теперь он
   будет управлять в принципе всеми отдельными операциями на отдельной части». That same
   day the switch took a word of that request as its name — «Работать с частями по
   отдельности», drawn by the glyph of a seam — because by then it had more to say than the
   sending of a part. The names the page and the session know it by stayed behind
   (`publicationPartsIndividually`, `partsPublication`): they carry a saved preference and
   an address. The arrows of the swap are not such an operation, and the person said so on
   the same day — менять части местами нужно всегда, когда эти части есть, стоит тумблер или
   нет, — so this second gate names the handles alone, and a handle shows only while both
   gates are passed. Both rules only hide, so the row stands while neither of them fires.
   The class is written by `updatePartSchedules()`, which is the drawing the switch calls
   and every rebuild of the parts walks, and the server never renders it: a page whose
   script has not run holds the handles away, which is the state a switch thrown off leaves
   them in anyway. The declaration is important for the reason the rule above gives: the row
   of handles is drawn by `d-flex`, and a utility of the theme that stands important is not
   moved by a plain one. */
#publicationTextParts:not(.publication-parts-individual) .publication-part-actions {
    display: none !important;
}

/* The controls of the split — the row that splits, the numbering, the distribution of
   images — stand in a green box of their own, and only one green line is drawn at a
   time. While the text is one part the line goes round the controls alone: the title and
   the text of a publication are no controls of the split, and a reader who never cut the
   text should not see them wrapped in a box that speaks of cutting. Once the text is cut,
   the line moves out to the whole group, so the parts and the controls that made them
   read as one work — and the controls inside it stop drawing a line, because a box in a
   box says nothing a box has not said already.
   Both lines are drawn the way the ui-kit draws a column that has reached its end: `card
   p-3 border border-success done` of tasks.html. That card is one line of the theme's
   green at the theme's own width and style, a room of `1rem` and corners of `.8rem`. The
   line is not retyped here:
   `--bs-border-width`, `--bs-border-style` and `--bs-success-rgb` are the very tokens
   that card reads, so a box and a finished column cannot drift apart, and the only number
   written down is the radius, which a card keeps in its own card-scoped
   `--bs-card-border-radius`, unreadable from anywhere else. Neither takes a background:
   both stand inside the card of the form, and a second underlay nobody asked for.
    Which of the two draws is read off the parts themselves — `:has()` over the sibling
    parts and merge rows that separate them — so the script of the page
   keeps no class to refresh when the parts are rebuilt, and a split, a merge or a move
   restates the line with the number of blocks it leaves behind. The bottom margin is the
   one number the donor cannot lend: that margin only says where a card stands in a column
   of the board, while the room under this box is a question of the form it stands in, and
   the form keeps `mb-3` — 1rem — between any two of its blocks. It is written with the
   border and taken back with it, so the box that draws keeps the room and the box that
   draws nothing keeps nothing apart. */
.publication-parts-controls {
    border: var(--bs-border-width) var(--bs-border-style) rgba(var(--bs-success-rgb), 1);
    padding: 1rem;
    border-radius: .8rem;
    margin-bottom: 1rem;
}

.publication-parts-group:has(#publicationTextParts > .publication-text-block + .publication-merge-row + .publication-text-block) {
    border: var(--bs-border-width) var(--bs-border-style) rgba(var(--bs-success-rgb), 1);
    padding: 1rem;
    border-radius: .8rem;
    margin-bottom: 1rem;
}

.publication-parts-group:has(#publicationTextParts > .publication-text-block + .publication-merge-row + .publication-text-block) .publication-parts-controls {
    border: 0;
    padding: 0;
    margin-bottom: 0;
}

/* The third green box, and the one that never stops drawing. The options of the number
   stand inside the box of the controls of the split, and they are a functional the
   reader counts apart from the cut itself: where the number of a part stands, what joins
   it to the words, and the string that writes both. So the box says «these five rows are
   one question», which the line above it does not say about them.
   It is drawn with the very tokens the two boxes above are drawn with, for the same
   reason the ui-kit card is quoted there: a green line retyped is a green line that can
   drift. Only the point of stopping is left out — the controls of the split give up their
   line where the group of parts already carries one, because a box in a box says nothing
   a box has not said already. This box has no box of its own to be said by, so nothing
   clears it, and the room under it is the room the form keeps between any two of its
   blocks. */
.publication-numbering-controls {
    border: var(--bs-border-width) var(--bs-border-style) rgba(var(--bs-success-rgb), 1);
    padding: 1rem;
    border-radius: .8rem;
    margin-bottom: 1rem;
}

/* The heading is a functional of its own, and it is boxed the way the split is boxed:
   the same line, the same room inside it, the same room under it. Its colour is info —
   one of the five the theme leaves a border to be drawn in, and the only one of them
   this page had not already spent: success is the split, primary is a field of the
   album and of the file, danger is the forum, warning is the card of the publication.
   From this revision on, a box drawn in info is a box that speaks about a heading.
   The block is one for the whole form, not one per part: the marks it offers lay
   themselves over the heading of every part of a cut publication, and a control whose
   answer is the same for all of them has nothing to be repeated for. */
.publication-heading-controls {
    border: var(--bs-border-width) var(--bs-border-style) rgba(var(--bs-info-rgb), 1);
    padding: 1rem;
    border-radius: .8rem;
    margin-bottom: 1rem;
}

/* Every control of the heading wears the info the heading is boxed in: the
   switch of the first line, the four marks and the gate that gives the shape of
   the number to the field below the rows. The ring is drawn on both states, the
   way the switch of the images is drawn on its own — a control that is off keeps
   the white ground of the sheet and names its colour with the line around it, so
   turning it is a change of the fill alone. The sheet paints a control that is on
   in primary, and primary is the colour of a field of the album and of the file. */
.publication-heading-controls .form-check-input,
#publicationHeadingNumberCustom {
    border-color: rgba(var(--bs-info-rgb), 1);
}

.publication-heading-controls .form-check-input:checked,
#publicationHeadingNumberCustom:checked {
    background-color: rgba(var(--bs-info-rgb), 1);
}

/* A control that works on the parts wears the green the parts are wrapped in. The switch of
   the heading cut a first line out of a fill while the text was one publication; while the
   text is cut it works on every part — the first sentence of a part whose heading field is
   still empty goes to that field — so the line of the group reaches its toggle too. The ring
   reads the parts from the same `:has()` the box of the group reads them with, a merge row
   between two text blocks, so a split cannot ring the toggle and leave the box plain, or the
   other way round. Only the colour is answered: the vendored sheet gives a switch its
   thickness, its corner and its room, and a rule that retyped either would move a control the
   person clicks. The ring stands on the toggle and not on the row it opens: the words beside a
   switch are no control, and neither is the block they stand in. Keying the selector on
   `.form-check-input` beside the class puts it above the state rules of the sheet, and the
   card in front of it puts it above the rest of the form — the reading costs the script of the
   page nothing, since both classes ride the markup. */
.publication-post-card:has(#publicationTextParts > .publication-text-block + .publication-merge-row + .publication-text-block) .form-check-input.publication-heading-switch {
    border-color: rgba(var(--bs-success-rgb), 1);
}

/* What an album refused is named by a notice standing over that same album, and
   the notice is put away by a cross of its own. The cross is the element the ui-kit
   ships for it, `.btn-close`, but the sheet only pins that element into a corner
   inside `.alert-dismissible`, and these notices are not alerts: left to itself the
   cross falls below the text, on a line of its own. So the notice states the two
   halves of that arrangement itself — it is the box the cross is placed in, the
   cross stands in that box at the inset the notice's own padding leaves, so it
   comes level with the first line and flush with the column of the text, and the
   text gives up the width of the cross at the end of every line. */
.publication-notice {
    position: relative;
}

.publication-notice .publication-notice-close {
    position: absolute;
    top: .5rem;
    right: 1rem;
}

.publication-notice-text {
    display: block;
    padding-right: 1.75rem;
}

/* The icon that adds a button floats at the right of its fields and the notice
   under them runs past it, so both boxes that hold a float own a block
   formatting context: a float only counts towards the height of such a box, and
   without it the icon would hang out of the bottom of the white card. A float
   still shortens the line boxes of the text in the same context, which is the
   wrapping the notice wants. The vendored sheet has no `d-flow-root` utility, so
   the two boxes name the display themselves. */
.publication-field-frame,
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

/* The emoji panel is the rich popover of the ui-kit: its card, its header and its
   arrow are the styles of `.popover` and `.popover-rich`, and only the placement
   stays ours — the panel opens over the caret of the part that asked for it, in
   viewport coordinates like the buttons that move a selection. The compound
   selector is what keeps these three from being re-decided by load order. The
   width is the one the reference popover of `popovers.html` draws at, which is the
   `max-width` of `.popover-rich` itself: left to shrink-to-fit the card would stop
   at its `min-width` of 350px, and eight columns of emoji do not ask for more. */
.popover.publication-emoji-panel {
    position: fixed;
    width: 380px;
    z-index: 1080;
}

/* Popper, which normally owns the arrow of a popover, is not here: the arrow goes
   where the script that places the panel tells it. */
.publication-emoji-panel .popover-arrow {
    left: 0;
    position: absolute;
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

/* Eight columns of `minmax(0, 1fr)`, and no horizontal scroll: a track may not be
   widened by what stands in it, because the widest answer the list keeps — a
   family of four, which the font of the machine does draw as one figure — comes
   within two pixels of the whole width of a column, and a row that grew past the
   card would hang a second scrollbar under it. Five of the square rows below. */
.publication-emoji-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    max-height: 12.7rem;
    overflow-x: hidden;
    overflow-y: auto;
    margin-top: 0.5rem;
}

/* The cell is a square: `aspect-ratio` gives it the height of the column it stands
   in, and the box centers the figure itself, because a row of the old fixed height
   cannot follow a side the width of the card decides. */
.publication-emoji-cell {
    display: grid;
    place-items: center;
    aspect-ratio: 1;
    padding: 0;
    border: 0;
    border-radius: 0.25rem;
    background: none;
    font-size: 1.25rem;
}

/* The cell the arrows stopped on and the one under the cursor are the same
   thing: the keyboard follows the mouse and the mouse follows the keyboard. */
.publication-emoji-cell:hover,
.publication-emoji-cell.active {
    background-color: var(--bs-primary-bg-subtle);
}

/* Quill gives the buttons of its toolbar an icon of its own svg set and knows
   nothing of this one, so it takes the icon font of the portal. The two do not
   sit the same way: Quill floats its svg into the height of the button, so the
   icon never touches the line box, while a font glyph is placed on that box's
   baseline, and a baseline is not the middle of the ink. Centering the button
   gets the glyph out of the line box, and the block pseudo-element leaves it no
   baseline to lean on either. The size is measured off the neighbours: their ink
   is 12–14px tall in a 24px button, so at 1rem the smile was not only low but a
   third bigger than the row it stands in. */
.publication-editor .ql-toolbar .ql-emoji {
    align-items: center;
    color: var(--bs-body-color);
    display: flex;
    font-size: 0.875rem;
    justify-content: center;
    line-height: 1;
}

.publication-editor .ql-toolbar .ql-emoji .bi::before {
    display: block;
}

/* Over a panel filled with the purple of the block, the glyph of the one button it holds is
   read in the white of the palette. Quill answers the ink of a hovered button with a rule of
   four classes and one element, so this one names that state as well as the still one: naming
   only the button would keep its ink light until a pointer arrived and bring the grey of the
   vendor back on the hover. The answer is kept here rather than in the sheet of the editor
   because the colour it replaces already lives in this block, which the browser reads last. */
.publication-button-label-editor .ql-toolbar .ql-emoji,
.publication-button-label-editor .ql-toolbar .ql-emoji:hover:not(.ql-active) {
    color: var(--bs-white);
}

/* The block of the forum topics is as tall as the page that is visible under the
   sticky header, so the list of topics scrolls inside the block instead of the
   document scrolling. A rule cannot ask a neighbour how tall it is, and that
   neighbour is not one number: the header of the layout is 64px on a desktop
   window and 70px below `lg`, where its 46px brand mark stands beside the
   buttons — so the page script measures it into `--app-header-height` and the
   fallback is the desktop figure. `flex: 1 1 0` gives the rest of the card to the
   list, and `min-height: 0` lets it take less than its own content: a flex item
   that kept its automatic minimum would stretch over every topic it holds and the
   card would outgrow the page. */
#pub-forum-block {
    height: calc(100vh - var(--app-header-height, 64px));
}

#pub-forum-block > .scroll350 {
    flex: 1 1 0;
    min-height: 0;
}

/* The three list blocks stand 95% of what a person sees tall. The theme pins
   their scroll hosts at `height: 350px`, which is no fraction of any screen: a
   third of a 1080px window, and taller than the whole visible list in a short
   one. The height is asked of the space the sticky header leaves, not of the
   window: that header is no one number either (64px on a desktop window, 70px
   below `lg`), so the rule reads the `--app-header-height` the page script
   measures off the live element and the forum block above already asks the same
   space for — a list there scrolls inside its block instead of the document
   scrolling, while these three sit in a page that does, and 5% of the visible
   area stays free so a block never sits flush against the bottom edge. The list
   gets whatever the header of the card and its padding leave it, by the same two
   flex rules. A block holding one post, or none, keeps its 95% exactly as it
   kept its 350px. */
.card.publication-list-block {
    height: calc((100vh - var(--app-header-height, 64px)) * 0.95);
}

.publication-list-block > .card-body {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.publication-list-block > .card-body > .scroll350 {
    flex: 1 1 0;
    height: auto;
    min-height: 0;
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

                <!-- Forum topics: the id carries the height rule of registerCss -->
                <div class="card p-3 border border-danger to-do" id="pub-forum-block">
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
                    <div class="card-body">
                        <div class="d-flex flex-column gap-2 w-100" id="publicationPreview"
                             data-source="publicationTextInput"
                             data-placeholder="Введите текст публикации — он отобразится здесь до отправки в канал TRVL."></div>
                    </div>
                    <div class="card-footer bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="bi bi-eye me-1"></i>
                                Текст обновляется по мере ввода
                            </small>
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-3">
                                до <?= $textLimit ?> символов на часть
                            </span>
                        </div>
                    </div>
                </div>

                <!-- New post form -->
                <div class="card p-3 border border-warning in-progress publication-post-card">
                    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="icon-box sm bg-warning-subtle border border-warning rounded-circle">
                                <i class="bi bi-hourglass-split text-warning"></i>
                            </span>
                            <h5 class="text-warning fw-semibold m-0">Новая публикация</h5>
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
                            <input type="hidden" name="publicationPartGroupId" id="publicationPartGroupId" value="">
                            <?php /* Says the server that the parts of this submission are one group whose
                                     publication is started part by part. The page fills it on the submit, and
                                     only while the switch of the parts is on: a draft and a plain publication
                                     leave it empty. */ ?>
                            <input type="hidden" name="individualParts" id="publicationIndividualParts" value="">

                            <!-- Textarea: cloned into one field per part once a text goes past the limit -->
                            <?php /* The parts of a text and the controls that speak only of them — the row that
                                     splits it, the numbering, the distribution of images — make one box of the
                                     form, and the green goes round whichever of the two the reader is looking
                                     at: while the text is one part, the controls stand in the line on their
                                     own, and once it is cut, the line moves out to this whole group and the
                                     controls inside it stop drawing one. Which of the two draws is counted off
                                     the parts themselves, so the page keeps no class of its own to keep in
                                     step when the parts are rebuilt. */ ?>
                            <div class="publication-parts-group">
                                <?php /* The first of the three places a new part is born: above the first
                                         block, in every seam between two of them, and under the last one.
                                         A list of N parts therefore always offers N + 1 of them, and the
                                         seam row of the template below carries its own. The row stands at
                                         the right end of the group, and its button wears the green the
                                         parts are wrapped in — the colour of a control of the split. */ ?>
                                <div class="text-end mb-2">
                                    <button type="button"
                                            class="btn btn-outline-success btn-sm publication-add-part-btn"
                                            data-add-part="first" title="Добавить пустую часть в начало списка">
                                        <i class="bi bi-plus-lg me-1"></i>Добавить часть
                                    </button>
                                </div>
                                <div id="publicationTextParts">
                                    <div class="mb-3 publication-text-block">
                                        <?php /* The heading of a part: an optional first line the channel
                                                draws bold over the text under it. A numbered part moves
                                                its «Часть N» line in here, to the end of the heading. */ ?>
                                        <?php /* The line the block opens with: the label of the heading
                                                field at its left end and the two arrows of the part at its
                                                right, so a part is traded with a neighbour from the corner
                                                above the heading, before any text of it. The rebuild counts
                                                the numbers, names the fields and hands over the shared album
                                                by the new places. An arrow stands out of reach exactly when
                                                the part it trades with is missing, so the first of them
                                                cannot climb and the last cannot fall. While the text is one
                                                block the two are not drawn at all — the box of arrows gives
                                                up to the same `:has()` the frames of the parts are drawn by —
                                                and the first split brings them in. */ ?>
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="form-label mb-0 publication-title-label" for="publicationTitleInput">Заголовок</label>
                                            <div class="d-flex align-items-center gap-1 publication-part-moves">
                                                <button type="button" disabled
                                                        class="btn btn-outline-secondary btn-icon publication-part-up"
                                                        data-move-part="-1"
                                                        aria-label="Поднять часть выше"
                                                        title="Поменять эту часть местами с предыдущей">
                                                    <i class="bi bi-arrow-up-short"></i>
                                                </button>
                                                <button type="button" disabled
                                                        class="btn btn-outline-secondary btn-icon publication-part-down"
                                                        data-move-part="1"
                                                        aria-label="Опустить часть ниже"
                                                        title="Поменять эту часть местами со следующей">
                                                    <i class="bi bi-arrow-down-short"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <?php /* The heading of a part, marked the way its text is marked:
                                               the same editor paints over a hidden field, and the hidden
                                               field is what the form submits. The marks of a heading are
                                               counted from its first character and travel in a json of
                                               their own, next to the marks of the text. A heading is one
                                               line — the split of a text reads it as one — so a break that
                                               reaches it is folded into a space, and Enter does nothing.
                                               A heading that carries no marks of its own goes out plain;
                                               the marks of the heading block are laid on the way out. */ ?>
                                        <div class="publication-editor publication-title-editor">
                                            <textarea class="form-control publication-title-field d-none"
                                                      id="publicationTitleInput" name="publicationTitle"
                                                      tabindex="-1" aria-hidden="true"
                                                      placeholder="Жирная первая строка публикации"></textarea>
                                            <div class="publication-editor-field"></div>
                                            <input type="hidden" class="publication-format-field"
                                                   name="publicationTitleFormatting[]" value="[]">
                                        </div>
                                        <?php /* The line that names the text of the part, says in which block
                                               that part lives now and carries the counter of it at its right
                                               end. The word about the block is a pill of its own color with a
                                               glyph of the same block at the left of that word, and every part
                                               wears one: a part the form took out of a list wears that list,
                                               and one the reader wrote himself wears «Без блока». The line
                                               stands centered, not on the baseline — the label, the pill and
                                               the counter are three boxes of different heights, and centering
                                               is what keeps them level with each other. */ ?>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label for="publicationTextInput" class="form-label mb-0 publication-text-label">Текст публикации</label>
                                            <span class="publication-part-habitat d-inline-flex align-items-center ms-auto me-2"></span>
                                            <small class="text-muted publication-text-count"></small>
                                        </div>
                                        <?php /* Which row of which block this part was taken out of, so that
                                               saving sends it back to the very same place. A part a fill of the
                                               forum brings names the forum here without an id, and a part the
                                               reader wrote himself, and one born of a cut, carry nothing. */ ?>
                                        <input type="hidden" class="publication-part-slot"
                                               name="publicationPartSlot[]" value="">
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
                                        <?php /* The album of a part, framed like the shared album of the
                                                first part. The first block shows no album of its own either:
                                                the shared «Изображения публикации» block is carried into it
                                                while the text is cut, so this one stays hidden and disabled —
                                                a disabled field is not submitted and cannot shift the parts
                                                of the list. */ ?>
                                        <div class="kanban-item publication-field-frame publication-part-album p-3 rounded-2 bg-white d-none mt-2">
                                                <?php /* The same switch the shared album of the form carries, at the top
                                                        of the frame and in the block every part is cloned with: the album
                                                        of a part is rows of its own, and the order of one part says
                                                        nothing about another. */ ?>
                                                <div class="form-check form-switch mb-2" data-bs-toggle="popover"
                                                     data-bs-trigger="hover" data-bs-placement="top-start"
                                                     data-bs-custom-class="popover-info"
                                                     data-bs-content="Показывает каждое изображение этой части отдельным полем: её ссылки и её файлы встают в один список, которым можно управлять стрелками. Порядок этот и уходит в канал. Стрелки влево и вправо у строки отдают это изображение предыдущей или следующей части, и оно встаёт после тех, что в ней уже есть; у последней части стрелки вправо нет. На другие части переключатель не влияет.">
                                                    <input class="form-check-input publication-album-rows-switch publication-image-control"
                                                           type="checkbox" role="switch" id="publicationPartAlbumRows">
                                                    <label class="form-check-label" for="publicationPartAlbumRows">
                                                        <i class="bi bi-card-list me-1"></i>Каждое изображение в своём поле
                                                    </label>
                                                </div>

                                                <label class="form-label mb-1 publication-part-album-label" for="publicationPartImages">
                                                    <i class="bi bi-images me-1"></i>Изображения этой части
                                                </label>

                                                <textarea class="form-control publication-part-album-field" id="publicationPartImages"
                                                          name="publicationPartImages[]" rows="2" disabled
                                                          placeholder="По одному URL изображения в строке"></textarea>

                                                <?php /* The rows of this album, cloned with the block and rebuilt from
                                                        its own fields; the mask under them is the third of them, and the
                                                        one name it carries makes the list of parts the server weaves. */ ?>
                                                <div class="d-flex flex-column gap-2 publication-album-rows mt-2 d-none">
                                                    <div class="row gx-2 gy-2 align-items-center publication-album-row">
                                                        <div class="col-sm">
                                                            <input type="text" class="form-control publication-album-link"
                                                                   placeholder="https://example.com/photo.jpg"
                                                                   aria-label="Адрес изображения">
                                                            <div class="form-control bg-body-tertiary text-truncate publication-album-file d-none">
                                                                <i class="bi bi-file-earmark-image me-1"></i>
                                                                <span class="publication-album-file-name"></span>
                                                            </div>
                                                        </div>
                                                        <div class="col-sm-auto">
                                                            <button type="button"
                                                                    class="btn btn-outline-secondary btn-icon publication-album-up"
                                                                    aria-label="Поднять изображение выше"
                                                                    title="Поднять это изображение на строку выше">
                                                                <i class="bi bi-arrow-up-short"></i>
                                                            </button>
                                                            <button type="button"
                                                                    class="btn btn-outline-secondary btn-icon publication-album-down"
                                                                    aria-label="Опустить изображение ниже"
                                                                    title="Опустить это изображение на строку ниже">
                                                                <i class="bi bi-arrow-down-short"></i>
                                                            </button>
                                                            <button type="button"
                                                                    class="btn btn-outline-secondary btn-icon publication-album-prev"
                                                                    aria-label="Переместить изображение в предыдущую часть"
                                                                    title="Это изображение переедет в предыдущую часть и встанет после тех, что в ней уже есть">
                                                                <i class="bi bi-arrow-left-short"></i>
                                                            </button>
                                                            <button type="button"
                                                                    class="btn btn-outline-secondary btn-icon publication-album-next"
                                                                    aria-label="Переместить изображение в следующую часть"
                                                                    title="Это изображение переедет в следующую часть и встанет после тех, что в ней уже есть">
                                                                <i class="bi bi-arrow-right-short"></i>
                                                            </button>
                                                            <button type="button"
                                                                    class="btn btn-danger btn-icon publication-album-remove"
                                                                    aria-label="Убрать изображение" title="Убрать это изображение из альбома">
                                                                <i class="bi bi-x-lg"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="d-flex justify-content-end publication-album-add-box mt-1 d-none">
                                                    <button type="button"
                                                            class="btn btn-primary btn-icon publication-album-add"
                                                            aria-label="Добавить изображение в альбом"
                                                            title="Добавить в этот альбом ещё одну ссылку">
                                                        <i class="bi bi-plus-lg"></i>
                                                    </button>
                                                </div>
                                                <input type="hidden" name="publicationPartImageOrder[]"
                                                       class="publication-album-order">

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
                                                <div class="bg-primary-subtle px-3 py-2 mt-1 rounded-2 text-break d-none publication-notice publication-images-notice"
                                                     role="status">
                                                    <span class="publication-notice-text"></span>
                                                    <button type="button" class="btn-close publication-notice-close"
                                                            aria-label="Скрыть уведомление"></button>
                                                </div>
                                                <?php /* A file the album will not take is named here: the pick stays out
                                                        of the form, and the album keeps what it already held. */ ?>
                                                <div class="bg-primary-subtle px-3 py-2 mt-1 rounded-2 text-break d-none publication-notice publication-files-notice"
                                                     role="status">
                                                    <span class="publication-notice-text"></span>
                                                    <button type="button" class="btn-close publication-notice-close"
                                                            aria-label="Скрыть уведомление"></button>
                                                </div>
                                                <div class="stacked-images publication-album-strip mt-2 d-none"></div>
                                                <?php /* The slider of this album, in the shape the ui-kit
                                                        gives it: the clone of a part block brings its own. */ ?>
                                                <div class="carousel slide publication-album-carousel mt-2 d-none">
                                                    <div class="carousel-indicators"></div>
                                                    <div class="carousel-inner"></div>
                                                    <button class="carousel-control-prev" type="button" data-bs-slide="prev">
                                                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                                        <span class="visually-hidden">Предыдущее изображение</span>
                                                    </button>
                                                    <button class="carousel-control-next" type="button" data-bs-slide="next">
                                                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                                        <span class="visually-hidden">Следующее изображение</span>
                                                    </button>
                                                </div>
                                        </div>

                                        <?php /* The block of the buttons of a part. It is a frame of
                                                the ui-kit, and the purple line the sheet gives the block
                                                of the form covers it too: the frame colours by the box
                                                of rows it holds, and that box stands inside the frame.
                                                The first part wears this block only while the switch of
                                                the green box stands; the parts below it wear it when
                                                that switch or the old «Кнопки-ссылки в каждой части»
                                                is thrown. A block out of sight is disabled, so it
                                                submits nothing and the part goes without a keyboard.
                                                Every row is one button with the same two fields; the
                                                rows of a box share one name, so the form submits them
                                                as the list of buttons of that part. The two attributes
                                                below are the bases of those names: a row gains its name
                                                from a base and its own id from the row number, both
                                                rewritten when the box moves to another part — and when
                                                the mode hands the first part its own block, for then
                                                that block submits under the name of the shared field.
                                                The switch at the top of the frame belongs to this part
                                                alone: the page renames it with the place of the part,
                                                and the fill reads it for every part on its own. */ ?>
                                        <div class="kanban-item publication-field-frame publication-part-button p-3 rounded-2 bg-white d-none mt-2">
                                            <div class="form-check form-switch mb-2" data-bs-toggle="popover"
                                                 data-bs-trigger="hover" data-bs-placement="top-start"
                                                 data-bs-custom-class="popover-info"
                                                 data-bs-content="Через 10 секунд после того, как текст этой части перестали править, дописывает в её кнопки адреса этого текста, кроме ссылок на изображения. Набранные вручную кнопки оставляет, адрес, который в кнопках этой части уже есть, не повторяет. Действует, пока включён блок кнопок этой части; на другие части не влияет.">
                                                <input class="form-check-input publication-part-links-switch"
                                                       type="checkbox" role="switch" id="publicationPartLinksToButtons1">
                                                <label class="form-check-label" for="publicationPartLinksToButtons1">
                                                    <i class="bi bi-link-45deg me-1"></i>Ссылки из текста в кнопки-ссылки
                                                </label>
                                            </div>
                                            <div class="publication-button-box">
                                                <label class="form-label mb-1 publication-buttons-label" for="publicationPartButtonText0-0">
                                                    <i class="bi bi-link-45deg me-1"></i>Кнопки-ссылки этой части
                                                </label>
                                                <div class="d-flex flex-column gap-2 publication-button-rows"
                                                     data-text="publicationPartButtonText0"
                                                     data-url="publicationPartButtonUrl0">
                                                    <div class="row gx-2 gy-2 align-items-center publication-button-row">
                                                        <div class="col-sm-4">
                                                            <div class="publication-editor publication-button-label-editor">
                                                                <textarea class="form-control publication-button-text d-none"
                                                                          id="publicationPartButtonText0-0"
                                                                          name="publicationPartButtonText0[]"
                                                                          tabindex="-1" aria-hidden="true" disabled
                                                                          placeholder="Надпись кнопки"></textarea>
                                                                <div class="publication-editor-field"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-sm">
                                                            <input type="text" class="form-control publication-button-url"
                                                                   id="publicationPartButtonUrl0-0"
                                                                   name="publicationPartButtonUrl0[]" maxlength="2048" disabled
                                                                   placeholder="https://example.com/poll">
                                                        </div>
                                                        <div class="col-sm-auto d-none">
                                                            <button type="button"
                                                                    class="btn btn-outline-secondary btn-icon publication-button-up"
                                                                    aria-label="Поднять кнопку-ссылку выше" disabled
                                                                    title="Поднять эту кнопку на строку выше">
                                                                <i class="bi bi-arrow-up-short"></i>
                                                            </button>
                                                            <button type="button"
                                                                    class="btn btn-outline-secondary btn-icon publication-button-down"
                                                                    aria-label="Опустить кнопку-ссылку ниже" disabled
                                                                    title="Опустить эту кнопку на строку ниже">
                                                                <i class="bi bi-arrow-down-short"></i>
                                                            </button>
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
                                        </div>
                                        <?php /* The three handles of one part. «Сохранить»
                                                carries the part into the drafts block, «Удалить»
                                                into the deleted one, «Очистить» leaves the part in
                                                the form and frees its fields for the reader to fill
                                                again. The two that move a part wear the colors and
                                                the glyphs the same action wears on a row of a list,
                                                because the logic is the very same one: a part that
                                                already stands in the block it would be moved to says
                                                so by staying off. «Очистить» is the grey of the
                                                general «Очистить» under the form, not the red of a
                                                deletion. Their size and shape were asked for the same
                                                day and come from the «Опубликовать» under the form:
                                                the plain `btn`, not the small pill of a list row.
                                                The row is cloned with every part and gives
                                                up to the same `:has()` as the arrows: while the text
                                                is one block there is no part to move out of the form.
                                                It stands above the queue row, which the reader asked
                                                to be the last thing a part holds. */ ?>
                                        <div class="d-flex justify-content-end gap-2 mt-2 publication-part-actions">
                                            <button type="button"
                                                    class="btn btn-outline-warning"
                                                    data-part-action="save"
                                                    title="Сохранить эту часть в черновики">
                                                <i class="bi bi-file-earmark-arrow-down me-1"></i>Сохранить
                                            </button>
                                            <button type="button"
                                                    class="btn btn-outline-danger"
                                                    data-part-action="delete"
                                                    title="Удалить эту часть">
                                                <i class="bi bi-trash me-1"></i>Удалить
                                            </button>
                                            <button type="button"
                                                    class="btn btn-outline-secondary"
                                                    data-part-action="clear"
                                                    title="Освободить поля этой части">
                                                <i class="bi bi-x-circle me-1"></i>Очистить
                                            </button>
                                        </div>
                                        <?php /* Every part of a split carries its own time and its own
                                                «Опубликовать». Only the first of them is a live button: it is
                                                the submit of the form, which saves all the parts and releases
                                                the first. A part below it has no row to release yet — the
                                                group is written when the form is saved — so its button stands
                                                off and the line under the field says where it opens. The row
                                                is the last box of a part, under its album and under its
                                                buttons: the queue of a part is what a reader meets last
                                                before the row that merges two parts. */ ?>
                                        <div class="publication-part-schedule d-none mt-2">
                                            <label class="form-label mb-1 publication-part-at-label">Дата и время публикации</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control publication-part-at publication-datepicker-time"
                                                       name="publicationPartAt[]" autocomplete="off" disabled>
                                                <button type="button" class="btn btn-outline-success publication-part-schedule-button"
                                                        disabled><i class="bi bi-send me-1"></i>Опубликовать</button>
                                            </div>
                                            <div class="form-text text-muted publication-part-schedule-hint d-none"></div>
                                        </div>

                                    </div>
                                    <!-- The row between a part and the one under it; the last part has none.
                                         Three handles of one seam: the scissors cut the part the row stands
                                         under, the empty part it grows, and the two parts it joins back into
                                         one. The cut answers for the part above because the row already speaks
                                         of that one: «Объединить» says «Слить эту часть со следующей». The
                                         scissors take the left edge of the row the way the handle under the
                                         list took the left edge of its own row, and the row lets its handles
                                         wrap: three of them do not stand in one line of a phone. -->
                                    <div class="d-flex flex-wrap justify-content-end gap-2 d-none publication-merge-row">
                                        <button type="button"
                                                class="btn btn-outline-success btn-sm me-auto publication-seam-split-btn"
                                                title="Разделить часть над этим швом по курсору, а без курсора — примерно посередине">
                                            <i class="bi bi-scissors me-1"></i>Разделить
                                        </button>
                                        <button type="button"
                                                class="btn btn-outline-success btn-sm publication-add-part-btn"
                                                data-add-part="seam" title="Добавить пустую часть между этими двумя">
                                            <i class="bi bi-plus-lg me-1"></i>Добавить часть
                                        </button>
                                        <button type="button"
                                                class="btn btn-outline-success btn-sm publication-merge-btn"
                                                title="Слить эту часть со следующей в одно поле">
                                            <i class="bi bi-arrows-collapse-vertical me-1"></i>Объединить
                                        </button>
                                    </div>
                                </div>

                                <?php /* The last of the three places: under the bottom block, so a list of
                                       N parts always offers N + 1 of them. The row is outside the box the
                                       parts are rebuilt in, so the split, the merge and the moving of a
                                       part cannot take it away with the rest of the inner HTML. The room
                                       under it is its own: the box it stands before — the controls of the
                                       split — draws a green line, and a row with no margin of its own
                                       presses its button onto that line. */ ?>
                                <div class="text-end mt-2 mb-2">
                                    <button type="button"
                                            class="btn btn-outline-success btn-sm publication-add-part-btn"
                                            data-add-part="last" title="Добавить пустую часть в конец списка">
                                        <i class="bi bi-plus-lg me-1"></i>Добавить часть
                                    </button>
                                </div>

                                <div class="publication-parts-controls">
                                    <!-- Outside the parts box, so the template a new part is cloned from stays clean. -->
                                    <div class="d-flex flex-wrap justify-content-end gap-2 mb-2" id="publicationSplitModes">
                                        <?php /* The row answers two questions, and since 2026-10-23 it says so by
                                               standing: the cut of one part — at the caret, or the middle of it —
                                               keeps the left edge, while the three ways of cutting the whole text
                                               stay gathered at the right. The edge is bought with an auto margin on
                                               the button, not with a class of the row: the class string of the row
                                               is an anchor of the notices harness, and the gathering it declares is
                                               still wanted. */ ?>
                                        <button type="button" class="btn btn-outline-success btn-sm me-auto" id="publicationSplitPart"
                                                title="Разделить часть по курсору, а без курсора — примерно посередине">
                                            <i class="bi bi-scissors me-1"></i>Разделить
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm" data-split-whole="paragraphs"
                                                title="Разбить весь текст по абзацам: пустая строка начинает новую часть">
                                            <i class="bi bi-paragraph me-1"></i>По абзацам
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm" data-split-whole="lines"
                                                title="Разбить весь текст по переносам строк: каждая строка становится частью">
                                            <i class="bi bi-list-nested me-1"></i>По строкам
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm" data-split-whole="sentences"
                                                title="Разбить весь текст по предложениям: «.», «!», «?» и «…» начинают новую часть">
                                            <i class="bi bi-chat-left-text me-1"></i>По предложениям
                                        </button>
                                    </div>
                                    <?php /* The options of the number are one functional of their own, and the reader
                                            asked them boxed the way the split is boxed: the same green line, the same
                                            room, the same corners. The box of the controls of the split stops drawing
                                            where the parts already draw one; this one has no box of its own to be said
                                            by, so it draws always. */ ?>
                                    <div class="publication-numbering-controls">
                                        <div class="form-check form-switch mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                             data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                             data-bs-content="Дописывает «Часть 1», «Часть 2» … в заголовок каждой части разбитой публикации: форму номера задают два ряда ниже, а пока «Первая строка — в заголовок» выключен — номер встаёт в начало самого текста">
                                            <input class="form-check-input" type="checkbox" role="switch" id="publicationNumberParts"
                                                <?= $numberParts ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="publicationNumberParts">
                                                <i class="bi bi-list-ol me-1"></i>Нумерация частей
                                            </label>
                                        </div>
                                        <?php /* The first of the two rows of radios under that switch answers WHICH end
                                                    of the heading wears the number, the second answers with WHAT that
                                                    number is joined to the words, and the checkbox under both hands the
                                                    whole shape over to the field of the reader. All of it travels to the
                                                    server as one string of the two tokens, together with the switch
                                                    itself: the page keeps the shape in the session, so the numbering and
                                                    the shape the reader left stand there when the form opens again.
                                                    While the numbering is off the five radios have nothing to decide,
                                                    and once the checkbox is on the two rows have nothing to decide
                                                    either — they are greyed out rather than taken away, and the words
                                                    the reader chose stay visible and stay kept. */ ?>
                                        <div class="mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                             data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                             data-bs-content="Где в заголовке части стоит её номер: в начале, как «Часть 1. Текст», или в конце, как «Текст. Часть 1». Пока включена «Нумерация частей»; с выключенной выбирать нечего.">
                                            <div class="form-label mb-1">Расположение</div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="publicationHeadingNumberPlace"
                                                       id="publicationHeadingNumberBefore" value="before"
                                                       <?= ($numberParts && !$headingNumberCustom) ? '' : 'disabled' ?>
                                                       <?= $headingNumberPlace === 'after' ? '' : 'checked' ?>>
                                                <label class="form-check-label" for="publicationHeadingNumberBefore">В начале заголовка</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="publicationHeadingNumberPlace"
                                                       id="publicationHeadingNumberAfter" value="after"
                                                       <?= ($numberParts && !$headingNumberCustom) ? '' : 'disabled' ?>
                                                       <?= $headingNumberPlace === 'after' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="publicationHeadingNumberAfter">В конце заголовка</label>
                                            </div>
                                        </div>
                                        <div class="mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                             data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                             data-bs-content="Чем номер части соединяется со словами заголовка: точка, дефис или скобки. Получается «Текст. Часть 1», «Текст - Часть 1» и «Текст (Часть 1)». Пока включена «Нумерация частей» и выключен «Свой шаблон».">
                                            <div class="form-label mb-1">Разделитель</div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="publicationHeadingNumberStyle"
                                                       id="publicationHeadingNumberDot" value="dot"
                                                       <?= ($numberParts && !$headingNumberCustom) ? '' : 'disabled' ?>
                                                       <?= $headingNumberStyle === 'dot' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="publicationHeadingNumberDot">Точка</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="publicationHeadingNumberStyle"
                                                       id="publicationHeadingNumberDash" value="dash"
                                                       <?= ($numberParts && !$headingNumberCustom) ? '' : 'disabled' ?>
                                                       <?= $headingNumberStyle === 'dash' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="publicationHeadingNumberDash">Дефис</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="publicationHeadingNumberStyle"
                                                       id="publicationHeadingNumberBracket" value="bracket"
                                                       <?= ($numberParts && !$headingNumberCustom) ? '' : 'disabled' ?>
                                                       <?= $headingNumberStyle === 'bracket' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="publicationHeadingNumberBracket">Скобки</label>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                             data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                             data-bs-content="Отдаёт форму номера полю под рядами: пока включён, «Расположение» и «Разделитель» гаснут, а строку пишет сам читатель. Пока включена «Нумерация частей»; с выключенной отдавать нечего.">
                                            <input class="form-check-input" type="checkbox" role="switch" id="publicationHeadingNumberCustom"
                                                   <?= $headingNumberCustom ? 'checked' : '' ?>
                                                   <?= $numberParts ? '' : 'disabled' ?>>
                                            <label class="form-check-label" for="publicationHeadingNumberCustom">
                                                <i class="bi bi-pencil me-1"></i>Свой шаблон
                                            </label>
                                        </div>
                                        <?php /* The row of the field is the field. The hint of a switch shrinks its row
                                                        to the switch, because the empty right half of a row of a switch
                                                        is not a control and must not drag the card to the middle of it;
                                                        the same rule held this field at the twenty characters an input
                                                        reports, so the boxed field drew narrower than the box. The
                                                        theme's own auto width lifts this row alone. */ ?>
                                        <div class="mb-3 w-auto" data-bs-toggle="popover" data-bs-trigger="hover"
                                             data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                             data-bs-content="Строка, где #NUMBER# — число части, а #TITLE_TEXT# — её слова. Пока «Свой шаблон» выключен, поле только зеркалит то, на чём стоят два ряда выше.">
                                            <label class="form-label" for="publicationHeadingNumberTemplate">Шаблон</label>
                                            <input class="form-control" type="text" id="publicationHeadingNumberTemplate"
                                                   value="<?= Html::encode($headingNumberTemplate) ?>"
                                                   maxlength="120"
                                                   <?= ($numberParts && $headingNumberCustom) ? '' : 'disabled' ?>>
                                        </div>
                                    </div>

                                    <div class="form-check form-switch mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                         data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                         data-bs-content="Добавляет каждой части дату и отдельную кнопку постановки в очередь, показывает у части её ручки «Сохранить», «Удалить» и «Очистить». Следующая часть станет доступна после фактической публикации предыдущей.">
                                        <input class="form-check-input" type="checkbox" role="switch" id="publicationPartsIndividually"
                                            <?= $partsPublication ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationPartsIndividually">
                                            <i class="bi bi-box-seam me-1"></i>Работать с частями по отдельности
                                        </label>
                                    </div>

                                    <?php /* This one travels to the server too, and what it carries is
                                                its own state only: the mode the reader left stands in the
                                                session when the form opens again. The block it hands to
                                                every part is a node the page draws, and the switch of
                                                that block is the state of the page — a part carries it,
                                                not the record, and nothing of it goes over the server. */ ?>
                                    <div class="form-check form-switch mb-3" data-bs-toggle="popover"
                                         data-bs-trigger="hover" data-bs-placement="top-start"
                                         data-bs-custom-class="popover-info"
                                         data-bs-content="Даёт каждой части — и первой тоже — свой блок кнопок-ссылок: свой переключатель «Ссылки из текста в кнопки-ссылки» и свои ряды с кнопкой «Добавить кнопку». Общий блок при этом гаснет и остаётся на месте: части берут кнопки только из своих блоков. Набранные в общем блоке кнопки переходят в блок первой части.">
                                        <input class="form-check-input" type="checkbox" role="switch" id="publicationButtonsPerPart"
                                            <?= $buttonsPerPart ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationButtonsPerPart">
                                            <i class="bi bi-link-45deg me-1"></i>Свои кнопки-ссылки у каждой части
                                        </label>
                                    </div>

                                    <?php /* The switch that hands the buttons of the block of
                                                the form to every part of a split publication. It
                                                stood inside that block until the ask of 2026-10-30
                                                gave it this box and asked that it show whatever the
                                                form holds: the reader throws it before the text is
                                                cut, so the row may not wait for the cut. */ ?>
                                    <div class="form-check form-switch mb-3" id="publicationButtonEveryPartRow"
                                         data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                                         data-bs-custom-class="popover-info"
                                         data-bs-content="Показывает поля кнопок у каждой части и заполняет их этими же кнопками; надпись и адрес одной части можно поправить после этого.">
                                        <input class="form-check-input" type="checkbox" role="switch" id="publicationButtonEveryPart">
                                        <label class="form-check-label" for="publicationButtonEveryPart">
                                            Кнопки-ссылки в каждой части
                                        </label>
                                    </div>

                                    <?php /* This one does travel to the server, and what it carries is only its
                                                own state: the page keeps it in the session, so the mode the
                                                reader left stands there when the form opens again. The albums
                                                it hands out are the submitted fields of the form — the switch
                                                arranges those, it is none of them. */ ?>
                                    <div class="form-check form-switch mb-3" data-bs-toggle="popover" data-bs-trigger="hover"
                                         data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                         data-bs-content="Пока включён, изображения раздаются по частям сами: при прогрузке поста с форума и каждый раз, когда частей в форме становится больше или меньше. Раздача берёт изображения всех частей по порядку и режет их на равные группы, поэтому своя раскладка при этом теряется. Выключенный тумблер ничего не возвращает: как разошлось, так и останется.">
                                        <input class="form-check-input publication-image-control" type="checkbox" role="switch"
                                               id="publicationDistributeImages"
                                               <?= $distributeImages ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationDistributeImages">
                                            <i class="bi bi-card-image me-1"></i>Равномерно распределить изображения между частями
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <?php /* The heading of a publication is one functional, and everything
                                       that decides what a heading is stands in this box: the switch
                                       that cuts a first line into it, and the four marks it is laid
                                       over with. A box that stands checked puts its mark over the
                                       whole line of a heading that carries no marks of its own — and
                                       over the heading of every part of a cut publication, since one
                                       form is one publication and one heading answers for all of its
                                       parts. A heading the reader has marked himself in its field is
                                       his: the boxes do not touch it, and nothing is laid over what
                                       he set. With all four boxes off a heading goes out plain, which
                                       is exactly what a heading of an empty field is. The marks are
                                       chosen once for the form and travel out with every heading that
                                       needs them; the state of the four boxes lives in the session,
                                       like the switch above them and the rest of the form. */ ?>
                            <div class="publication-heading-controls">
                                <?php /* A fill of the forum block brings one run of text. With this
                                       switch its first line goes to the heading field of the part it
                                       fills, so the line a topic stands in front of its own text — or
                                       the line an author opened a post with — is drawn over that
                                       text instead of being its first words. While the text is cut the
                                       same switch works over every part: the first sentence of a part
                                       whose heading field is still empty goes into it, since a part can
                                       open with several short sentences standing on one line. A heading
                                       the reader already wrote is left alone, and a text is never cut
                                       when nothing would be left of it. The state of the switch lives in
                                       the session, like the filters of the block above and the order of
                                       the lists, so a reload reads the form the way the reader left it
                                       and the address of the page says it too. Its toggle wears the
                                       green of the parts while the text is cut into them. */ ?>
                                <div class="form-check form-switch mb-3"
                                     data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                                     data-bs-custom-class="popover-info"
                                     data-bs-content="При подгрузке из блока «Форум» первую строку текста пишет в поле «Заголовок», а остальное оставляет в тексте. У тофика первой строкой стоит его название, у поста — первая набранная им строка. Пока текст разбит на части, в заголовок каждой части с пустым полем уходит первое предложение её текста: первой строкой в части может идти несколько коротких предложений. Если после заголовка ничего не остаётся, текст не режется, а уже заполненный заголовок остаётся как есть. Пока части есть, рамка переключателя зелёная — он работает с частями.">
                                    <input class="form-check-input publication-heading-switch" type="checkbox" role="switch"
                                           id="publicationTitleFromFirstLine"
                                           <?= $titleFromFirstLine ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="publicationTitleFromFirstLine">
                                        <i class="bi bi-card-heading me-1"></i>Первая строка — в заголовок
                                    </label>
                                </div>
                                <?php /* The four marks of a heading. Each of them lays its own span
                                       over the whole line — a heading of a part wears them all at once,
                                       the way the channel reads a bold italic underlined strike — and
                                       they are asked of the form once, not of every part. The bold box
                                       stands by default: until this revision every heading went out
                                       bold over its whole line, and a form opened for the first time
                                       says the same thing the page used to do without being asked. */ ?>
                                <div class="mb-0" data-bs-toggle="popover" data-bs-trigger="hover"
                                     data-bs-placement="top-start" data-bs-custom-class="popover-info"
                                     data-bs-content="Ставит свою метку на всю строку заголовка — и на заголовок каждой части, пока текст разбит. Работает только там, где у заголовка своих меток нет: заголовок, отмеченный в самом поле, идёт ровно так, как его отметили, и ничего сверху ему не кладётся. Все четыре сняты — заголовок идёт без меток. Жирный включён по умолчанию: так заголовок выходил всегда.">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="publicationHeadingBold"
                                               value="bold" <?= in_array('bold', $headingFormatting, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationHeadingBold">Жирный</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="publicationHeadingItalic"
                                               value="italic" <?= in_array('italic', $headingFormatting, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationHeadingItalic">Курсив</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="publicationHeadingStrike"
                                               value="strike" <?= in_array('strike', $headingFormatting, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationHeadingStrike">Зачёркнуто</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="publicationHeadingUnderline"
                                               value="underline" <?= in_array('underline', $headingFormatting, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationHeadingUnderline">Подчёркнуто</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Attached images -->
                            <?php /* The mark the album of the form was born by, and the block it is
                                    carried in: while the text is cut into parts the page moves the block
                                    itself into the first part, and this empty mark is the place it comes
                                    back to when the text is one piece again. The block is the album of
                                    the first part either way — the fields travel under the names of the
                                    publication, and nothing but their place in the form changes. */ ?>
                            <span id="publicationAlbumHome" hidden></span>
                            <div class="mb-3" id="publicationAlbumBlock" data-in-part="0">
                                <?php /* The album of the first part of the publication is this block, and
                                        it stands in the same bordered card the buttons of the form and the
                                        albums of its other parts stand in, so one album reads as one group.
                                        A cut carries the card into the first part, which is the part the
                                        album belongs to; the hidden album of that row stays hidden. */ ?>
                                <div class="kanban-item publication-field-frame p-3 rounded-2 bg-white">
                                    <?php /* The switch that lays this album out as rows stands at the top of
                                            the frame, the way the switch of the button frame stands there: it
                                            says how the album below is shown before the album starts. This one
                                            does travel to the server, and what it carries is only its own state:
                                            the page keeps it in the session, so the album opens the rows the
                                            reader left it in. The album itself still goes out as a text area, a
                                            picker and a mask — the switch arranges those, it is none of them. */ ?>
                                    <div class="form-check form-switch mb-2" data-bs-toggle="popover"
                                         data-bs-trigger="hover" data-bs-placement="top-start"
                                         data-bs-custom-class="popover-info"
                                         data-bs-content="Показывает каждое изображение отдельным полем: ссылки из «Изображения публикации» и файлы из «Файлы публикации» встают в один список, которым можно управлять стрелками. Порядок этот и уходит в канал — первым изображением части будет то, что стоит наверху, а не первая ссылка поля. Поле с файлом только показывает его имя: заменить файл можно через «Файлы публикации» ниже. Стрелки влево и вправо у строки отдают это изображение предыдущей или следующей части, и оно встаёт после тех, что в ней уже есть. У первой части стрелки влево нет, у последней части стрелки вправо нет, а пока текст публикации не разбит, стрелок перехода нет вовсе. Исходное поле с ссылками скрыруется, но остаётся в форме."
                                         data-bs-content-part="Показывает каждое изображение этой части отдельным полем: ссылки из «Изображения этой части» и файлы из «Файлы этой части» встают в один список, которым можно управлять стрелками. Порядок этот и уходит в канал — первым изображением части будет то, что стоит наверху, а не первая ссылка поля. Поле с файлом только показывает его имя: заменить файл можно через «Файлы этой части» ниже. Стрелки влево и вправо у строки отдают это изображение предыдущей или следующей части, и оно встаёт после тех, что в ней уже есть. У первой части стрелки влево нет, у последней части стрелки вправо нет, а пока текст публикации не разбит, стрелок перехода нет вовсе. Исходное поле с ссылками скрыруется, но остаётся в форме.">
                                        <input class="form-check-input publication-album-rows-switch publication-image-control"
                                               type="checkbox" role="switch" id="publicationAlbumRows"
                                               <?= $albumRows ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="publicationAlbumRows">
                                            <i class="bi bi-card-list me-1"></i>Каждое изображение в своём поле
                                        </label>
                                    </div>

                                    <label for="publicationImages" class="form-label"
                                           data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top-start"
                                           data-bs-custom-class="popover-info"
                                           data-bs-content="Изображения отправляются в канал вместе с текстом публикации (первое — с подписью); у разбитой публикации это изображения её первой части. Файл, выбранный здесь, сохраняется на сервере и становится ссылкой этого же альбома."
                                           data-bs-content-part="Изображения отправляются в канал вместе с текстом этой части (первое — с подписью). Файл, выбранный здесь, сохраняется на сервере и становится ссылкой этого же альбома.">
                                        <i class="bi bi-images me-1"></i><span class="publication-album-name"
                                                data-shared="Изображения публикации"
                                                data-part="Изображения этой части">Изображения публикации</span>
                                    </label>

                                    <textarea class="form-control" id="publicationImages" name="publicationImages"
                                              rows="3"
                                              placeholder="По одному URL изображения в строке&#10;https://example.com/photo1.jpg&#10;https://example.com/photo2.jpg"></textarea>

                                    <?php /* The rows themselves: one per picture of the album, in the order the
                                            channel gets them in. A row of a link holds an input without a name and
                                            a row of a file holds its name — the fields that travel stay the text
                                            area above, the picker below and the mask beside them. The one row of
                                            the box doubles as the template the rest are cloned from, the way a
                                            row of the button box does. */ ?>
                                    <div class="d-flex flex-column gap-2 publication-album-rows mt-2 d-none"
                                         id="publicationAlbumRowsBox">
                                        <div class="row gx-2 gy-2 align-items-center publication-album-row">
                                            <div class="col-sm">
                                                <input type="text" class="form-control publication-album-link"
                                                       placeholder="https://example.com/photo.jpg"
                                                       aria-label="Адрес изображения">
                                                <div class="form-control bg-body-tertiary text-truncate publication-album-file d-none">
                                                    <i class="bi bi-file-earmark-image me-1"></i>
                                                    <span class="publication-album-file-name"></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-auto">
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-icon publication-album-up"
                                                        aria-label="Поднять изображение выше"
                                                        title="Поднять это изображение на строку выше">
                                                    <i class="bi bi-arrow-up-short"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-icon publication-album-down"
                                                        aria-label="Опустить изображение ниже"
                                                        title="Опустить это изображение на строку ниже">
                                                    <i class="bi bi-arrow-down-short"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-icon publication-album-prev"
                                                        aria-label="Переместить изображение в предыдущую часть"
                                                        title="Это изображение переедет в предыдущую часть и встанет после тех, что в ней уже есть">
                                                    <i class="bi bi-arrow-left-short"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-icon publication-album-next"
                                                        aria-label="Переместить изображение в следующую часть"
                                                        title="Это изображение переедет в следующую часть и встанет после тех, что в ней уже есть">
                                                    <i class="bi bi-arrow-right-short"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-danger btn-icon publication-album-remove"
                                                        aria-label="Убрать изображение" title="Убрать это изображение из альбома">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <?php /* The icon that opens a row of this album: a blank address is not a
                                            picture of the album yet, so the row stands behind the pictures until
                                            the reader types one into it. It shows with the rows and takes the same
                                            shape the icon of the button box takes. */ ?>
                                    <div class="d-flex justify-content-end publication-album-add-box mt-1 d-none">
                                        <button type="button" class="btn btn-primary btn-icon publication-album-add"
                                                aria-label="Добавить изображение в альбом"
                                                title="Добавить в этот альбом ещё одну ссылку">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>

                                    <?php /* The weave of the two fields: a token per picture of the album, "l" for
                                            the next line of the text area above and "f" for the next file of the
                                            picker below. The channel takes the links first and the stored files
                                            after them, so this is the only way an order that reaches across both
                                            has of getting there; a mask that does not fit the album it stands in
                                            is let go of, and the album keeps the plain order. */ ?>
                                    <input type="hidden" id="publicationImageOrder" name="publicationImageOrder"
                                           class="publication-album-order">

                                    <?php /* The companion of the links field for the first part of the
                                            publication: the album of that part is this block, so its
                                            picker carries the name of the field, not of a part. The
                                            brackets make PHP keep every file of a `multiple` input
                                            instead of only the last one. */ ?>
                                    <label class="form-label mb-1 mt-2" for="publicationImageFiles">
                                        <i class="bi bi-file-earmark-image me-1"></i><span class="publication-album-name"
                                                data-shared="Файлы публикации"
                                                data-part="Файлы этой части">Файлы публикации</span>
                                    </label>
                                    <input type="file" class="form-control" id="publicationImageFiles"
                                           name="publicationImageFiles[]" accept="image/*" multiple>

                                    <?php /* Named here are the links a fill left out: the shape is the
                                            one the ui-kit gives a day divider inside a chat column. */ ?>
                                    <div class="bg-primary-subtle px-3 py-2 mt-1 rounded-2 text-break d-none publication-notice publication-images-notice"
                                         id="publicationImagesNotice" role="status">
                                        <span class="publication-notice-text"></span>
                                        <button type="button" class="btn-close publication-notice-close"
                                                aria-label="Скрыть уведомление"></button>
                                    </div>
                                    <div class="bg-primary-subtle px-3 py-2 mt-1 rounded-2 text-break d-none publication-notice publication-files-notice"
                                         id="publicationImageFilesNotice" role="status">
                                        <span class="publication-notice-text"></span>
                                        <button type="button" class="btn-close publication-notice-close"
                                                aria-label="Скрыть уведомление"></button>
                                    </div>
                                    <div class="stacked-images publication-album-strip mt-2 d-none" id="publicationImagesPreview"></div>
                                    <?php /* The slider a click on the strip above opens: the shape of
                                            carousel.html, standing empty until the album of this frame
                                            fills it. Its id and the data-bs-target of its own controls
                                            are written when it opens — a part block is cloned with a
                                            slider of its own, so two open ones never share a name. */ ?>
                                    <div class="carousel slide publication-album-carousel mt-2 d-none">
                                        <div class="carousel-indicators"></div>
                                        <div class="carousel-inner"></div>
                                        <button class="carousel-control-prev" type="button" data-bs-slide="prev">
                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                            <span class="visually-hidden">Предыдущее изображение</span>
                                        </button>
                                        <button class="carousel-control-next" type="button" data-bs-slide="next">
                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                            <span class="visually-hidden">Следующее изображение</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Link buttons -->
                            <div class="mb-3">
                                <?php /* The frame of the buttons: the same bordered card a column of the
                                        board in ui-kit/tasks.html puts under each of its items. */ ?>
                                <div class="kanban-item publication-field-frame p-3 rounded-2 bg-white">
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
                                            any later part has, so one rule names its rows and one icon adds them.
                                            The class of the label tells it apart from the label of the switch
                                            that stands over the box of a part — the two are in one frame, and
                                            only the label of the box points at the rows. */ ?>
                                    <div class="publication-button-box" id="publicationButtonBox">
                                        <label class="form-label mb-1 publication-buttons-label" for="publicationButtonText-0"
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
                                                    <?php /* The label of a button is an editor of its own: the
                                                            panel above the box it paints into, the box over a
                                                            field the form submits. The panel of a label carries
                                                            no formats — the channel takes the text of a button
                                                            as it stands — so the one control of it is the button
                                                            of an emoji, and an emoji is a character the label
                                                            keeps. A label is one line: Enter does nothing in it,
                                                            as in a heading. */ ?>
                                                    <div class="publication-editor publication-button-label-editor">
                                                        <textarea class="form-control publication-button-text d-none"
                                                                  id="publicationButtonText-0"
                                                                  name="publicationButtonText[]"
                                                                  tabindex="-1" aria-hidden="true"
                                                                  placeholder="Надпись кнопки"></textarea>
                                                        <div class="publication-editor-field"></div>
                                                    </div>
                                                </div>
                                                <div class="col-sm">
                                                    <input type="text" class="form-control publication-button-url"
                                                           id="publicationButtonUrl-0" name="publicationButtonUrl[]"
                                                           maxlength="2048" placeholder="https://example.com/poll">
                                                </div>
                                                <div class="col-sm-auto d-none">
                                                    <button type="button"
                                                            class="btn btn-outline-secondary btn-icon publication-button-up"
                                                            aria-label="Поднять кнопку-ссылку выше"
                                                            title="Поднять эту кнопку на строку выше">
                                                        <i class="bi bi-arrow-up-short"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-outline-secondary btn-icon publication-button-down"
                                                            aria-label="Опустить кнопку-ссылку ниже"
                                                            title="Опустить эту кнопку на строку ниже">
                                                        <i class="bi bi-arrow-down-short"></i>
                                                    </button>
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
                                <?php /* A part saved by this button stays in the block it was taken
                                       out of, so the button only makes sense while the form carries a
                                       group: it shows itself with the group id and goes with it. */ ?>
                                <button type="submit" name="action" value="home"
                                        class="btn btn-outline-primary d-none"
                                        id="publicationSaveInPlaceBtn"
                                        title="Обновить каждую часть там, где она лежит">
                                    <i class="bi bi-pin-angle me-1"></i>Сохранить по местам
                                </button>
                                <?php /* Back to the blank state the page opens in, by hand instead of
                                       after a save. The record form taken over is dropped with it: a
                                       field left standing would write over that record. */ ?>
                                <button type="button" class="btn btn-outline-danger" id="publicationClearBtn"
                                        title="Освободить форму: текст, части, изображения, кнопки и дату — как после сохранения">
                                    <i class="bi bi-x-circle me-1"></i>Очистить
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
        <div class="card mb-4 publication-list-block">
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
        <div class="card mb-4 publication-list-block">
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
        <div class="card mb-4 publication-list-block">
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

<!-- Emoji panel: the rich popover of the ui-kit, moved to the body, where nothing
     can shadow the viewport it is placed against. Only its placement is ours; the
     card, its header and its arrow are the styles of `.popover`/`.popover-rich`. -->
<div class="publication-emoji-panel popover popover-rich bs-popover-auto d-none"
     id="publicationEmojiPanel" data-popper-placement="bottom">
    <div class="popover-arrow" id="publicationEmojiArrow"></div>
    <h3 class="popover-header"><i class="bi bi-emoji-smile"></i>Emoji</h3>
    <div class="popover-body">
        <div class="p-1">
            <div class="publication-emoji-head">
                <input type="text" class="form-control form-control-sm" id="publicationEmojiQuery"
                       autocomplete="off" placeholder="Поиск emoji" aria-label="Поиск emoji">
                <div class="publication-emoji-categories" id="publicationEmojiCategories"></div>
            </div>
            <div class="publication-emoji-grid" id="publicationEmojiGrid"></div>
        </div>
    </div>
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
$albumSwitchSaveUrl = \yii\helpers\Url::to(['site/album-rows-save']);
$partsPublicationSaveUrl = \yii\helpers\Url::to(['site/parts-publication-save']);
$buttonsPerPartSaveUrl = \yii\helpers\Url::to(['site/buttons-per-part-save']);
$distributeImagesSaveUrl = \yii\helpers\Url::to(['site/distribute-images-save']);
$numberPartsSaveUrl = \yii\helpers\Url::to(['site/number-parts-save']);
$headingNumberTemplateSaveUrl = \yii\helpers\Url::to(['site/heading-number-template-save']);
$headingFormattingSaveUrl = \yii\helpers\Url::to(['site/heading-formatting-save']);
$textOrderSaveUrl = \yii\helpers\Url::to(['site/forum-text-order-save']);
$partGroupUrl = \yii\helpers\Url::to(['site/publication-part-group']);
// The three addresses a part of the form is moved by: the two the lists already post
// to, and the one that writes the first row of a part nothing was saved under yet.
$toDraftUrl = \yii\helpers\Url::to(['site/publication-to-draft']);
$deleteUrl = \yii\helpers\Url::to(['site/publication-delete']);
$partDraftUrl = \yii\helpers\Url::to(['site/publication-part-draft']);
$blockTotals = json_encode($totals);
// The six shapes of a numbered heading reach the page the same way the totals of the block
// do: held once by the service, encoded once, interpolated once. The page mirrors the
// preset of the two rows into the field, and both rows are drawn back out of the shape the
// session holds — none of that asks the server again.
$headingNumberPresets = PublicationSettingsService::HEADING_NUMBER_PRESETS;
$headingNumberPresetsJson = json_encode($headingNumberPresets, JSON_UNESCAPED_UNICODE);
// The page tunes its own behaviour through the settings storage: what the
// scroll waits for, how long a picture may think, which step the minutes of
// the picker take, how far the parts of one publication stand from each other,
// which format of date the reader reads and how big the files the album picks
// from a computer may be.
$scrollEdge = (int)$settings['scrollEdgePx'];
$probeTimeout = (int)$settings['imageProbeTimeoutMs'];
$snapRange = (int)$settings['splitSnapRangeChars'];
$minuteStep = (int)$settings['scheduleMinuteStep'];
$horizonHours = (int)$settings['scheduleHorizonHours'];
$previewLimit = (int)$settings['imagesPreviewLimit'];
$uploadMaxMb = (int)$settings['imageUploadMaxMb'];
$uploadLimit = (int)$settings['imageUploadLimit'];
$partsOffset = (int)$settings['partsOffsetMinutes'];
$numberingReserve = ChannelService::PARTS_NUMBERING_RESERVE;
// The longest album the channel takes as one message: the preview of a longer one
// shows this many tiles and counts the rest behind «+N», because the sender cuts
// the pictures into media groups of the same size.
$albumMax = ChannelService::ALBUM_MAX_PHOTOS;
$pickerFormat = PublicationSettingsService::DATE_FORMATS[$settings['dateFormat']];
$this->registerJs(
    "var __FILTER_SAVE_URL = '{$filterSaveUrl}';
var __PAGE_URL = '{$pageUrl}';
var __POST_PAGE_URL = '{$postPageUrl}';
var __THREAD_URL = '{$threadUrl}';
var __SORT_URL = '{$sortUrl}';
var __TITLE_SWITCH_URL = '{$titleSwitchSaveUrl}';
var __LINKS_SWITCH_URL = '{$linksSwitchSaveUrl}';
var __ALBUM_SWITCH_URL = '{$albumSwitchSaveUrl}';
var __PARTS_PUBLICATION_SAVE_URL = '{$partsPublicationSaveUrl}';
var __BUTTONS_PER_PART_SAVE_URL = '{$buttonsPerPartSaveUrl}';
var __DISTRIBUTE_IMAGES_URL = '{$distributeImagesSaveUrl}';
var __NUMBER_PARTS_URL = '{$numberPartsSaveUrl}';
var __HEADING_TEMPLATE_URL = '{$headingNumberTemplateSaveUrl}';
var __HEADING_FORMATTING_URL = '{$headingFormattingSaveUrl}';
var __TEXT_ORDER_URL = '{$textOrderSaveUrl}';
var __PART_GROUP_URL = '{$partGroupUrl}';
var __TO_DRAFT_URL = '{$toDraftUrl}';
var __DELETE_URL = '{$deleteUrl}';
var __PART_DRAFT_URL = '{$partDraftUrl}';
var __BLOCK_TOTALS = {$blockTotals};
var __NUMBER_PRESETS = {$headingNumberPresetsJson};
var __CSRF_PARAM = '{$csrfParam}';
var __CSRF_TOKEN = '{$csrfToken}';
var __TEXT_PART_LIMIT = {$textLimit};
var __BUTTON_LIMIT = {$buttonLimit};
var __BUTTON_LABEL_BYTES = {$buttonLabelBytes};
var __KEYBOARD_MAX_ROWS = {$keyboardMaxRows};
var __NUMBERING_RESERVE = {$numberingReserve};
var __ALBUM_MAX = {$albumMax};
var __SCROLL_EDGE = {$scrollEdge};
var __IMAGE_PROBE_TIMEOUT = {$probeTimeout};
var __SNAP_RANGE = {$snapRange};
var __MINUTE_STEP = {$minuteStep};
var __HORIZON_HOURS = {$horizonHours};
var __PARTS_OFFSET = {$partsOffset};
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

// The number a heading of a part wears, as the field of the heading holds it: the twin
// of the line above, which the same number makes inside the text of the part. The pair
// of radios under the switch of the numbering answers WHICH end of the heading carries
// it, so the field is read at both of its ends. Behind the words the dot the heading
// ends with stands as the separator, and that dot is what tells a number the page wrote
// there from words a person typed themselves: «Текст Часть 2» holds no separator and is
// left to the person who wrote it.
// The number a heading of a part wears is written by one string of two tokens, and both of
// the rows of radios under the switch of the numbering are drawn from that one string: the
// six shapes of the table the server hands are words of the same shape as the words a
// reader writes into the field under them. One hand writes every shape and one hand reads
// it back, so a rebuild over a field numbered by another shape takes that shape off first.
var HEADING_NUMBER = /^Часть \d+[.:]?\s*/;
var HEADING_NUMBER_END = /\.\s*Часть \d+\s*$/;
var NUMBER_TEMPLATE_NUMBER = '#NUMBER#';
var NUMBER_TEMPLATE_TEXT = '#TITLE_TEXT#';

// The seven controls of the numbering are looked for once, when the form stands in the DOM,
// and are kept here: the pass over the headings asks the shape for every one of them, and a
// helper of this scope cannot reach into the scope of the form.
var headingNumberBeforeInput = null;
var headingNumberAfterInput = null;
var headingNumberDotInput = null;
var headingNumberDashInput = null;
var headingNumberBracketInput = null;
var headingNumberCustomInput = null;
var headingNumberTemplateInput = null;

function headingNumberAtEnd() {
    return !!(headingNumberAfterInput && headingNumberAfterInput.checked);
}

// The row of the separator answers with its own radio, and a form that holds none of the
// three says the dot: that is the shape the page wrote before the row grew, and it is what
// a page which was told nothing keeps writing.
function headingNumberStyle() {
    if (headingNumberDashInput && headingNumberDashInput.checked) {
        return 'dash';
    }

    if (headingNumberBracketInput && headingNumberBracketInput.checked) {
        return 'bracket';
    }

    return 'dot';
}

// The checkbox is what makes the words of the field a template at all. With it off the
// field only mirrors the preset of the two rows, and the words a reader left in it answer
// nothing.
function headingNumberCustomOn() {
    return !!(headingNumberCustomInput && headingNumberCustomInput.checked);
}

// One preset of the table the server hands, and the preset of the head and the dot for a
// place or a separator the rows were never told to stand on.
function numberTemplateFor(place, style) {
    var row = __NUMBER_PRESETS[place];

    return (row && row[style]) || __NUMBER_PRESETS.before.dot;
}

// The shape the form stands on: the words of the field while the checkbox is on and holds
// both of its tokens, and the preset of the two rows in every other case — a field that
// forgot where the words of a heading go is no template of a number, and the rows answer
// nothing once the field owns the shape.
function activeNumberTemplate() {
    var held = headingNumberTemplateInput ? String(headingNumberTemplateInput.value || '') : '';

    if (headingNumberCustomOn()
        && held.indexOf(NUMBER_TEMPLATE_NUMBER) >= 0
        && held.indexOf(NUMBER_TEMPLATE_TEXT) >= 0) {
        return held;
    }

    return numberTemplateFor(headingNumberAtEnd() ? 'after' : 'before', headingNumberStyle());
}

// Every shape a number is read out of: the shape of the reader before the six, because a
// heading the field wrote wears the field's own shape, and the six in the order of the
// table. With the checkbox off the field mirrors one of the six, and with a field that
// forgot a token the rows answer one of them too — a shape is never read twice.
function headingNumberTemplates() {
    var list = [__NUMBER_PRESETS.before.dot, __NUMBER_PRESETS.before.dash,
        __NUMBER_PRESETS.before.bracket, __NUMBER_PRESETS.after.dot,
        __NUMBER_PRESETS.after.dash, __NUMBER_PRESETS.after.bracket];
    var own = activeNumberTemplate();

    if (!headingNumberCustomOn()) {
        return list;
    }

    return [own].concat(list.filter(function (shape) {
        return shape !== own;
    }));
}

// One template turned back into the words it writes: the number of the part stands for
// digits, the words of the heading stand for the whole of the field, and the blanks of the
// shape are optional, because a heading that holds nothing but its number is written
// without the blank that joined the words. Every other character is the character it is —
// a bracket is a bracket and the mark that joins the number to the words is what tells a
// number the page wrote there from words a person typed themselves. A string that holds
// only one of the two tokens reads nothing.
function numberTemplateMatcher(template) {
    var shape = String(template || '');

    if (shape.indexOf(NUMBER_TEMPLATE_NUMBER) < 0 || shape.indexOf(NUMBER_TEMPLATE_TEXT) < 0) {
        return null;
    }

    var body = shape.split(NUMBER_TEMPLATE_TEXT).map(function (chunk) {
        return chunk.split(NUMBER_TEMPLATE_NUMBER).map(function (piece) {
            return piece.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s*');
        }).join('(?:\\d+)');
    }).join('([\\s\\S]*)');

    return new RegExp('^' + body + '$');
}

// The words a heading holds under its number, read through that matcher: nothing for a
// heading of another shape, and the empty string for a heading of nothing but a number.
// The blank that joined the words to the number is the shape's, not the reader's, so it
// comes off with the number — a heading stripped to a trailing space would be written
// again with that space standing between the words and the mark of the new shape.
function numberTemplateCaptures(title, template) {
    var matcher = numberTemplateMatcher(template);
    var match = matcher ? String(title || '').match(matcher) : null;

    return match === null ? null : match[1].replace(/^\s+|\s+$/g, '');
}

// The hand every shape is written by, the six presets and the words of the reader alike.
// The dot a heading of the parts is stored with is the mark that joins it to its number, so
// a shape that puts a mark behind the words takes that dot in its place, and a shape that
// puts a word behind them, or nothing at all, leaves it alone. Where the words are not
// there, the marks at the edges of the shape go with them: a dot that CLOSES the number of
// a head shape stays, because it never joined the words to anything.
function renderNumberTemplate(template, index, text) {
    var shape = String(template || '');
    var words = String(text || '');
    var at = shape.indexOf(NUMBER_TEMPLATE_TEXT);
    var next = at < 0 ? '' : shape.slice(at + NUMBER_TEMPLATE_TEXT.length).replace(/^\s+/, '').charAt(0);
    var joined = next !== '' && !/[0-9A-Za-zА-Яа-яЁё#]/.test(next);
    var written = words === ''
        ? words
        : (joined ? words.replace(/\.\s*$/, '') : words);
    var out = shape.split(NUMBER_TEMPLATE_NUMBER).join(String(index + 1))
        .split(NUMBER_TEMPLATE_TEXT).join(written);

    if (words !== '') {
        return out;
    }

    out = out.replace(/^\s+|\s+$/g, '').replace(/^[-–:.]\s*/, '').replace(/\s*[-–:]$/, '');

    return out.replace(/^\s+|\s+$/g, '');
}

// What a heading of a numbered part held, without its number: the shape the form stands on
// first, because that is the shape the page wrote the field with, and the two matches the
// page made before the rows grew behind it — so a heading numbered before they existed
// loses that number too.
function stripHeadingNumber(title) {
    var bare = String(title || '');
    var shapes = headingNumberTemplates();

    for (var i = 0; i < shapes.length; i += 1) {
        var words = numberTemplateCaptures(bare, shapes[i]);
        if (words !== null) {
            return words;
        }
    }

    return bare
        .replace(HEADING_NUMBER, '')
        .replace(HEADING_NUMBER_END, '');
}

// The heading of a part with the number of its place in the shape the form stands on, or
// with no number at all: the number is written from the place of the part over whatever the
// field held, so a rebuild never piles a second copy of it on, and the click that takes the
// numbers off takes them off the very fields they were written into. A heading of nothing
// else is its number in either of the two shapes.
function numberHeading(title, index, on) {
    var bare = stripHeadingNumber(title);

    if (!on) {
        return bare;
    }

    return renderNumberTemplate(activeNumberTemplate(), index, bare).replace(/\s+$/, '');
}

function numberHeadings(titles, on) {
    return (titles || []).map(function (title, index) {
        return numberHeading(title, index, on);
    });
}

// The heading a part goes out with and the text under it: the heading of a numbered
// part stands at the head of its message, and the line of the number the text opens
// with only leaves the body — it goes to the heading a second time only while the
// heading holds no number of its own, which is how a part went out before the number
// was written into the field of the heading.
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
        heading: HEADING_NUMBER.test(heading)
            ? heading
            : heading + '. ' + prefix.replace(/\s+$/, ''),
        gap: text.length > prefix.length ? '\n\n' : '',
        body: text.slice(prefix.length),
        moved: prefix.length
    };
}

// The same highlighting lying under a heading of its own: every span of the body
// moves behind the heading and over the line that separates the two, less the
// number the heading was built from. The heading wears the list it was handed, and
// the composition marks a heading of its own no more: since this revision the whole
// line of an unmarked heading is bolded by the block of the heading, whose boxes are
// read by the hands that call this one. A heading that carries nothing therefore
// goes out plain, and the two shapes still share the arithmetic of the body — a mark
// of a heading is counted from its beginning, so it needs no shift of its own.
function entitiesUnderHeading(list, heading, gap, moved, own) {
    if (heading === '') {
        return list.slice();
    }

    return sortEntities(
        shiftEntities(own || [], 0)
            .concat(shiftEntities(list, heading.length + gap.length - moved))
    );
}

// The marks the block of the heading lays over a heading: a box standing checked
// covers the whole line of it, and the marks come out in the order the channel lists
// the spans of one and the same piece of text. A name the block does not carry lays
// nothing, and so does a heading with no words in it.
function headingBlockEntities(heading, formats) {
    if (heading === '') {
        return [];
    }

    var marks = [];

    (formats || []).forEach(function (format) {
        var type = ENTITY_OF_FORMAT[format];

        if (type) {
            marks.push(makeEntity(type, 0, heading.length));
        }
    });

    return sortEntities(marks);
}

// Which marks a heading of the form goes out with: the marks the reader set over a
// heading win, and a heading he left unmarked wears the boxes of the block. The stamp
// is counted over the words the server takes for the heading — its own ends trimmed
// off — so a heading of spaces, which is no heading at all, is marked by nothing.
function titleMarksOf(title, own, formats) {
    var marks = own || [];

    if (marks.length > 0) {
        return marks;
    }

    return headingBlockEntities(String(title || '').trim(), formats);
}

// The marks of a heading grown over the line the heading goes out as: a numbered part
// hands the number from the head of its body up into that line, so the line is longer
// than the words of the field by exactly that number, and a span that stood from the
// beginning of the field to its end is stood to the end of the line. A span the reader
// stopped short of the end claims the same piece of the line he always claimed, and a
// heading whose line gained nothing keeps the marks it was saved with. The widening
// belongs to the way out and never to the field: the list a record carries is measured
// against the words of the field, and a mark over a number the field does not hold is
// a record the server refuses.
function widenToHeading(marks, title, heading) {
    var units = String(title || '').trim().length;

    if (heading.length <= units) {
        return marks;
    }

    return marks.map(function (mark) {
        if (mark.offset !== 0 || mark.length !== units) {
            return mark;
        }

        return makeEntity(mark.type, 0, heading.length, mark.url);
    });
}

// The message a part goes to the channel as, and its highlighting: what the
// preview shows and the counters count is the text the server composes, so a
// heading is never a decoration the posted message does not carry. The boxes of
// the heading come in as the last list a caller hands: the two readers of the
// message ask for them themselves, which is what makes the preview and the counters
// say the very thing the submitted fields will say.
function composedMessage(title, text, entities, titleEntities, formats) {
    var cut = splitHeading(title, text);

    if (cut.heading === '') {
        return {text: text, entities: (entities || []).slice()};
    }

    return {
        text: cut.body === '' ? cut.heading : cut.heading + cut.gap + cut.body,
        entities: entitiesUnderHeading(
            entities || [], cut.heading, cut.gap, cut.moved,
            widenToHeading(titleMarksOf(title, titleEntities || [], formats), title, cut.heading))
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

// The font of the machine fails at two different things, and each asks its own
// question. What it cannot compose it draws as the separate glyphs a joiner
// (U+200D) stands between, and those together are near twice the width of one
// emoji: half again is the line the measurement leaves between the two answers,
// since the widest figure the font really does draw alone — a family of four —
// comes to 1.41 of an emoji, while the sets it splits begin at 1.64. Only the
// sequences carrying a joiner are measured, because every answer of the table
// wider than one glyph carries one, and the rest, the two-letter flags among it,
// is known to fit. A symbol the font holds no figure for at all is the other
// failure, and no width tells of it: the box of the last resort is exactly as wide
// as the emoji it stands for. `missing` is that question, asked of the ink. Both
// go to the font and not to a list of exclusions: a newer font answers them the
// other way and gives back what this one loses.
function emojiSupported(list, measure, single, missing) {
    var limit = single * 1.5;

    return list.filter(function (item) {
        if (item.emoji.indexOf('\u200D') >= 0 && measure(item.emoji) > limit) {
            return false;
        }

        return !missing(item.emoji);
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

// What a click on a picture of the album strip asks for: `open` is the album
// whose slider the page shows and the slide it stands on, or null while no
// slider is open. Another album opens its own slider, another picture of the
// album already open slides it, and the picture the slider is on hides it.
function stripAction(open, clicked) {
    if (open === null || open.album !== clicked.album) {
        return 'open';
    }

    return open.index === clicked.index ? 'close' : 'slide';
}

// What a part of the form shows the preview as: a part carries a message when it
// has words of its own or an album of its own, because a picture without a
// caption is a post Telegram accepts. A part with neither is nothing to show.
function partIsShown(text, pictures) {
    return text !== '' || pictures.length > 0;
}

// How the channel lays an album of N pictures out: the tiles it really shows,
// whether the first of them lies across both columns on top of the rest (three
// pictures, and four — where the ones under it are three, not two), and how many
// stay behind the «+N» of the last one. Only one media group bounds the tiles:
// the sender cuts a longer album into albums of its own, which is a second
// message this bubble has no place for.
function albumLayout(count) {
    if (count <= 1) {
        return { shown: count, wide: false, extra: 0 };
    }

    if (count >= 3 && count <= 6) {
        return { shown: count, wide: true, extra: 0 };
    }

    // Two pictures and every album longer than six stand on the base grid of the
    // sheet: two columns of square tiles, as many rows as the album asks for. The
    // channel has been measured at three, four, five and six only, so a longer
    // album keeps the tiles of the sheet rather than a collage nobody has read yet.
    var shown = count < __ALBUM_MAX ? count : __ALBUM_MAX;

    return { shown: shown, wide: false, extra: count - shown };
}

// --- the order of an album: its links, its files, and one place for each ----
//
// An album of the form is two fields kept apart: the URLs a reader typed into
// its text area and the files its picker holds. The channel receives the links
// first and the stored files after them, so an order that reaches across both —
// a photo picked from disk standing before a typed address — has nowhere to
// travel. The mask is that place: one token per picture of the album, in the
// order the album shows them, naming which of the two fields the picture comes
// from. Both fields keep their own order, the mask only weaves them together,
// and the server weaves the same way after it has turned every file into a link.
var ALBUM_LINK_TOKEN = 'l';
var ALBUM_FILE_TOKEN = 'f';

function isAlbumToken(token) {
    return token === ALBUM_LINK_TOKEN || token === ALBUM_FILE_TOKEN;
}

// The order nothing has been moved in: every link of the field, then every file
// of the picker — which is also exactly what the server does without a mask.
function plainAlbumMask(links, files) {
    return (links || []).map(function () {
        return ALBUM_LINK_TOKEN;
    }).concat((files || []).map(function () {
        return ALBUM_FILE_TOKEN;
    }));
}

// Whether a mask can be trusted over an album: it may name fewer pictures than
// the album holds — a file picked or a line typed after it was written stands
// behind the rest — but one that names more, or carries a token from another
// page, is not this album's pattern and is let go of rather than argued with.
// The plain order is what the reader saw before any of the rows existed.
function isAlbumMask(mask, count) {
    return Array.isArray(mask) && mask.length <= count && mask.every(isAlbumToken);
}

// The album as the rows of the form see it: the two fields woven together by
// the mask, every picture named once. A token whose field has run out is passed
// over, and what no token named comes after the rest in the plain order.
function orderAlbum(links, files, mask) {
    var urls = (links || []).slice();
    var picks = (files || []).slice();
    var pattern = isAlbumMask(mask, urls.length + picks.length)
        ? mask
        : plainAlbumMask(urls, picks);
    var pictures = [];

    function take(pool, kind) {
        if (pool.length === 0) {
            return false;
        }
        pictures.push({ kind: kind, value: pool.shift() });

        return true;
    }

    pattern.forEach(function (token) {
        take(token === ALBUM_LINK_TOKEN ? urls : picks,
            token === ALBUM_LINK_TOKEN ? 'link' : 'file');
    });

    // The pictures no token named, in the order their own field keeps them.
    while (urls.length > 0) {
        take(urls, 'link');
    }
    while (picks.length > 0) {
        take(picks, 'file');
    }

    return pictures;
}

// The two sides of the same list, read back out of it: the fields the form
// submits are derived from the order, never kept beside it.
function splitAlbum(pictures) {
    var parts = { links: [], files: [] };

    (pictures || []).forEach(function (picture) {
        parts[picture.kind === 'link' ? 'links' : 'files'].push(picture.value);
    });

    return parts;
}

function maskOfAlbum(pictures) {
    return (pictures || []).map(function (picture) {
        return picture.kind === 'link' ? ALBUM_LINK_TOKEN : ALBUM_FILE_TOKEN;
    });
}

// A picture swaps places with its neighbour, the way a row of buttons does. The
// list it was given is left alone, because the fields of the form are rewritten
// from the answer and an in-place move would leave them holding half of it.
function moveAlbumPicture(pictures, index, step) {
    var list = (pictures || []).slice();
    var to = index + step;

    if (to < 0 || to >= list.length) {
        return list;
    }
    list.splice(to, 0, list.splice(index, 1)[0]);

    return list;
}

function removeAlbumPicture(pictures, index) {
    var list = (pictures || []).slice();

    if (index < 0 || index >= list.length) {
        return list;
    }
    list.splice(index, 1);

    return list;
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

    // The switch of the numbering changes a great deal of the form and nothing of the
    // lists: the numbers it writes live in the fields the reader is typing into, so the
    // server only stores its state and answers with the address that mirrors it. The
    // click itself renumbers the parts through the listener the form binds, so a save
    // that came back asks no renumbering — only the hand that puts the switch back has
    // to give the fields over again, or the form would stand numbered while the session
    // says it is not.
    function watchNumberPartsSwitch(renumberParts) {
        var input = document.getElementById('publicationNumberParts');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var state = input.checked;
            postForJson(__NUMBER_PARTS_URL, { numberParts: state ? '1' : '0' })
                .then(function (payload) {
                    mirrorUrl(payload);
                })
                .catch(function (error) {
                    // The session kept the state it was asked with, so the switch has
                    // to keep the one it was rendered with — and the parts the click
                    // renumbered have to follow it back.
                    input.checked = !state;
                    renumberParts();
                    showFlash('error', 'Не удалось запомнить переключатель: '
                        + (error && error.message ? error.message : error));
                });
        });
    }

    // The shape of a number is one string the session holds, and seven controls answer it:
    // the two radios of the place, the three of the separator, the checkbox that hands the
    // shape to the field and the field itself. Every one of them posts both of the states —
    // the flag of the checkbox and the words of the field — because either half alone would
    // leave the session holding a shape the form does not show. The click renumbers the
    // parts first, so the gate has already written the preset of the new rows into the field
    // by the time the watcher reads it, and a refusal puts the whole of the shape back over
    // the words the click moved: which radio of each row stood is part of what the session
    // agreed to, and the headings have to follow it back.
    function watchHeadingTemplateSwitch(renumberParts) {
        var inputs = [headingNumberBeforeInput, headingNumberAfterInput, headingNumberDotInput, headingNumberDashInput, headingNumberBracketInput, headingNumberCustomInput, headingNumberTemplateInput];
        if (!inputs.every(function (input) { return !!input; })) {
            return;
        }

        // The shape the session holds: the gate and the words on one side, and which radio of
        // each of the two rows stood when the shape was drawn on the other. A refusal puts all
        // of it back, because the click that moved a radio moved the words the field mirrors.
        function snapshot() {
            return {
                custom: headingNumberCustomInput.checked,
                template: headingNumberTemplateInput.value,
                after: headingNumberAfterInput.checked,
                dash: headingNumberDashInput.checked,
                bracket: headingNumberBracketInput.checked,
            };
        }

        var held = snapshot();
        inputs.forEach(function (input) {
            input.addEventListener('change', function () {
                renumberParts();
                var custom = headingNumberCustomInput.checked;
                var template = headingNumberTemplateInput.value;
                postForJson(__HEADING_TEMPLATE_URL, { headingNumberCustom: custom ? '1' : '0', headingNumberTemplate: template })
                    .then(function (payload) {
                        held = snapshot();
                        mirrorUrl(payload);
                    })
                    .catch(function (error) {
                        headingNumberBeforeInput.checked = !held.after;
                        headingNumberAfterInput.checked = held.after;
                        headingNumberDotInput.checked = !(held.dash || held.bracket);
                        headingNumberDashInput.checked = held.dash;
                        headingNumberBracketInput.checked = held.bracket;
                        headingNumberCustomInput.checked = held.custom;
                        headingNumberTemplateInput.value = held.template;
                        renumberParts();
                        showFlash('error', 'Не удалось запомнить переключатель: '
                            + (error && error.message ? error.message : error));
                    });
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

    // The switch that lays the album of the form out in rows keeps its state the
    // same way the two switches above it do: the server stores it and answers with
    // the address that mirrors it. The rows the page lays out on the change of the
    // switch by itself, so only a save that was refused has to bring the album back
    // to the state it was rendered with — and `sync` is that layout, which lives in
    // the scope of the form.
    function watchAlbumRowsSwitch(sync) {
        var input = document.getElementById('publicationAlbumRows');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var state = input.checked;
            postForJson(__ALBUM_SWITCH_URL, { albumRows: state ? '1' : '0' })
                .then(function (payload) {
                    mirrorUrl(payload);
                })
                .catch(function (error) {
                    // The session kept the state it was asked with, so the switch
                    // goes back to the one it was rendered with — and the album
                    // with it, since a checkbox that never was turned lays nothing
                    // out on its own.
                    input.checked = !state;
                    sync();
                    showFlash('error', 'Не удалось запомнить переключатель: '
                        + (error && error.message ? error.message : error));
                });
        });
    }

    // The switch that hands the albums of the form out over its parts keeps its state the
    // way the switch above it does: the server stores it and answers with the address that
    // mirrors it. The cut the page makes on its own, and only when the switch came up:
    // thrown off, the mode leaves the arrangement the reader has where it stands. `turnOn`
    // is that cut, which lives in the scope of the form.
    function watchDistributeImagesSwitch(turnOn) {
        var input = document.getElementById('publicationDistributeImages');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var state = input.checked;
            postForJson(__DISTRIBUTE_IMAGES_URL, { distributeImages: state ? '1' : '0' })
                .then(function (payload) {
                    mirrorUrl(payload);
                    if (state) {
                        turnOn();
                    }
                })
                .catch(function (error) {
                    // The refused save cuts nothing: a switch that never was thrown off
                    // leaves the albums of the form alone, and one that never was thrown
                    // on has nothing to hand out.
                    input.checked = !state;
                    showFlash('error', 'Не удалось запомнить переключатель: '
                        + (error && error.message ? error.message : error));
                });
        });
    }

    // The switch that gives every part its own publication time and its own
    // button keeps its state the way the switches above it do: the server stores
    // it and answers with the address that mirrors it. The rows of the parts the
    // page draws on the change of the switch by itself, so only a save the server
    // refused has to bring them back — and `sync` is that drawing, which lives in
    // the scope of the form.
    function watchPartsPublicationSwitch(sync) {
        var input = document.getElementById('publicationPartsIndividually');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var state = input.checked;
            sync();
            postForJson(__PARTS_PUBLICATION_SAVE_URL, { partsPublication: state ? '1' : '0' })
                .then(function (payload) {
                    mirrorUrl(payload);
                })
                .catch(function (error) {
                    // The session kept the state it was asked with, so the switch
                    // goes back to the one it was rendered with — and the rows of
                    // the parts go back with it.
                    input.checked = !state;
                    sync();
                    showFlash('error', 'Не удалось запомнить переключатель: '
                        + (error && error.message ? error.message : error));
                });
        });
    }

    // The switch that gives every part its own block of link buttons keeps its state
    // the way the switches around it do: the server stores it and answers with the
    // address that mirrors it. Which block answers for which part is the page's own
    // doing, and `sync` is that drawing.
    function watchButtonsPerPartSwitch(sync) {
        var input = document.getElementById('publicationButtonsPerPart');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var state = input.checked;
            sync();
            postForJson(__BUTTONS_PER_PART_SAVE_URL, { buttonsPerPart: state ? '1' : '0' })
                .then(function (payload) {
                    mirrorUrl(payload);
                })
                .catch(function (error) {
                    // The session kept the state it was asked with, so the switch goes
                    // back — and the blocks of the parts go back with it.
                    input.checked = !state;
                    sync();
                    showFlash('error', 'Не удалось запомнить переключатель: '
                        + (error && error.message ? error.message : error));
                });
        });
    }

    // The height the block of the forum topics is written in depends on the
    // header of the layout, and CSS reads no neighbour: that header takes the
    // height of whatever its breakpoint leaves standing in it — the 46px brand
    // mark below `lg`, the search field and the avatar above it — so it is
    // measured off the live element and handed to the rule as a variable.
    function syncAppHeaderHeight() {
        var header = document.querySelector('.app-header');
        if (!header) {
            return;
        }

        document.documentElement.style.setProperty(
            '--app-header-height',
            header.getBoundingClientRect().height.toFixed(2) + 'px'
        );
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
        function makePopover(el) {
            var Popover = window.bootstrap && window.bootstrap.Popover;
            if (!Popover) {
                return;
            }

            // The script of the kit hands a popover to every trigger like this
            // one, with its own settings, and it runs before this page does; an
            // instance that already exists keeps the settings it was built with
            // and drops the ones asked for now, so it is let go first. The hint
            // itself is read when the instance is made, so a trigger whose hint
            // was rewritten has to be handed over again.
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
        }

        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
            makePopover(el);
        });

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
        // The rows that grow a new part stand in the group around the parts: the two
        // of them that name the ends of the list are outside the box the parts are
        // rebuilt in, so their clicks are caught one level above it.
        var partsGroup = partsBox.closest('.publication-parts-group');
        // The first field is the template the extra parts are cloned from. It is
        // never replaced itself, so `source` stays a valid reference.
        var partTemplate = partsBox.querySelector('.publication-text-block').cloneNode(true);
        // The row of the shared field is the template of a new button: the markup
        // draws it blank, and a clone of it is what adding appends to a box.
        var buttonRowTemplate = buttonRowsOf(sharedButtonBox)[0].cloneNode(true);
        var numberPartsInput = document.getElementById('publicationNumberParts');
        // The seven controls of the numbering are remembered where the number of a heading
        // is written: the pass over the headings asks the shape which end wears it and with
        // what the number is joined, for every one of the parts.
        headingNumberBeforeInput = document.getElementById('publicationHeadingNumberBefore');
        headingNumberAfterInput = document.getElementById('publicationHeadingNumberAfter');
        headingNumberDotInput = document.getElementById('publicationHeadingNumberDot');
        headingNumberDashInput = document.getElementById('publicationHeadingNumberDash');
        headingNumberBracketInput = document.getElementById('publicationHeadingNumberBracket');
        headingNumberCustomInput = document.getElementById('publicationHeadingNumberCustom');
        headingNumberTemplateInput = document.getElementById('publicationHeadingNumberTemplate');
        var partsIndividualInput = document.getElementById('publicationPartsIndividually');
        var partGroupIdInput = document.getElementById('publicationPartGroupId');
        var saveInPlaceButton = document.getElementById('publicationSaveInPlaceBtn');
        var individualPartsInput = document.getElementById('publicationIndividualParts');
        var mainPublicationAt = document.getElementById('publicationAt');
        var mergeRowTemplate = partsBox.querySelector('.publication-merge-row').cloneNode(true);
        var distributeImagesInput = document.getElementById('publicationDistributeImages');
        var linksToButtonsInput = document.getElementById('publicationLinksToButtons');
        var titleFromFirstLineInput = document.getElementById('publicationTitleFromFirstLine');
        var splitButton = document.getElementById('publicationSplitPart');
        var splitModesBox = document.getElementById('publicationSplitModes');
        var buttonEveryPartInput = document.getElementById('publicationButtonEveryPart');
        var buttonsPerPartInput = document.getElementById('publicationButtonsPerPart');
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
        // The panel of a heading is the set the reader asked for: bold, italic,
        // underline, a strike, link, and the emoji button this page adds to every
        // toolbar. A `code` span stays with the body — the reader marks a heading
        // in words he chose, not in a face the channel reserves for a command.
        var TITLE_TOOLBAR_FORMATS = [['bold', 'italic', 'underline', 'strike'], ['link']];
        // The panel of a label of a link button holds no formats at all. The channel
        // takes the text of a button as it stands — `NutgramChannelClient` sends the
        // label and the address of it and no list of marks — so nothing the reader
        // could set here travels with the button. The panel is drawn for the one
        // control this page adds to every toolbar: the button of an emoji, which puts
        // a character into the label rather than a mark over it.
        var BUTTON_TOOLBAR_FORMATS = [];

        function formattingOf(field) {
            return field.__formatting || [];
        }

        function editorRootOf(field) {
            return field.parentNode ? field.parentNode.querySelector('.publication-editor-field') : null;
        }

        function formatFieldOf(field) {
            return field.parentNode ? field.parentNode.querySelector('.publication-format-field') : null;
        }

        // The labels of the rows of a box — given the document, of the whole form. A
        // label is the mirror an editor paints over and the field the form submits the
        // text of a button under.
        function buttonLabelFields(box) {
            return Array.prototype.slice.call(box.querySelectorAll('.publication-button-text'));
        }

        // A label of a button is the one field of one line that carries no marks: the
        // panel over it holds the button of an emoji and nothing else, and an address
        // pasted into it stays the text of it instead of becoming a link the channel
        // has no way to show.
        function isButtonLabel(field) {
            return field.classList.contains('publication-button-text');
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
            // A heading is one line: a break that reached it — from a record whose
            // heading was saved before this rule, or from a fill of the forum —
            // folds into a space before the text is written back through the field.
            if (field.__singleLine) {
                shown = shown.replace(/\n+/g, ' ');
            }
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

        function mountPartEditor(field, singleLine) {
            var root = editorRootOf(field);
            if (field.__editor || !root || typeof Quill !== 'function') {
                return;
            }

            field.__singleLine = !!singleLine;

            var editor = new Quill(root, {
                theme: 'snow',
                placeholder: field.placeholder || '',
                modules: {
                    toolbar: {
                        container: isButtonLabel(field)
                            ? BUTTON_TOOLBAR_FORMATS
                            : (singleLine ? TITLE_TOOLBAR_FORMATS : TOOLBAR_FORMATS),
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
                field.__emojiButton = emojiButton;
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
                if (source !== 'user') {
                    return;
                }

                // A heading is not a part a cut works on and not a block a sentence
                // moves between, so its caret never names the field those two hands
                // were told to work on.
                if (!singleLine) {
                    lastEditedField = field;
                }

                if (!range) {
                    // Only the field the popup stands over takes it away: a neighbour reports the
                    // same empty range when a drag moves the selection onto this field, and that
                    // answer arrives after this field has already painted the popup.
                    if (field === selectionField) {
                        hideSelectionActions();
                    }

                    return;
                }

                field.__caret = [range.index, range.index + range.length];
                // A selection is made with the mouse or with shift and the arrows, and the box
                // reports neither of them to the listener of `input`. The answer waits for this
                // event because it is the moment the selection is, and the popup is placed by
                // the offsets it carries: asked from a `mouseup` of the box it stands for the
                // selection of the previous click, and the scroll that ends a long drag takes
                // the popup away over the answer.
                showSelectionActions(field);
            });
            // The caret of the page lives in the editor now, so the field it was
            // typed in is the one a cut or a move works on.
            root.addEventListener('focusin', function () {
                if (!singleLine) {
                    lastEditedField = field;
                }
            });
            // The arrows and Enter belong to the panel of a colon while it is open
            // over this part. The capture phase, as for the paste: Quill's own
            // keyboard answers ArrowDown and Enter, and it must not.
            root.addEventListener('keydown', function (event) {
                // A heading is one line, so Enter has nothing to do in it: the key
                // would split the block of the editor and the split of a text reads
                // a heading as one line.
                if (singleLine && event.key === 'Enter') {
                    event.preventDefault();
                    event.stopPropagation();

                    return;
                }
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
                // Text that comes into a heading arrives with the lines of the page
                // it was copied from, and a heading keeps one: the breaks fold into
                // the spaces a typed heading would have had.
                var pasted = singleLine ? text.replace(/\s*\n+\s*/g, ' ') : text;
                var range = editor.getSelection(true);
                editor.deleteText(range.index, range.length, 'api');
                editor.insertText(range.index, pasted, 'user');

                // An address that came in with the text is an address the user
                // meant to keep, so it is read as one here too; the caret follows
                // the text back to where the paste left it. A label of a button is
                // the one field this does not happen to: the mark would stand over
                // text the channel sends plain, and the address read out of the
                // label would be a word the reader pasted and lost.
                var removed = isButtonLabel(field) ? 0 : linkifyEditor(field);
                if (removed > 0) {
                    var caret = Math.min(range.index + pasted.length - removed, field.value.length);
                    editor.setSelection(caret, 0, 'api');
                    field.__caret = [caret, caret];
                    writeMirror(field, editor.getText().replace(/\n$/, ''));
                }
            }, true);

            paintEditor(field);
        }

        function mountPartEditors() {
            textParts().forEach(function (field) { mountPartEditor(field, false); });
            titleFields().forEach(function (field) { mountPartEditor(field, true); });
            // The labels are mounted over the whole form: the box of the first part
            // stands outside the parts, and a box of a part is drawn disabled and out
            // of sight until the switch of the parts opens it.
            mountButtonLabelEditors(document);
        }

        // The four boxes of the block, in the order they stand in their row: every one
        // of them is the name the mark travels under and the id the page gives it. A
        // box the list does not carry is a box nobody clicks, so this is the only place
        // the row of the block is written down.
        var HEADING_BLOCK_BOXES = [
            ['bold', 'publicationHeadingBold'],
            ['italic', 'publicationHeadingItalic'],
            ['strike', 'publicationHeadingStrike'],
            ['underline', 'publicationHeadingUnderline']
        ];

        // The marks the block is standing with: the names of the boxes that are
        // checked, in the order of the row.
        function headingBlockFormats() {
            var marks = [];

            HEADING_BLOCK_BOXES.forEach(function (pair) {
                var box = document.getElementById(pair[1]);

                if (box && box.checked) {
                    marks.push(pair[0]);
                }
            });

            return marks;
        }

        // The hidden field of a part carries its list to the server next to the
        // text the spans are counted over.
        function writeFormattingField(field) {
            var holder = formatFieldOf(field);

            if (holder) {
                // A heading is the one field the block speaks for: its boxes are put
                // in on the way out, over a heading that carries no marks of its own.
                // The list of the editor itself is left alone, so nothing a rebuild, a
                // cut, a merge or a move reads back is a stamp the page laid itself —
                // and a box that comes off stops acting at once.
                holder.value = JSON.stringify(field.classList.contains('publication-title-field')
                    ? titleMarksOf(field.value, formattingOf(field), headingBlockFormats())
                    : formattingOf(field));
            }
        }

        function writeFormattingFields() {
            textParts().concat(titleFields()).forEach(writeFormattingField);
        }

        // The block of the heading marks nothing of the lists and a great deal of the
        // message, so its click has two hands to do: it restates the fields the marks
        // ride out in, and it asks the session to remember the row — the four boxes as
        // one list, the way every other switch of the form is remembered.
        function watchHeadingBlock() {
            var boxes = HEADING_BLOCK_BOXES.map(function (pair) {
                return document.getElementById(pair[1]);
            });

            if (boxes.some(function (box) { return box === null; })) {
                return;
            }

            // The row as the page was rendered with it: the state a request the server
            // refused has to put the four boxes back into.
            var shown = headingBlockFormats();

            boxes.forEach(function (box) {
                box.addEventListener('change', function () {
                    var marks = headingBlockFormats();

                    writeFormattingFields();
                    // The marks are all a box changes, and the marks are what the
                    // preview paints: a box that comes off has to take its line off the
                    // screen in the same click that takes it off the channel.
                    update();
                    postForJson(__HEADING_FORMATTING_URL, { headingFormatting: marks.join(',') })
                        .then(function (payload) {
                            shown = marks;
                            mirrorUrl(payload);
                        })
                        .catch(function (error) {
                            boxes.forEach(function (other, otherIndex) {
                                other.checked = shown.indexOf(HEADING_BLOCK_BOXES[otherIndex][0]) >= 0;
                            });
                            writeFormattingFields();
                            update();
                            showFlash('error', 'Не удалось запомнить метки заголовка: '
                                + (error && error.message ? error.message : error));
                        });
                });
            });
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
        var emojiArrow = document.getElementById('publicationEmojiArrow');
        var emojiQueryInput = document.getElementById('publicationEmojiQuery');
        var emojiCategoriesBox = document.getElementById('publicationEmojiCategories');
        var emojiGridBox = document.getElementById('publicationEmojiGrid');
        // The width the font of the machine gives a symbol. The canvas answers the
        // same advance the cell of the grid would lay out, which is what the panel
        // needs the cell to hold; the size it is asked at does not decide the
        // answer, since the reference emoji is measured at the same one.
        var emojiWidth = (function () {
            var ctx = document.createElement('canvas').getContext('2d');

            ctx.font = '20px ' + getComputedStyle(document.body).fontFamily;

            return function (symbol) {
                return ctx.measureText(symbol).width;
            };
        })();
        // Whether the font drew a symbol at all. A code point no font on any machine
        // holds — U+10FFFF, which Unicode reserves forever — is drawn as the box of
        // the last resort, and every symbol the font holds no figure for comes out of
        // that same box, pixel for pixel, which is the answer compared here. The
        // pixels and not the width: the box is as wide as the emoji it stands for,
        // while the black square of the table, which the font really does hold and
        // which is black by design, is that same width again. Where even the grinning
        // face comes out of the box the machine draws no emoji, and the question is
        // dropped: it would refuse the whole table to a font that has nothing to say.
        var emojiMissing = (function () {
            var canvas = document.createElement('canvas');
            var ctx = canvas.getContext('2d', {willReadFrequently: true});
            // One answer of the font per symbol: the panel is filled again on every
            // keystroke, every chip and every arrow, and the second answer of the
            // same question is the first.
            var answers = {};

            canvas.width = 48;
            canvas.height = 32;
            ctx.font = '20px ' + getComputedStyle(document.body).fontFamily;
            ctx.textBaseline = 'alphabetic';

            function figure(symbol) {
                var hash = 2166136261;
                var pixels;

                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.fillStyle = '#000';
                ctx.fillText(symbol, 2, 26);
                pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;

                for (var i = 0; i < pixels.length; i++) {
                    hash = ((hash ^ pixels[i]) * 16777619) >>> 0;
                }

                return hash;
            }

            var box = figure('\u{10FFFF}');
            var width = ctx.measureText('\u{10FFFF}').width;

            return box === figure('\u{1F600}')
                ? function () {
                    return false;
                }
                : function (symbol) {
                    if (answers[symbol] === undefined) {
                        // One figure of one font has one advance, so a symbol that is
                        // not as wide as the box cannot be that box. The width is
                        // asked first because the pixels cost a second the whole
                        // category would take.
                        answers[symbol] = ctx.measureText(symbol).width === width
                            && figure(symbol) === box;
                    }

                    return answers[symbol];
                };
        })();
        var EMOJI = window.TRVL_EMOJI ? readEmojiTable(window.TRVL_EMOJI) : [];
        // The width of the reference emoji, which the answer of the width question
        // is weighed against wherever the font is asked.
        var emojiSingle = emojiWidth('\u{1F600}');
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
        // on while nothing is asked for. The font is put to here, when the panel is
        // filled, and not over the whole table at start-up: a machine takes a few
        // tenths of a millisecond for its first sight of a symbol, so a question
        // about the table would hold the page for half a second to name the boxes of
        // the parts nobody is about to open. The answer is remembered by emojiMissing.
        // A category is asked before it is cut, because the rows the font has no
        // figure for would otherwise stand as boxes in the page and leave one cell
        // short of what the panel could have filled. A word is cut first: its matches
        // come back ranked, so the tail of them is not the answer anyone is looking
        // for, and one keystroke must not cost more than the panel can show.
        function emojiListOf(word) {
            if (word !== '') {
                return emojiSupported(emojiSearch(word, EMOJI, EMOJI_SEARCH_LIMIT), emojiWidth, emojiSingle, emojiMissing);
            }

            var category = EMOJI.filter(function (item) {
                return item.category === emojiCategory;
            });

            return emojiSupported(category, emojiWidth, emojiSingle, emojiMissing).slice(0, EMOJI_BROWSE_LIMIT);
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
            var anchor = emojiAnchor();
            var point = popupPoint(
                anchor,
                { width: emojiPanel.offsetWidth, height: emojiPanel.offsetHeight },
                { width: window.innerWidth, height: window.innerHeight }
            );
            // The side the arrow stands on is the side the panel landed on: a panel
            // above the anchor wears the arrow at its bottom, one below — at its top.
            var above = point.top + emojiPanel.offsetHeight <= anchor.y;
            // The arrow of a popover is a box of 1rem with the triangle in its middle,
            // and it is pulled to the anchor the same way Popper would have pulled it.
            var arrowWidth = 16;
            var arrowLeft = anchor.x - point.left - arrowWidth / 2;

            emojiPanel.style.left = point.left + 'px';
            emojiPanel.style.top = point.top + 'px';
            emojiPanel.dataset.popperPlacement = above ? 'top' : 'bottom';

            if (emojiArrow) {
                emojiArrow.style.transform = 'translate(' + Math.round(
                    Math.max(arrowWidth, Math.min(arrowLeft, emojiPanel.offsetWidth - arrowWidth * 2))
                ) + 'px, 0)';
            }
        }

        // The panel of the button stands over the button, the panel of a colon over
        // the caret that colon left: the arrow of the first has to point at the smile
        // a person has just pressed, and the whole card moves to keep it there.
        function emojiAnchor() {
            if (emojiTarget.mode === 'picker' && emojiTarget.field.__emojiButton) {
                return buttonPoint(emojiTarget.field.__emojiButton);
            }

            return caretPoint(emojiTarget.field, emojiTarget.start + emojiTarget.length);
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
            // of it but the first: the caret only goes back where a field of the page
            // still stands — a heading of a part and a label of a button included.
            if (textParts().concat(titleFields(), buttonLabelFields(document)).indexOf(field) !== -1) {
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

        // The blocks the parts are drawn in, in the order of the parts: one block
        // holds the text, the heading, the album and the buttons of one part, and
        // the arrows of a part find the place it stands on in this list.
        function partBlocks() {
            return Array.prototype.slice.call(partsBox.querySelectorAll('.publication-text-block'));
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

        // The marks of every heading, in the order of the parts. A heading is a field
        // of the list like its text, so its list is read the same way — from the
        // editor that stands over it, not from the json the form was filled with.
        function readTitleFormats() {
            return titleFields().map(function (field) {
                return formattingOf(field);
            });
        }

        // Which row of which block a part of the form was taken out of. The field
        // of the block carries it as a json of three words — the id the row is
        // saved under, the block it stands in and the habitat its pill names — and
        // a part that was not taken out of anything carries an empty field, which
        // is how a new part and a part born of a cut both come. A part a fill of
        // the forum brought carries the forum as its habitat and no id: the save
        // still writes it as a new row, the marker only says where the reader took
        // its text from. Every habitat wears its own glyph inside its own pill, so
        // the five differ by glyph and not by color alone.
        var HABITAT_BADGES = {
            forum: {
                label: 'Форум',
                icon: 'bi-collection',
                css: 'bg-info-subtle text-info-emphasis'
            },
            channel: {
                label: 'В канале',
                icon: 'bi-telegram',
                css: 'bg-success-subtle text-success'
            },
            post: {
                label: 'Публикации',
                icon: 'bi-journal-text',
                css: 'bg-primary-subtle text-primary'
            },
            draft: {
                label: 'Черновики',
                icon: 'bi-file-earmark-text',
                css: 'bg-warning-subtle text-warning-emphasis'
            },
            deleted: {
                label: 'Удалённые',
                icon: 'bi-trash',
                css: 'bg-danger-subtle text-danger'
            },
            none: {
                label: 'Без блока',
                icon: 'bi-pencil',
                css: 'bg-secondary-subtle text-secondary-emphasis'
            }
        };

        function parseSlot(raw) {
            var held = { id: null, source: '', habitat: '' };

            if (!raw) {
                return held;
            }

            try {
                var stored = JSON.parse(raw);

                if (stored && typeof stored === 'object') {
                    held.id = typeof stored.id === 'number' ? stored.id : null;
                    held.source = typeof stored.source === 'string' ? stored.source : '';
                    held.habitat = typeof stored.habitat === 'string' ? stored.habitat : '';
                }
            } catch (e) {}

            return held;
        }

        function readSlots() {
            return partBlocks().map(function (block) {
                var field = block.querySelector('.publication-part-slot');

                return field && field.value ? field.value : '';
            });
        }

        // The part a cut starts is a new row of the block its parent stands in: it
        // wears the same pill and carries no id, because nothing of it is saved
        // anywhere yet.
        function childSlotOf(raw) {
            var held = parseSlot(raw);

            if (held.source === '') {
                return '';
            }

            return JSON.stringify({ id: null, source: held.source, habitat: held.habitat });
        }

        // The three handles of a part answer for the block its slot names. A part that
        // already rests in the drafts block has nothing left to save, and one that rests
        // in the deleted block has nothing left to delete, so the handle that would do it
        // stands off — the same pair of rules the rows of the two blocks draw. A part with
        // no row behind it is the third case, and its two handles say so in their own
        // words: «Сохранить» writes its first draft, «Удалить» takes the block out of the
        // form, because there is no record to move.
        function updatePartActions(block, held) {
            var save = block.querySelector('[data-part-action="save"]');
            var remove = block.querySelector('[data-part-action="delete"]');

            if (!save || !remove) {
                return;
            }

            save.disabled = held.source === 'draft';
            save.title = held.source === 'draft'
                ? 'Часть уже лежит в черновиках'
                : (held.id === null
                    ? 'Сохранить эту часть первым черновиком'
                    : 'Перенести эту часть в черновики');
            remove.disabled = held.source === 'deleted';
            remove.title = held.source === 'deleted'
                ? 'Часть уже лежит в удалённых'
                : (held.id === null
                    ? 'Убрать эту часть из формы'
                    : 'Перенести эту часть в удалённые');
        }

        // The mark of one block: a pill of its own color with its glyph standing at
        // the left of the word inside it. The glyph is a child of the pill and not a
        // box beside it, so it reads the pill's own `line-height: 1` and cannot make
        // the mark taller than the word alone. A part whose slot names no habitat at
        // all is a part the reader wrote himself or one a cut gave birth to, and it
        // wears «Без блока» — the row of the label is never left bare again.
        function writeSlot(block, raw) {
            var field = block.querySelector('.publication-part-slot');
            var span = block.querySelector('.publication-part-habitat');
            var held = parseSlot(raw);
            var badge = HABITAT_BADGES[held.habitat] || HABITAT_BADGES.none;

            updatePartActions(block, held);

            if (field) {
                field.value = raw || '';
            }
            if (!span) {
                return;
            }

            span.textContent = '';

            var pill = document.createElement('span');

            pill.className = 'badge rounded-pill fw-semibold px-3 py-1 ' + badge.css;

            var glyph = document.createElement('i');

            glyph.className = 'bi ' + badge.icon + ' me-1';
            pill.appendChild(glyph);
            pill.appendChild(document.createTextNode(badge.label));

            span.appendChild(pill);
        }

        // The slot of a part a fill of the forum brought. It holds no id, because
        // the text is not a row of any block yet, and names the forum as its
        // habitat, so the marker survives a cut, an arrow and a rebuild: every one
        // of them moves the hidden field of the part, not the picture drawn from it.
        function forumSlot() {
            return JSON.stringify({ id: null, source: 'forum', habitat: 'forum' });
        }

        // The slot of the row the reader clicked: the block it stands in, the id it
        // is saved under and the habitat its own badge has to name. A row that is
        // not a record at all — a forum post filling a blank form — has no habitat,
        // and the form of it carries no slot.
        function slotOfRow(log) {
            var habitat = log.getAttribute('data-habitat') || '';

            if (habitat === '') {
                return '';
            }

            return JSON.stringify({
                id: parseInt(log.getAttribute('data-source-id') || '', 10) || null,
                source: log.getAttribute('data-source-type') || '',
                habitat: habitat
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

        // The rows of every album: the box of the shared field first, then one per
        // part, the same offset the fields of links and of files keep.
        function albumRowBoxes() {
            return [sharedAlbumRowsBox].concat(albumBoxes().map(function (box) {
                return box.querySelector('.publication-album-rows');
            }));
        }

        function albumOrderFields() {
            return [imagesOrderInput].concat(albumBoxes().map(function (box) {
                return box.querySelector('.publication-album-order');
            }));
        }

        function albumRowSwitches() {
            return [albumRowsInput].concat(albumBoxes().map(function (box) {
                return box.querySelector('.publication-album-rows-switch');
            }));
        }

        function albumRowsOf(box) {
            return Array.prototype.slice.call(box.querySelectorAll('.publication-album-row'));
        }

        // The mask of an album as its field holds it: one token per picture. An
        // empty field is no pattern, which the plain order of the two fields is.
        function readAlbumMask(index) {
            var target = albumOrderFields()[index];

            return target ? String(target.value).split('') : [];
        }

        function writeAlbumMask(index, mask) {
            var target = albumOrderFields()[index];

            if (target) {
                target.value = (mask || []).join('');
            }
        }

        // The album of one part as the rows of its box show it: the links of its
        // field and the files of its picker woven into one list by its mask.
        function readAlbumOrder(index) {
            return orderAlbum(readImageGroups()[index], readFileGroups()[index], readAlbumMask(index));
        }

        // Any arrangement of an album ends here, and the three fields of it are
        // written from the one list: the text area of the links, the picker of the
        // files and the mask that says which of them stands where. A blank link is
        // not one of the pictures the album holds — a row the reader is still
        // typing into has no address yet, and a line the channel would take for
        // one cannot be left in the field.
        function setAlbumOrder(index, pictures) {
            var kept = (pictures || []).filter(function (picture) {
                return picture.kind !== 'link' || String(picture.value).trim() !== '';
            });
            var parts = splitAlbum(kept);

            writeImageGroup(index, parts.links);
            writeFileGroup(index, parts.files);
            writeAlbumMask(index, maskOfAlbum(kept));
        }

        // What an album really holds: its pictures in the order the reader laid
        // them out — the list the channel will get once the server has turned
        // every file into a link of its own. An album nobody has arranged comes
        // out as its links with the files picked for that part behind them, which
        // is exactly what the server does without a mask.
        function albumPictures() {
            return imageTargets().map(function (field, index) {
                return readAlbumOrder(index).map(function (picture) {
                    return picture.value;
                });
            });
        }

        // The album of a part handed to its neighbour: the moved pictures come
        // after what the receiving album already holds, a link both of them showed
        // is named once, and the album they came from stands empty. The order of
        // both is kept, because the two lists are woven by the mask of the album
        // they arrive in.
        function moveImageGroup(index, step) {
            var to = index + step;
            var moved = readAlbumOrder(index);
            var many = readAlbumOrder(to);

            // An empty album has nothing to move, so the buttons never turn into a
            // way to empty the field of a neighbour.
            if (index < 0 || to < 0 || to >= imageTargets().length || moved.length === 0) {
                return;
            }

            setAlbumOrder(to, unionPictures([many, moved]));
            setAlbumOrder(index, []);
            forgetAlbumBlanks(to);
            forgetAlbumBlanks(index);
            renderNotice(albumNotices()[index], []);
            renderFilesNotice(albumFileNotices()[index], [], []);
            updateImages();
            syncAlbumRows();
        }

        // One picture of an album handed to its neighbour: it comes in behind what that
        // album already holds, a link both of them showed is named once, and every other
        // picture of the album it left stands exactly where the reader laid it out. A row
        // is the unit here, so the album of the fields is what moves: the text is left
        // alone, no part is cut again, and the mask of neither album is lost.
        function moveAlbumPictureToPart(index, position, step) {
            var to = index + step;
            var pictures = readAlbumOrder(index);

            if (index < 0 || to < 0 || to >= imageTargets().length) {
                return false;
            }
            if (position < 0 || position >= pictures.length) {
                return false;
            }

            var moved = pictures.splice(position, 1);

            setAlbumOrder(to, unionPictures([readAlbumOrder(to), moved]));
            setAlbumOrder(index, pictures);
            updateImages();
            syncAlbumRows();

            return true;
        }

        // The links an album was shown holding are named once, as they were before
        // the rows; two files of one name are two picks the reader made.
        function unionPictures(groups) {
            var pictures = [];

            groups.forEach(function (group) {
                group.forEach(function (picture) {
                    var seen = picture.kind === 'link' && pictures.some(function (other) {
                        return other.kind === 'link' && other.value === picture.value;
                    });

                    if (!seen) {
                        pictures.push(picture);
                    }
                });
            });

            return pictures;
        }

        // --- the rows of an album -------------------------------------------------

        // The four controls a row is worked with: the address of a link and the
        // three icons. A rebuild of the box gives them new nodes, so the one that
        // had the focus is looked for by the same class in the row it went to.
        var ALBUM_ROW_CONTROLS = ['.publication-album-link', '.publication-album-up',
            '.publication-album-down', '.publication-album-prev', '.publication-album-next',
            '.publication-album-remove'];

        function isAlbumRowsOn(index) {
            var toggle = albumRowSwitches()[index];

            return !!(toggle && toggle.checked);
        }

        // Which album and which picture a control of a row belongs to. The rows of
        // a box are its album's pictures in their order, so the place of a row in
        // the box is the place of its picture in the album.
        function albumRowOf(target) {
            var row = target.closest ? target.closest('.publication-album-row') : null;

            if (!row) {
                return null;
            }

            var box = row.closest('.publication-album-rows');

            return {
                index: albumRowBoxes().indexOf(box),
                position: albumRowsOf(box).indexOf(row),
                box: box,
            };
        }

        // The control the reader is working in, and where it stands among the rows.
        function albumFocusedRow(box) {
            var active = document.activeElement;
            var selector = null;

            if (!active || !box.contains(active)) {
                return null;
            }
            ALBUM_ROW_CONTROLS.some(function (candidate) {
                if (active.matches(candidate)) {
                    selector = candidate;
                }

                return !!selector;
            });
            if (!selector) {
                return null;
            }

            return {
                position: albumRowsOf(box).indexOf(active.closest('.publication-album-row')),
                selector: selector,
                caret: selector === '.publication-album-link' ? active.selectionStart : null,
            };
        }

        function focusAlbumRow(box, held) {
            var row = albumRowsOf(box)[held.position];
            var control = row ? row.querySelector(held.selector) : null;

            if (!control) {
                return;
            }
            control.focus();
            if (held.caret !== null) {
                control.setSelectionRange(held.caret, held.caret);
            }
        }

        // The rows a reader has opened but not filled in yet: an address that is
        // still empty is not a picture of the album — the text area has no line for
        // it, and the channel would get nothing out of it — so it stands in the box
        // and in this count, behind the pictures, until a character arrives there.
        // A row whose address is erased becomes one of them again.
        var albumBlankRows = [];

        function forgetAlbumBlanks(index) {
            albumBlankRows[index] = 0;
        }

        // The icon that opens a row of an album stands beside the box of its rows,
        // so the album it belongs to is read from the frame both of them stand in.
        function albumIndexOfNode(node) {
            var frame = node.closest('.publication-field-frame');

            return frame ? albumRowBoxes().indexOf(frame.querySelector('.publication-album-rows')) : -1;
        }

        function albumAddBoxOf(index) {
            var box = albumRowBoxes()[index];

            return box ? box.parentNode.querySelector('.publication-album-add-box') : null;
        }

        // One row is one picture of the album: the address of a link in an input
        // that carries no name, the name of a file in a block that cannot be typed
        // into. A file has no address to show until the server has given it one,
        // and its place in the album is what the arrows change, so a row of a file
        // says what the picker below it holds.
        function renderAlbumRows(index, held) {
            var box = albumRowBoxes()[index];

            if (!box) {
                return;
            }

            var pictures = readAlbumOrder(index);
            // An album that holds nothing keeps one row open: the rows stand in the place of
            // its text area, and an empty box would leave the reader with nothing to type
            // into. The counter of the blanks pays for that row the way the icon of the album
            // does, so pressing it opens a second row rather than bringing the caret back to
            // the one that already stands there.
            if (pictures.length === 0 && !albumBlankRows[index]) {
                albumBlankRows[index] = 1;
            }
            var many = pictures.length + (albumBlankRows[index] || 0);
            var focused = held || albumFocusedRow(box);

            box.textContent = '';
            for (var position = 0; position < many; position += 1) {
                box.appendChild(albumRowTemplate.cloneNode(true));
            }

            albumRowsOf(box).forEach(function (row, place) {
                var blank = place >= pictures.length;
                var picture = blank ? null : pictures[place];
                var isLink = blank || picture.kind === 'link';
                var link = row.querySelector('.publication-album-link');

                link.classList.toggle('d-none', !isLink);
                link.value = isLink && picture ? picture.value : '';
                row.querySelector('.publication-album-file').classList.toggle('d-none', isLink);
                row.querySelector('.publication-album-file-name').textContent =
                    isLink ? '' : picture.value.name;
            });

            markAlbumRowFlags(index, box, pictures);

            if (focused) {
                focusAlbumRow(box, focused);
            }
        }

        // Which arrows of a row are stopped: a blank holds no picture, so it has no
        // place of the album to be moved to, and the pictures stop at the ends of
        // the album. The icons are stopped rather than taken away, as the icons of a
        // button row are, so that the column never jumps.
        //
        // The two that cross to a neighbour follow the list of albums instead: the
        // first of them has no part above it and the last has none below, and a list
        // one album long is a publication that is not cut at all. Those two are taken
        // away rather than stopped, because no keystroke of the reader can make a part
        // stand where no part of the publication is.
        function markAlbumRowFlags(index, box, pictures) {
            var hasPrev = index > 0;
            var hasNext = index < albumRowBoxes().length - 1;

            albumRowsOf(box).forEach(function (row, place) {
                var blank = place >= pictures.length;

                row.querySelector('.publication-album-up').disabled = blank || place === 0;
                row.querySelector('.publication-album-down').disabled =
                    blank || place === pictures.length - 1;
                row.querySelector('.publication-album-prev').classList.toggle('d-none', !hasPrev);
                row.querySelector('.publication-album-prev').disabled = blank;
                row.querySelector('.publication-album-next').classList.toggle('d-none', !hasNext);
                row.querySelector('.publication-album-next').disabled = blank;
            });
        }

        // The rows stand in the place of the text area of their album, and the
        // picker stays where it is: it is how a file gets into the album, not a
        // list of what the album holds. A text area taken out of sight is hidden by
        // class and never disabled — a disabled field is not submitted, and the
        // album would travel to the channel without a single link.
        function syncAlbumRows() {
            imageTargets().forEach(function (field, index) {
                var box = albumRowBoxes()[index];
                var add = albumAddBoxOf(index);
                var on = isAlbumRowsOn(index);

                if (box) {
                    box.classList.toggle('d-none', !on);
                    if (on) {
                        renderAlbumRows(index);
                    }
                }
                if (add) {
                    add.classList.toggle('d-none', !on);
                }
                if (field) {
                    field.classList.toggle('d-none', on);
                }
            });
        }

        // One place a row edit leaves the album: the fields are written from the new
        // order and everything that listens to the album is drawn again. The rows
        // themselves follow only an edit that came from an icon — between two
        // keystrokes the box holds the field the caret stands in, and drawing it
        // again would take that field away from under it.
        function applyAlbumPictures(index, pictures, held) {
            setAlbumOrder(index, pictures);
            updateImages();

            if (held && isAlbumRowsOn(index)) {
                renderAlbumRows(index, held);
            }
        }

        // The address a row shows is the picture of the album it stands for, unless
        // the row is one of the blanks behind the album: those join by their own
        // place, which is the end of it, and an address erased leaves the album and
        // takes the place of a blank.
        function takeAlbumLinkEdit(event) {
            var row = albumRowOf(event.target);

            if (!row || !event.target.classList.contains('publication-album-link')) {
                return;
            }

            var pictures = readAlbumOrder(row.index);
            var value = String(event.target.value);
            var blank = row.position >= pictures.length;

            if (blank) {
                if (value.trim() === '') {
                    return;
                }
                pictures.push({ kind: 'link', value: value });
                albumBlankRows[row.index] = Math.max(0, (albumBlankRows[row.index] || 0) - 1);
            } else if (value.trim() === '') {
                pictures = removeAlbumPicture(pictures, row.position);
                albumBlankRows[row.index] = (albumBlankRows[row.index] || 0) + 1;
            } else {
                pictures[row.position].value = value;
            }

            applyAlbumPictures(row.index, pictures);

            // The box was not drawn again, so the arrows of these rows would still
            // speak of the album as it stood before the keystroke. Only the flags
            // move here: the field the caret stands in is left exactly as it is.
            var box = albumRowBoxes()[row.index];

            if (box) {
                markAlbumRowFlags(row.index, box, readAlbumOrder(row.index));
            }
        }

        // The icon of one album only: a click anywhere else in the card — and a
        // label hands its click on to the control it stands for, so one reader
        // click arrives as two events — must not open a row of its own.
        function takeAlbumAdd(event) {
            var add = event.target.closest('.publication-album-add');

            if (!add) {
                return;
            }

            var index = albumIndexOfNode(add);

            if (index < 0) {
                return;
            }

            var many = (albumBlankRows[index] || 0) + 1;

            albumBlankRows[index] = many;

            // The row opens at the end of the album, where a blank stands, and the
            // caret goes into it: what the reader types there becomes the address of
            // one more picture of the album.
            applyAlbumPictures(index, readAlbumOrder(index), {
                position: readAlbumOrder(index).length + many - 1,
                selector: '.publication-album-link',
                caret: 0,
            });
        }

        function takeAlbumRowClick(event) {
            var row = albumRowOf(event.target);
            var prev = event.target.closest('.publication-album-prev');
            var next = event.target.closest('.publication-album-next');
            var up = event.target.closest('.publication-album-up');
            var down = event.target.closest('.publication-album-down');
            var remove = event.target.closest('.publication-album-remove');

            if (!row || !(prev || next || up || down || remove)) {
                return;
            }

            var pictures = readAlbumOrder(row.index);
            var blank = row.position >= pictures.length;
            var held = null;

            // The two arrows that cross answer the neighbour of the part, not its own
            // album, so they are done before the fields of this album are written — and
            // a row that holds no picture has nothing to hand over. The album the picture
            // arrives in is laid out by the move, as is this one; only the place the
            // reader stands in is given back here.
            if (prev || next) {
                var arrow = prev ? '.publication-album-prev' : '.publication-album-next';

                if (blank || !moveAlbumPictureToPart(row.index, row.position, prev ? -1 : 1)) {
                    return;
                }

                var stay = Math.min(row.position,
                    readAlbumOrder(row.index).length + (albumBlankRows[row.index] || 0) - 1);

                // The row that came to stand in the place of the one that left is the
                // one the reader keeps working with, as it is after a row taken out.
                renderAlbumRows(row.index, stay < 0 ? null : {
                    position: stay,
                    selector: arrow,
                    caret: null,
                });

                return;
            }

            if (up || down) {
                pictures = moveAlbumPicture(pictures, row.position, up ? -1 : 1);
                held = {
                    position: row.position + (up ? -1 : 1),
                    selector: up ? '.publication-album-up' : '.publication-album-down',
                    caret: null,
                };
            } else if (remove) {
                if (blank) {
                    albumBlankRows[row.index] = Math.max(0, (albumBlankRows[row.index] || 0) - 1);
                } else {
                    pictures = removeAlbumPicture(pictures, row.position);
                }
                // The row that came to stand in the place of the one taken out is
                // the one the reader keeps working with.
                held = {
                    position: Math.min(row.position,
                        readAlbumOrder(row.index).length + (albumBlankRows[row.index] || 0) - 1),
                    selector: '.publication-album-remove',
                    caret: null,
                };

                if (held.position < 0) {
                    held = null;
                }
            }

            applyAlbumPictures(row.index, pictures, held);
        }

        // The switch shows the rows of its own album and takes the text area of it
        // out of sight. Nothing of the album changes: the rows are the same two
        // fields seen as one list, and a blank row the reader had opened is kept.
        function takeAlbumRowsSwitch(event) {
            if (!event.target.classList
                || !event.target.classList.contains('publication-album-rows-switch')) {
                return;
            }

            syncAlbumRows();
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

        // Two places of a list traded where they stand: a part is one place in each
        // of the lists the rebuild reads, and moving it over its neighbour changes
        // nothing but the two places those lists hold for them.
        function exchange(values, first, second) {
            var held = values[first];

            values[first] = values[second];
            values[second] = held;

            return values;
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
                return box.querySelector('.publication-album-strip');
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

        // The wording of the shared album has two readings: the one of the form, where the
        // block is a field of the publication, and the one of a part, where the block stands
        // in the row it belongs to. Both are written in the markup and the block says in
        // `data-in-part` which of them it wears, so a reading is never rewritten twice.
        function setAlbumWording(inPart) {
            Array.prototype.slice.call(albumBlock.querySelectorAll('.publication-album-name'))
                .forEach(function (name) {
                    name.textContent = inPart ? name.dataset.part : name.dataset.shared;
                });
            Array.prototype.slice.call(albumBlock.querySelectorAll('[data-bs-content-part]'))
                .forEach(function (el) {
                    // The reading of the form stands in `data-bs-content` alone until the
                    // first swap takes it away, so it is copied aside before that.
                    if (!el.dataset.bsContentShared) {
                        el.dataset.bsContentShared = el.dataset.bsContent;
                    }
                    el.dataset.bsContent = inPart ? el.dataset.bsContentPart : el.dataset.bsContentShared;
                    makePopover(el);
                });
        }

        // The album of the first part is the shared block of the form, and a cut carries that
        // block into the row it belongs to instead of copying its fields: the names the server
        // weaves the parts out of, the pool the distribution cuts and the mask of an order all
        // stay where they were. The block goes in front of the album the row was cloned with,
        // which stays hidden and disabled for the first part, and comes back to the mark it
        // left as soon as the text is one piece again.
        function placeSharedAlbum() {
            if (!albumBlock || !albumHome) {
                return;
            }

            var inPart = textParts().length > 1;
            var home = albumHome.parentNode;
            var mark = inPart ? '1' : '0';

            if (inPart) {
                var block = partBlocks()[0];
                if (block && albumBlock.parentNode !== block) {
                    block.insertBefore(albumBlock, block.querySelector('.publication-part-album'));
                }
            } else if (albumBlock.parentNode !== home) {
                home.insertBefore(albumBlock, albumHome.nextSibling);
            }

            if (albumBlock.dataset.inPart !== mark) {
                albumBlock.dataset.inPart = mark;
                setAlbumWording(inPart);
            }
        }

        // --- the link buttons of a part -------------------------------------------

        // The shared «Кнопки-ссылки» field of the form carries the buttons of the
        // first part, so the box of that part stays hidden and disabled the same
        // way the album box does. The switch of the field shows the box of every
        // part after it and puts the buttons of the shared field into each one.
        // A box holds one row per button: the two fields of it and the icon that
        // takes the row out of the form again.

        // The blocks the parts are cloned with, in the order of the parts: the first of
        // them answers for the first part only while the mode of a block per part stands.
        function partButtonBoxes() {
            return Array.prototype.slice.call(partsBox.querySelectorAll('.publication-part-button'));
        }

        // The mode is a field of a split publication only: with one part there is
        // nothing to separate, and the block of the form answers for the whole of it.
        function buttonsPerPartOn() {
            return !!(buttonsPerPartInput && buttonsPerPartInput.checked) && textParts().length > 1;
        }

        // The boxes the parts are read through. While the mode sleeps the first part is
        // answered for by the field of the form, so the list of the blocks starts one
        // block behind the parts; the mode hands the block of the first part to that part.
        function buttonBoxes() {
            return partButtonBoxes().slice(buttonsPerPartOn() ? 0 : 1);
        }

        function buttonBoxForPart(index) {
            if (index === 0 && !buttonsPerPartOn()) {
                return sharedButtonBox;
            }

            return partButtonBoxes()[index];
        }

        // The switch of the addresses a block of a part carries, and nothing for the box
        // of the form — that one has its switch beside the frame, not inside it.
        function partLinksSwitchOf(box) {
            return box.querySelector('.publication-part-links-switch');
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
        // box points at one field. The bases of the names move with the box. The
        // label is named by its own class: the frame of a part holds a second label,
        // the one of the switch of its addresses, and that one points at the switch.
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
            box.querySelector('.publication-buttons-label').setAttribute('for', bases[0] + '-0');
        }

        // A row the box grew by is a copy of the template, and the template stands
        // outside the form: the label of it carries no editor yet. The labels are
        // mounted before the values are written, so that a write of a button goes
        // through the mirror of an editor and not over the text of a bare field —
        // a field the page has just mounted paints the mirror it was handed.
        function mountButtonLabelEditors(box) {
            buttonLabelFields(box).forEach(function (field) {
                mountPartEditor(field, true);
                // A box of a part is drawn out of sight and with its fields disabled,
                // and the load of a form never asks the switch about it: an editor
                // born over a mirror the form does not submit has to start where the
                // mirror of its own row already stands.
                if (field.__editor) {
                    field.__editor.enable(!field.disabled);
                }
            });
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
            mountButtonLabelEditors(box);

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
            mountButtonLabelEditors(box);
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

        // A row of a box swaps places with its neighbour. The order of the rows is the
        // order the channel gets its buttons in, since the form submits them as one
        // list and the server reads that list positionally — so this is the whole of
        // what the two icons do, and nothing on the server has to know about them.
        // The names of the fields do not move (every row of a box carries one name),
        // but their ids do, so the rows are named again after the swap.
        function moveButtonRow(button, step) {
            var row = button.closest('.publication-button-row');
            var box = row.closest('.publication-button-box');
            var rows = buttonRowsOf(box);
            var index = rows.indexOf(row);
            var neighbour = rows[index + step];
            // The row goes in front of whatever has to stay behind it: the neighbour
            // it swaps with when climbing, the row after that neighbour when falling.
            var before = step < 0 ? neighbour : rows[index + 2] || null;

            if (!neighbour) {
                return;
            }
            rowsBoxOf(box).insertBefore(row, before);
            nameButtonRows(box);
        }

        // A row goes out by its own icon and climbs or falls by the two beside it; the
        // one row of a box keeps the column of icons hidden, and at the ends of the
        // list the icon that has nowhere to go is stopped rather than taken away, so
        // that the column never jumps. The icon of adding stops at the bound the
        // portal gives a message. The icons leave with their column, so that the
        // address field of a row without them reaches the same edge they stand at.
        function updateButtonRows(box, enabled) {
            var rows = buttonRowsOf(box);

            rows.forEach(function (row, index) {
                var remove = row.querySelector('.publication-button-remove');

                remove.parentElement.classList.toggle('d-none', rows.length < 2);
                row.querySelector('.publication-button-up').disabled = !enabled || index === 0;
                row.querySelector('.publication-button-down').disabled = !enabled || index === rows.length - 1;
                remove.disabled = !enabled;
            });
            addButtonOf(box).disabled = !enabled || rows.length >= __BUTTON_LIMIT;
        }

        // The buttons of every part, each read through the box that answers for it.
        function readButtonGroups() {
            return textParts().map(function (field, index) {
                return buttonsOf(buttonBoxForPart(index));
            });
        }

        // The row of this switch shows whatever the form holds, so the number of parts is
        // answered here and not by a row put away: one part has no other part to hand its
        // buttons to.
        function isEveryPartButton() {
            return !!(buttonEveryPartInput && buttonEveryPartInput.checked)
                && textParts().length > 1;
        }

        // The boxes hold the buttons of the shared field while the old switch is on and
        // stand empty while it is off: what a hidden box keeps must not reach the
        // form or the preview. `carried` is the list every part held before the parts
        // were rebuilt, and only the mode of a block per part keeps a part to its own
        // list — the old switch hands one list out, so a rebuild restates it.
        function syncPartButtons(carried) {
            var first = buttonsPerPartOn() ? 0 : 1;
            var buttons = isEveryPartButton() ? buttonsOf(sharedButtonBox) : [];

            buttonBoxes().forEach(function (box, offset) {
                var index = first + offset;
                var own = carried !== undefined && buttonsPerPartOn() ? carried[index] : undefined;

                writeButtons(box, own === undefined ? buttons : own);
            });
        }

        // The two boxes that can answer for the first part trade the name their rows are
        // submitted under. The server reads the buttons of the first part out of the
        // shared field, so while the mode stands that name belongs to the block of the
        // part, and the sleeping block of the form takes the name of a part that has
        // another block of its own: a disabled field is not submitted, so the trade
        // leaves exactly one list for the first part.
        function applyButtonNames() {
            var first = partButtonBoxes()[0];
            // The two bases the markup draws: the field of the form is the list of the
            // first part, and the block a part is cloned with stands for the second of
            // them until the mode puts it over the first.
            var shared = ['publicationButtonText', 'publicationButtonUrl'];
            var part = ['publicationPartButtonText0', 'publicationPartButtonUrl0'];

            if (first) {
                var awake = buttonsPerPartOn() ? shared : part;
                var asleep = buttonsPerPartOn() ? part : shared;

                rowsBoxOf(first).setAttribute('data-text', awake[0]);
                rowsBoxOf(first).setAttribute('data-url', awake[1]);
                rowsBoxOf(sharedButtonBox).setAttribute('data-text', asleep[0]);
                rowsBoxOf(sharedButtonBox).setAttribute('data-url', asleep[1]);
            }
            partButtonBoxes().forEach(nameButtonRows);
            nameButtonRows(sharedButtonBox);
        }

        // The rows of the first part move with the box that answers for it, so that
        // throwing the switch loses nothing of what the reader has already set. The
        // box left without them keeps the one blank row every box always holds.
        function handOverFirstPart() {
            var first = partButtonBoxes()[0];

            if (!first) {
                return;
            }
            if (buttonsPerPartOn()) {
                writeButtons(first, buttonsOf(sharedButtonBox));
                writeButtons(sharedButtonBox, []);

                return;
            }
            writeButtons(sharedButtonBox, buttonsOf(first));
            writeButtons(first, []);
        }

        // A box goes into the form and out of it whole: its fields, the panel of every
        // label of it, the icons of its rows and the switch of the addresses it carries.
        // A box that stays in the form while its fields leave it is a box the reader
        // sees and cannot reach, and that is what the mode does to the block of the form.
        function liveButtonBox(box, live) {
            buttonRowsOf(box).forEach(function (row) {
                buttonFieldsOf(row).forEach(function (field) {
                    field.disabled = !live;
                    // The panel of a label is a control of the same row, so a box
                    // that goes out of the form puts the panel away with the field
                    // it stands over: an editor left editable would take text the
                    // form does not submit.
                    if (field.__editor) {
                        field.__editor.enable(live);
                    }
                });
            });
            updateButtonRows(box, live);
            if (box !== sharedButtonBox) {
                box.classList.toggle('d-none', !live);
            }
            var own = partLinksSwitchOf(box);

            if (own) {
                own.disabled = !live;
            }
        }

        // The switch of the parts is a field of a split publication only, and a
        // box answers for the form just while it shows: a disabled field is not
        // submitted, so a part with no box goes to the channel with no buttons.
        function updateButtonBoxes() {
            var separated = buttonsPerPartOn();

            if (buttonsPerPartInput) {
                buttonsPerPartInput.disabled = textParts().length < 2;
            }
            partButtonBoxes().forEach(function (box, index) {
                liveButtonBox(box, separated || (index > 0 && isEveryPartButton()));
            });
            liveButtonBox(sharedButtonBox, !separated);
            // The two switches of the block of the form answer for the rows that sleep
            // with it, so they leave the reach of the reader with them.
            if (linksToButtonsInput) {
                linksToButtonsInput.disabled = separated;
            }
            if (buttonEveryPartInput) {
                buttonEveryPartInput.disabled = separated;
            }
        }

        // The mode is thrown: the rows of the first part go to the block that starts
        // answering for it, the two boxes take the name they are submitted under, every
        // block wakes or sleeps, and the waiting for the addresses moves to the switches
        // that now own it. The preview reads the same boxes, so it is drawn again too.
        function redrawButtonBoxes() {
            handOverFirstPart();
            applyButtonNames();
            updateButtonBoxes();
            armLinksToButtons();
            update();
        }

        // The addresses of the text handed to the buttons of the same part. This
        // only ever appends: a row the person filled by hand stays where it stands,
        // and an address a box already carries is not added a second time.
        function fillButtonsFromText() {
            var fields = textParts();
            var separated = buttonsPerPartOn();
            var changed = false;

            if (!separated && fields.length > 1 && buttonEveryPartInput && !buttonEveryPartInput.checked) {
                // A part submits buttons only while its own box shows, so the parts
                // take their own fields along with the addresses of their own text.
                buttonEveryPartInput.checked = true;
                updateButtonBoxes();
            }

            fields.forEach(function (field, index) {
                var box = buttonBoxForPart(index);
                var own = separated ? partLinksSwitchOf(box) : null;

                // The mode reads the switch of every part on its own: a part whose own
                // switch sleeps keeps no rows, and a block that wears no switch at all
                // is no block of a part.
                if (separated && (own === null || own.checked !== true)) {
                    return;
                }

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

        // The waiting belongs to the switch that stands over the rows the fill writes:
        // the switch of the block of the form while the mode sleeps, and the switch of
        // a part once the mode has handed that part a block of its own — the block of
        // the form is asleep by then, and its switch arms nothing.
        function linksArmed() {
            if (buttonsPerPartOn()) {
                return partButtonBoxes().some(function (box) {
                    var own = partLinksSwitchOf(box);

                    return own !== null && own.checked === true;
                });
            }

            return !!(linksToButtonsInput && linksToButtonsInput.checked);
        }

        // The fill comes after the last keystroke, not with every one of them, so
        // each change of a text field puts the waiting off again.
        function armLinksToButtons() {
            if (linksToButtonsTimer) {
                clearTimeout(linksToButtonsTimer);
                linksToButtonsTimer = null;
            }
            if (!linksArmed()) {
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

        // A notice about the album of a part is put away by its own cross: what the
        // probe dropped has been read, and the line keeps standing over the strip
        // and the slider of that album until the next render brings a new list.
        // One listener on the form, because a part block is cloned with its own
        // notices and the shared album has two of its own.
        function watchNoticeDismiss(form) {
            if (!form) {
                return;
            }
            form.addEventListener('click', function (event) {
                var cross = event.target.closest('.publication-notice-close');

                if (cross) {
                    cross.closest('.publication-notice').classList.add('d-none');
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

        // The five radios under the switch of the numbering decide nothing while that
        // switch stands off: the number of a part goes into the head of its text, where
        // the shape of a heading has no say. And once the checkbox has handed the shape to
        // the field they decide nothing either — the field is the shape then. Both of those
        // cases grey them out rather than take them away, so the choice the reader made
        // stays visible and stays kept for the moment the numbering comes back on. The
        // field itself is the mirror of the two rows while the checkbox stands off and the
        // reader's own string while it stands on, so this hand writes the preset of the
        // rows into it and says nothing about which radio stands.
        function syncHeadingTemplateSwitch() {
            if (headingNumberBeforeInput) {
                headingNumberBeforeInput.disabled = !isNumbered() || headingNumberCustomOn();
            }
            if (headingNumberAfterInput) {
                headingNumberAfterInput.disabled = !isNumbered() || headingNumberCustomOn();
            }
            if (headingNumberDotInput) {
                headingNumberDotInput.disabled = !isNumbered() || headingNumberCustomOn();
            }
            if (headingNumberDashInput) {
                headingNumberDashInput.disabled = !isNumbered() || headingNumberCustomOn();
            }
            if (headingNumberBracketInput) {
                headingNumberBracketInput.disabled = !isNumbered() || headingNumberCustomOn();
            }
            if (headingNumberCustomInput) {
                headingNumberCustomInput.disabled = !isNumbered();
            }
            if (headingNumberTemplateInput) {
                headingNumberTemplateInput.disabled = !isNumbered() || !headingNumberCustomOn();

                if (!headingNumberCustomOn()) {
                    headingNumberTemplateInput.value = numberTemplateFor(
                        headingNumberAtEnd() ? 'after' : 'before', headingNumberStyle());
                }
            }
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

        // The line of the number stands at the head of the text of a part only while
        // the number has nowhere else to live: with both switches of the form standing
        // the same way the number is written in front of the heading of the part, and a
        // second copy of it in the text would reach the channel as «Часть 4» over the
        // body and «Часть 4. …» over the message. Taking either of the two switches off
        // brings the line back — the fields are rewritten from their bare text every
        // time, so the same pass that refuses to write the line takes the one that
        // stands there off.
        function applyPartNumbers() {
            var fields = textParts();
            if (fields.length < 2) {
                return;
            }

            var numbered = isNumbered() && !headingNumbersOn();

            fields.forEach(function (field, index) {
                var bare = stripPartNumber(field.value);
                field.value = numbered ? 'Часть ' + (index + 1) + '\n\n' + bare : bare;
            });
        }

        // The two switches a number written into a heading stands under: the numbering
        // of the parts, which makes the number, and the heading out of the first line
        // of the part, which makes the field it is written into.
        function headingNumbersOn() {
            return !!(titleFromFirstLineInput && titleFromFirstLineInput.checked
                && isNumbered());
        }

        // The number of every part in front of the heading of that part, and off it
        // again: the headings are rewritten by the place of the part, so a part added,
        // a part taken away and either of the two switches are all one and the same
        // pass over the fields. A single field is not a part of a split, so it wears
        // no number — the line of the text above makes the same refusal.
        function applyHeadingNumbers() {
            var fields = titleFields();
            var on = headingNumbersOn() && fields.length >= 2;

            numberHeadings(readTitles(), on).forEach(function (title, index) {
                fields[index].value = title;
            });
        }

        function updateCounters() {
            var titles = readTitles();
            var titleMarks = readTitleFormats();

            textParts().forEach(function (field, index) {
                var counter = field.closest('.publication-text-block').querySelector('.publication-text-count');
                if (!counter) {
                    return;
                }
                // The number of the message the channel gets, not of the text in
                // the field: a heading the part carries is part of that message,
                // and so are the marks the reader set over it or, where he set
                // none, the boxes the block of the heading stands with.
                var shown = composedMessage(
                    titles[index] || '', field.value, formattingOf(field), titleMarks[index] || [],
                    headingBlockFormats()).text.length;
                counter.textContent = shown + ' / ' + __TEXT_PART_LIMIT;
                counter.className = 'publication-text-count'
                    + (shown > __TEXT_PART_LIMIT ? ' text-danger' : ' text-muted');
            });
        }

        // Each row separates one part from the part below it.
        function mergeRows() {
            return Array.prototype.slice.call(partsBox.querySelectorAll('.publication-merge-row'));
        }

        function updateMergeRows() {
            var rows = mergeRows();
            var parts = bareParts();
            var visible = rows.length === textParts().length - 1;

            rows.forEach(function (row, index) {
                var scissors = row.querySelector('.publication-seam-split-btn');
                row.classList.toggle('d-none', !visible);
                // A seam with nothing to cut holds its scissors out of reach. The text of
                // the part above the row is asked the way a cut asks it when no caret was
                // left in it, and not by the caret itself: a caret is no reason a part
                // stops being halvable, and the row cannot know where the caret is by the
                // time it is drawn.
                if (scissors) {
                    scissors.disabled = middleCut(parts.values[index]) === -1;
                }
            });
        }

        // Which action the reader is about to send. Only «Опубликовать» reads the
        // queue rows of the parts, so only that button is held to their order: a
        // group opened from the blocks carries a drafted part with a suggested slot
        // beside a planned one of a later day, and both «Сохранить» and «Сохранить
        // по местам» leave those times to themselves. The click of a submit button
        // is what names the action.
        var partTimesEnforced = true;

        // The rows of the parts' own publication times appear with the switch of
        // the parts: the first of them carries the time the whole form was given,
        // every part below it one interval of the settings more than the part
        // above. Every row wears the same «Опубликовать», but only the first part
        // starts from the form — its button is the submit of the form itself, which
        // saves all the parts and puts the first one into the queue. A part below it
        // waits until the one above has reached the channel and is started from the
        // list of publications, so its button stays off and the line under the field
        // says so where the button itself cannot.
        function updatePartSchedules() {
            var rows = Array.prototype.slice.call(partsBox.querySelectorAll('.publication-part-schedule'));
            var enabled = partsIndividualInput.checked && textParts().length > 1;
            // The switch alone, not the count beside it: how far the text is really cut is
            // what the rule of the merge rows answers, and the two answers add up to nothing.
            partsBox.classList.toggle('publication-parts-individual', partsIndividualInput.checked);
            var base = moment(mainPublicationAt.value, pickerFormat, true);
            var previous = null;

            if (!base.isValid()) {
                base = computeNextPublicationSlot();
            }

            rows.forEach(function (row, index) {
                var input = row.querySelector('.publication-part-at');
                var button = row.querySelector('.publication-part-schedule-button');
                var hint = row.querySelector('.publication-part-schedule-hint');

                row.classList.toggle('d-none', !enabled);
                input.disabled = !enabled;
                input.id = 'publicationPartAt' + (index + 1);
                row.querySelector('.publication-part-at-label').setAttribute('for', input.id);

                if (!input.dataset.userChanged) {
                    input.value = index === 0 ? mainPublicationAt.value
                        : base.clone().add(index * __PARTS_OFFSET, 'minute').format(pickerFormat);
                }

                var time = moment(input.value, pickerFormat, true);
                var invalid = partTimesEnforced && enabled && index > 0 && (!time.isValid()
                    || (previous !== null && previous.isValid() && !time.isAfter(previous)));
                input.setCustomValidity(invalid ? 'Время части должно быть позже времени предыдущей части.' : '');
                input.classList.toggle('is-invalid', invalid);
                previous = time;

                button.disabled = !enabled || index > 0;
                hint.textContent = 'Часть становится доступной в списке публикаций после предыдущей';
                hint.classList.toggle('d-none', index === 0);

                if (index === 0) {
                    button.type = 'submit';
                    button.name = 'action';
                    button.value = 'publish';
                    button.title = 'Сохранить части и поставить первую в очередь';
                    return;
                }
                button.type = 'button';
                button.removeAttribute('name');
                button.removeAttribute('value');
            });
        }

        // A submit button says with its own value whether the queue rows are being
        // asked of the reader, so the order stops holding the form over the moment
        // «Сохранить» or «Сохранить по местам» is clicked and comes back for
        // «Опубликовать» — including a send that starts without a button at all.
        mainPublicationAt.form.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('button[name="action"]') : null;

            if (!button) {
                return;
            }

            partTimesEnforced = button.value === 'publish';
            updatePartSchedules();
        });

        mainPublicationAt.form.addEventListener('submit', function () {
            partTimesEnforced = true;
        });

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

        // An arrow of a part reaches exactly the neighbour it trades with: the first
        // of them has no part above it and the last has none below, and a form that
        // still holds one field has neither. Both stay standing, out of reach.
        function updatePartMoves() {
            var blocks = partBlocks();

            blocks.forEach(function (block, index) {
                block.querySelector('.publication-part-up').disabled = index === 0;
                block.querySelector('.publication-part-down').disabled = index === blocks.length - 1;
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
        // message has one first line. `titleFormats` is the marking of those
        // headings, counted over the heading of the same place, and it is carried
        // exactly as the headings are: a heading that stays keeps its marks.
        // `slots` is the row every part was taken out of, and it is carried the
        // same way: a part keeps the row it came with and
        // a part that appears carries none, because nothing of it was saved.
        function setTextParts(values, groups, files, formats, titles, titleFormats, slots) {
            // The blocks that are about to be replaced carry the pickers, the
            // headings, the marks of those headings, the slot, the time and the own
            // switch of every part, so all of them are read while the fields exist.
            var carriedFiles = files === undefined ? readFileGroups() : files;
            var carriedTitles = titles === undefined ? readTitles() : titles;
            var carriedTitleFormats = titleFormats === undefined ? readTitleFormats() : titleFormats;
            var carriedSlots = slots === undefined ? readSlots() : slots;
            var carriedTimes = Array.prototype.slice.call(partsBox.querySelectorAll('.publication-part-at'))
                .map(function (input) { return { value: input.value, changed: input.dataset.userChanged || '' }; });
            // The buttons of every part and the switch of the addresses each part wears
            // are read off the same blocks that are about to go: the mode hands a part a
            // list and a switch of its own, and neither of them is a thing the parts are
            // rebuilt to lose.
            var carriedButtons = readButtonGroups();
            var carriedLinks = Array.prototype.slice.call(partsBox.querySelectorAll('.publication-part-links-switch'))
                .map(function (input) { return input.checked === true; });

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
            mergeRows().forEach(function (row) { row.remove(); });
            Array.prototype.slice.call(partsBox.querySelectorAll('.publication-part-schedule')).slice(1)
                .forEach(function (row) { row.remove(); });
            values.forEach(function (value, index) {
                if (index === 0) {
                    seedPart(source, value, (formats || [])[0]);
                    return;
                }
                var block = partTemplate.cloneNode(true);
                var partAt = block.querySelector('.publication-part-at');
                partAt.value = (carriedTimes[index] || {}).value || '';
                partAt.dataset.userChanged = (carriedTimes[index] || {}).changed || '';
                var field = block.querySelector('.publication-text-part');
                field.id = 'publicationTextInput' + (index + 1);
                block.querySelector('.publication-text-label').setAttribute('for', field.id);

                // A cloned block is one part of the list of headings the form
                // submits, so it loses the name of the shared field it was
                // cloned with — and so does the field its marks travel in.
                var title = block.querySelector('.publication-title-field');
                title.id = 'publicationPartTitle' + (index + 1);
                title.name = 'publicationPartTitle[]';
                block.querySelector('.publication-title-label').setAttribute('for', title.id);
                block.querySelector('.publication-title-editor .publication-format-field')
                    .name = 'publicationPartTitleFormatting[]';

                var album = block.querySelector('.publication-part-album-field');
                album.id = 'publicationPartImages' + (index + 1);
                album.disabled = false;
                block.querySelector('.publication-part-album-label').setAttribute('for', album.id);

                // The switch of the rows is one per part and named after its place,
                // so that its label points at the toggle of the same album box.
                var rowsSwitch = block.querySelector('.publication-album-rows-switch');
                rowsSwitch.id = 'publicationPartAlbumRows' + (index + 1);
                block.querySelector('.publication-part-album .form-check-label').setAttribute('for', rowsSwitch.id);

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

                // The switch of the addresses is one per part and named after its
                // place, so that its label points at the toggle of the same frame —
                // and it comes back with the state the part was left with.
                var linksSwitch = block.querySelector('.publication-part-links-switch');
                linksSwitch.id = 'publicationPartLinksToButtons' + (index + 1);
                block.querySelector('.publication-part-button .form-check-label')
                    .setAttribute('for', linksSwitch.id);
                linksSwitch.checked = carriedLinks[index] === true;

                var mergeRow = mergeRowTemplate.cloneNode(true);
                mergeRow.classList.remove('d-none');
                partsBox.appendChild(mergeRow);
                partsBox.appendChild(block);
                setupDateTimePicker(jQuery(partAt));
                field.value = value;
                field.__formatting = (formats || [])[index] || [];
            });

            textParts().forEach(function (field, index) {
                var block = field.closest('.publication-text-block');
                var label = block.querySelector('.publication-text-label');
                label.textContent = values.length > 1 ? 'Часть ' + (index + 1) : 'Текст публикации';
                block.querySelector('.publication-part-album-field').disabled = index === 0;
                block.querySelector('.publication-part-album-files').disabled = index === 0;
                writeSlot(block, carriedSlots[index] || '');
            });

            titleFields().forEach(function (field, index) {
                field.value = carriedTitles[index] || '';
                field.__formatting = (carriedTitleFormats || [])[index] || [];
                // The heading of the first part wears the editor it was mounted with
                // and no block is cloned for it, so the marks written under its value
                // are painted by this hand — the write of the value paints the ones
                // that stood here before.
                paintEditor(field);
            });

            mountPartEditors();

            setAlbumFields(groups === undefined ? keepGroups(values.length) : groups);
            writeFileGroups(carriedFiles);
            // A rebuild gives every part a mask field of its own and an empty one,
            // which is the plain order of the album it stands in; the pictures that
            // came out of a part that no longer exists keep their place behind the
            // ones the receiving part already held.
            albumBlankRows = [];
            updateAlbumBoxes();
            syncAlbumRows();
            // A rebuild rewrites the name every clone submits its rows under, so the
            // trade between the block of the form and the block of the first part is
            // restated here — and the rows of a box are named by the base it now holds.
            applyButtonNames();
            syncPartButtons(carriedButtons);
            updateButtonBoxes();
            clearNotices();

            updateMergeRows();
            updatePartSchedules();
            updateAlbumMoves();
            updatePartMoves();
            linkifyParts();
            applyPartNumbers();
            applyHeadingNumbers();
            updateCounters();
            updateImages();
            writeFormattingFields();
            armLinksToButtons();
            placeSharedAlbum();
            redistributeOnPartCount();
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
            var headed = headingsFromParts(parts,
                formatsOfParts(parts, text, joinFormats(bare.values, bare.formats)),
                readTitles(), readTitleFormats());

            // The whole text is cut anew, so the albums of the parts come together
            // in the first of them: none of the new parts is the one a list was
            // written for.
            setTextParts(headed.values,
                asTexts(flattenGroups(readImageGroups())),
                flattenGroups(readFileGroups()),
                headed.formats, headed.titles, headed.titleFormats, undefined);

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

        // The heading of a part is its first sentence, not its first line: the split
        // can lay one part per paragraph, and the line a part opens with may hold
        // several short sentences. Where a sentence ends is the rule of the «По
        // предложениям» split, so it is read from endsSentence() rather than guessed
        // here. A sentence that is all the part holds is handed back whole, the same
        // refusal the first line of a fill makes.
        function firstSentenceAsHeading(text) {
            var raw = String(text);
            var from = raw.search(/\S/);
            var cut = -1;

            for (var index = from + 1; index < raw.length; index += 1) {
                if (endsSentence(raw, index)) {
                    cut = index;
                    break;
                }
            }

            // The heading travels in a single-line field, so a sentence running over
            // a line break arrives as one line of it.
            var heading = cut < 0 ? '' : raw.slice(from, cut).replace(/\s+/g, ' ').trim();
            var rest = cut < 0 ? '' : raw.slice(cut).replace(/^\s+/, '');

            return heading === '' || rest === ''
                ? { title: '', text: raw }
                : { title: heading, text: rest };
        }

        // The headings of the parts a split has just made: a part whose heading field
        // holds nothing of its own takes its first sentence out of its own text, and
        // whatever stands behind the sentence keeps the highlighting it had — the
        // spans of the sentence itself move into the list of marks the heading wears.
        // A heading that is already there is left alone — it is the name of a topic
        // the fill put there, or a line the reader wrote. The number of the part the
        // field opens with is not such a heading: it is the page that wrote it, and a
        // field holding nothing but it is as empty as one that holds nothing. A part
        // holding no second sentence is handed back by firstSentenceAsHeading()
        // exactly as it stood, so nothing is written for it.
        function cutHeadingsFromParts(values, formats, titles, titleFormats, on) {
            var kept = {
                values: (values || []).slice(),
                formats: (formats || []).slice(),
                titles: (titles || []).slice(),
                titleFormats: (titleFormats || []).slice()
            };

            // A text the form still keeps in one field takes its heading from its
            // first line, which is the fill above doing that, not this.
            if (!on || kept.values.length < 2) {
                return kept;
            }

            kept.values.forEach(function (value, index) {
                if (stripHeadingNumber(kept.titles[index] || '').trim() !== '') {
                    return;
                }

                var heading = firstSentenceAsHeading(value);

                // The text the part keeps is the tail of the value it had, so the
                // spans of the part are read from where that tail begins.
                kept.formats[index] = takeEntities(
                    kept.formats[index] || [],
                    value.length - heading.text.length,
                    Number.MAX_SAFE_INTEGER
                );
                // The sentence that left the text is the heading now, and the marks
                // the reader set over it go with it: they are counted from the
                // beginning of the heading from here on, and a mark the seam cuts
                // in two is cut here too. The field of such a heading was empty, so
                // nothing of its own is left behind — the list of the part's text
                // taken before the tail was rebased is the whole of it.
                kept.titleFormats[index] = takeEntities(
                    formats[index] || [],
                    0,
                    heading.title.length
                );
                kept.values[index] = heading.text;
                kept.titles[index] = heading.title;
            });

            return kept;
        }

        // The one read of the switches of the header behind the pass, so every rebuild
        // of the parts hands over its lists and says nothing of the field. The number
        // of a part goes in front of the heading its own part took, and comes off it
        // again where either of the two switches stands off. A heading that is rewritten
        // by that number carries its marks along with its words: the number is part of
        // the field, so a mark the reader set over the words stands behind it.
        function headingsFromParts(values, formats, titles, titleFormats) {
            var headed = cutHeadingsFromParts(
                values,
                formats,
                titles,
                titleFormats,
                !!(titleFromFirstLineInput && titleFromFirstLineInput.checked)
            );

            var bare = headed.titles.slice();

            headed.titles = numberHeadings(headed.titles,
                headingNumbersOn() && headed.values.length >= 2);
            headed.titleFormats = (headed.titleFormats || []).map(function (marks, index) {
                return remapEntities(marks || [], bare[index], headed.titles[index]);
            });

            return headed;
        }

        // The text of a record or a forum post arrives as one run of it, and the
        // album that comes with it belongs to its first part — the field of the
        // fill writes that one, so the parts of this list start out empty.
        // `formatting` is the highlighting the record was saved with, cut up along
        // with the text the parts are made of. `title` is the heading of the
        // record, which opens the first of those parts; a forum post has none.
        // `titleFormatting` is the marking that heading was saved with, counted over
        // the very heading the field now holds.
        // `slot` is the row the record was opened from: only the first of the parts
        // stands in it, the rest are cut out of its text here and belong to no row
        // at all yet.
        function loadText(text, formatting, title, titleFormatting, slot) {
            var parts = splitIntoParts(text);
            var headed = headingsFromParts(parts,
                formatsOfParts(parts, text, formatting || []),
                [title || ''], [titleFormatting || []]);

            setTextParts(headed.values,
                emptyGroups(parts.length),
                emptyFileGroups(parts.length),
                headed.formats,
                headed.titles,
                headed.titleFormats,
                [slot || '']);
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
        // come back to the buttons the record really holds — and a form that wears a
        // block per part holds them in the block of the first part, where the field of
        // the form sleeps.
        function fillButtons(raw) {
            writeButtons(buttonBoxForPart(0), parseButtons(raw));
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

        // The part the handle under the list of parts asks for: the one the caret was
        // written in, and without a caret the longest of them — the only part worth
        // halving on its own.
        function splitPartAtCaret() {
            var bare = bareParts();
            var index = splitTarget(bare.fields, bare.values);

            if (index === -1) {
                return;
            }

            splitPartAt(index);
        }

        // Cut one part in two. The row of a seam hands this the part it stands under,
        // the handle below the list hands it the part of the caret; both get the cut
        // at the caret of that part's own field, so a caret left in another part
        // cannot cut it.
        function splitPartAt(index) {
            var bare = bareParts();

            if (index < 0 || index >= bare.values.length) {
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
            var titleFormats = readTitleFormats();
            var slots = readSlots();

            // The part that starts below the caret begins without pictures of its
            // own: the album stays with the text it was attached to, and so does
            // the heading it opened with. Both halves were one saved record
            // before the cut, so both wear its pill — and only the half that keeps
            // the text of that row goes back to it.
            groups.splice(index + 1, 0, []);
            files.splice(index + 1, 0, []);
            titles.splice(index + 1, 0, '');
            titleFormats.splice(index + 1, 0, []);
            slots.splice(index + 1, 0, childSlotOf(slots[index]));

            var headed = headingsFromParts(bare.values.slice(0, index)
                .concat([head, tail])
                .concat(bare.values.slice(index + 1)), formats, titles, titleFormats);

            setTextParts(headed.values, asTexts(groups), files, headed.formats, headed.titles,
                headed.titleFormats, slots);

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

            var values = parts.length > 0 ? parts : [''];
            // The whole run is laid out again, so the only heading the publication
            // keeps is the one it opens with — and so are its marks.
            var headed = headingsFromParts(values,
                formatsOfParts(parts, text, joinFormats(bare.values, bare.formats)),
                [readTitles()[0] || ''], [readTitleFormats()[0] || []]);

            setTextParts(headed.values,
                asTexts(flattenGroups(readImageGroups())),
                flattenGroups(readFileGroups()),
                headed.formats, headed.titles, headed.titleFormats, undefined);
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
            // one first line, and the part it grew into keeps its own — its marks
            // come along the same way. The pill of
            // the block is kept the same way — the joined text is the record that
            // stood above, and the one below it is gone.
            var titles = readTitles();
            var titleFormats = readTitleFormats();
            var slots = readSlots();

            titles.splice(index + 1, 1);
            titleFormats.splice(index + 1, 1);
            slots.splice(index + 1, 1);

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
            setTextParts(bare.values, asTexts(groups), files, bare.formats, titles, titleFormats, slots);

            // The joined text does not have to fit one message, so it goes back
            // through the split when it stopped fitting.
            if (splitIfNeeded()) {
                return;
            }

            // The caret marks the place the two parts grew together at.
            caretOfPart(textParts()[index], seam);
        }

        // An empty part grown at one of the places a list of N parts offers — the
        // head, a seam of two parts, the tail. Nothing of it is saved anywhere, so
        // it comes without a heading, an album, picked files or a pill of its own,
        // and the parts around it keep theirs: the seven lists the rebuild reads are
        // the ones the form already holds, and only a place is opened between them.
        // Where a part lands decides whose album it splits from, so the two ends are
        // not special: `0` takes the shared field of the first part, `N` stands
        // behind the last one.
        function addPartAt(index) {
            var bare = bareParts();

            if (index < 0 || index > bare.values.length) {
                return;
            }

            var values = bare.values.slice();
            var formats = bare.formats.slice();
            var groups = alignGroups(readImageGroups(), bare.values.length);
            var files = alignGroups(readFileGroups(), bare.values.length);
            var titles = readTitles();
            var titleFormats = readTitleFormats();
            var slots = readSlots();

            values.splice(index, 0, '');
            formats.splice(index, 0, []);
            groups.splice(index, 0, []);
            files.splice(index, 0, []);
            titles.splice(index, 0, '');
            titleFormats.splice(index, 0, []);
            slots.splice(index, 0, '');

            var headed = headingsFromParts(values, formats, titles, titleFormats);

            setTextParts(headed.values, asTexts(groups), files, headed.formats, headed.titles,
                headed.titleFormats, slots);

            // The new part is the one the reader came to fill.
            caretOfPart(textParts()[index], 0);
        }

        // A part the reader invented is freed the way it was grown: the seven lists the
        // rebuild reads give up what stands at this one place, and the part itself stays
        // in the form to be filled again. Its slot is not touched — the row the part was
        // taken out of is still the row a save of this part writes into, and the pill
        // keeps naming the block it lies in. The time of a cleared part goes back to what
        // the queue of the form counts out for its place, the way a new part takes it.
        function clearPartAt(index) {
            var bare = bareParts();
            var values = bare.values.slice();
            var formats = bare.formats.slice();
            var groups = alignGroups(readImageGroups(), values.length);
            var files = alignGroups(readFileGroups(), values.length);
            var titles = readTitles();
            var titleFormats = readTitleFormats();
            var slots = readSlots();
            var partAt = partBlocks()[index].querySelector('.publication-part-at');

            values[index] = '';
            formats[index] = [];
            groups[index] = [];
            files[index] = [];
            titles[index] = '';
            titleFormats[index] = [];

            if (index === 0) {
                // The keyboard of the first part is the shared field of the form, and the
                // boxes of the parts below it are filled from that field while the
                // «Кнопка-ссылка в каждой части» switch stands on — so the field goes empty
                // before the rebuild, and the copies of it go empty with it.
                fillButtons('');
            }

            if (partAt) {
                partAt.value = '';
                delete partAt.dataset.userChanged;
            }

            var headed = headingsFromParts(values, formats, titles, titleFormats);

            setTextParts(headed.values, asTexts(groups), files, headed.formats, headed.titles,
                headed.titleFormats, slots);
        }

        // The block of a part nothing is saved under goes out of the form the way a merged
        // part does: the seven lists lose the entry of this place, and the rebuild counts the
        // numbers, renames the fields and hands over the shared album by the new places.
        function removePartAt(index) {
            var bare = bareParts();
            var values = bare.values.slice();
            var formats = bare.formats.slice();
            var groups = alignGroups(readImageGroups(), values.length);
            var files = alignGroups(readFileGroups(), values.length);
            var titles = readTitles();
            var titleFormats = readTitleFormats();
            var slots = readSlots();

            values.splice(index, 1);
            formats.splice(index, 1);
            groups.splice(index, 1);
            files.splice(index, 1);
            titles.splice(index, 1);
            titleFormats.splice(index, 1);
            slots.splice(index, 1);

            var headed = headingsFromParts(values, formats, titles, titleFormats);

            setTextParts(headed.values, asTexts(groups), files, headed.formats, headed.titles,
                headed.titleFormats, slots);
        }

        // The row a part has just taken is written back into the part: its pill names the
        // block the row lies in now, its handles read the same slot, and the next save of
        // the form sends the part to that very row.
        function takePartRow(payload, block, habitat) {
            if (!payload || !payload.ok || typeof payload.partId !== 'number') {
                return;
            }

            writeSlot(block, JSON.stringify({ id: payload.partId, source: habitat, habitat: habitat }));
        }

        // A part that holds a row is moved by the very request a row of a list posts, so
        // the block it lands in, the id it takes there and the debt a message already sent
        // leaves behind are decided by the server exactly as they are for a record.
        function movePartRow(block, url, held, habitat) {
            postForBlocks(url, {
                publicationId: held.id,
                publicationSource: held.source,
            }).then(function (payload) {
                takePartRow(payload, block, habitat);
            });
        }

        // A part with no row of its own is saved the way a whole form is saved: its text,
        // heading, album and keyboard go over exactly as the reader left them, so the
        // request carries the fields of the form and the place of this part among them.
        // That one part reaches a row; the rest of the form waits as it waited before.
        function savePartRow(block, index) {
            // A part saved on its own goes out over the same fields the form goes out
            // with, so the marks of the heading block are put in here too.
            writeFormattingFields();

            var fields = new FormData(block.closest('form'));

            fields.append('publicationPartIndex', index);

            postForBlocks(__PART_DRAFT_URL, fields).then(function (payload) {
                takePartRow(payload, block, 'draft');
            });
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

        // A part traded with its neighbour: the seven lists the parts are rebuilt from
        // exchange the two places the move touches, and the rebuild does the rest —
        // it counts the numbers again, names the fields and the labels by the new
        // places, and hands the shared album of the first part over to whichever part
        // now stands first. The time of a row stays on the place it was given, so a
        // part that moved takes the schedule of the place it came to, not its own.
        function movePart(button, step) {
            var blocks = partBlocks();
            var index = blocks.indexOf(button.closest('.publication-text-block'));
            var other = index + step;

            if (index < 0 || other < 0 || other >= blocks.length) {
                return;
            }

            var bare = bareParts();
            var groups = alignGroups(readImageGroups(), bare.values.length);
            var files = alignGroups(readFileGroups(), bare.values.length);
            var titles = readTitles();
            var titleFormats = readTitleFormats();
            var slots = readSlots();
            // The caret is counted over what stands in the field, where the line of
            // the number goes before the text of the part; a caret left inside that
            // line stands at the head of the text it moves to.
            var at = Math.max(0, (bare.fields[index].selectionStart || 0)
                - (bare.fields[index].value.length - bare.values[index].length));

            exchange(bare.values, index, other);
            exchange(bare.formats, index, other);
            exchange(groups, index, other);
            exchange(files, index, other);
            exchange(titles, index, other);
            exchange(titleFormats, index, other);
            exchange(slots, index, other);

            setTextParts(bare.values, asTexts(groups), files, bare.formats, titles, titleFormats, slots);

            // The caret marks the part that moved, now standing in its new place.
            caretOfPart(textParts()[other], at);
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

        // The popup goes above the anchor, below it when the top of the viewport is in
        // the way, and inside the viewport on both axes. An anchor carries the two
        // edges a popup stands by: a caret is a line, so both of them are that line,
        // while a control has a top the popup clears from above and a bottom it clears
        // from below.
        function popupPoint(anchor, size, viewport) {
            var margin = 8;
            var left = Math.max(margin, Math.min(
                anchor.x - size.width / 2,
                viewport.width - size.width - margin
            ));
            var top = anchor.y - size.height - margin;

            if (top < margin) {
                top = anchor.bottom + margin;
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
                return { x: 0, y: 0, bottom: 0 };
            }

            var box = root.getBoundingClientRect();
            var bounds = editor.getBounds(
                Math.max(0, Math.min(position, editor.getLength() - 1)),
                0
            ) || { left: 0, top: 0, height: 0 };
            var line = box.top + bounds.top + bounds.height;

            return { x: box.left + bounds.left, y: line, bottom: line };
        }

        // The box of a control read as an anchor: its middle, which the arrow of a
        // popup has to sit under, and its two edges.
        function buttonPoint(button) {
            var box = button.getBoundingClientRect();

            return { x: box.left + box.width / 2, y: box.top, bottom: box.bottom };
        }

        function showSelectionActions(field) {
            var selection = selectionRange(field);
            var parts = textParts();
            var index = parts.indexOf(field);

            if (!selectionPopup || parts.length < 2 || index < 0
                || selection.text.slice(selection.from, selection.to).trim() === '') {
                hideSelectionActions();

                return;
            }

            // A handle naming a neighbour the form has not got is left out of the popup: the
            // first part has nothing above it, the last nothing below, and the move behind them
            // would invent a part nobody asked for. Taken away rather than stopped — the word
            // was that the button is not there — and both of them are asked every time, so a
            // handle hidden for one part comes back for the next one.
            movePrevPartButton.classList.toggle('d-none', index === 0);
            moveNextPartButton.classList.toggle('d-none', index === parts.length - 1);

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
            var titleFormats = readTitleFormats();
            var slots = readSlots();

            if (moved.values.length === bare.values.length + 1) {
                groups.splice(moved.index, 0, []);
                files.splice(moved.index, 0, []);
                titles.splice(moved.index, 0, '');
                titleFormats.splice(moved.index, 0, []);
                slots.splice(moved.index, 0, '');
            } else if (moved.values.length === bare.values.length - 1) {
                var joined = forward ? index : index - 1;
                groups = spliceImageGroups(groups, joined);
                files = spliceImageGroups(files, joined);
                titles.splice(joined + 1, 1);
                titleFormats.splice(joined + 1, 1);
                slots.splice(joined + 1, 1);
            }

            setTextParts(moved.values, asTexts(groups), files, moved.formats, titles, titleFormats, slots);

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
        var albumRowsInput = document.getElementById('publicationAlbumRows');
        var sharedAlbumRowsBox = document.getElementById('publicationAlbumRowsBox');
        var imagesOrderInput = document.getElementById('publicationImageOrder');
        var albumBlock = document.getElementById('publicationAlbumBlock');
        var albumHome = document.getElementById('publicationAlbumHome');
        var albumRowTemplate = sharedAlbumRowsBox
            ? sharedAlbumRowsBox.querySelector('.publication-album-row').cloneNode(true)
            : null;
        var publicationAtInput = document.getElementById('publicationAt');
        var sourceTypeInput = document.getElementById('publicationSource');
        var sourceIdInput = document.getElementById('publicationSourceId');
        var publishedAtInput = document.getElementById('publicationAt');
        var scheduleModal = document.getElementById('scheduleModal');

        // The field and its calendar are two views of one moment: a value written
        // without moving the picker leaves the dialog that opens over the form on the
        // day the field does not name. The corner each card carries is read from the
        // same field, so a value written here redraws it — `update` is a later value
        // of this scope and every call of this function is made after it stands.
        function applyPublicationSlot(value) {
            if (publishedAtInput) {
                publishedAtInput.value = value;
            }
            try {
                var picker = publicationAtJq.data('daterangepicker');
                if (picker && value) {
                    var m = moment(value, pickerFormat);
                    if (m.isValid()) {
                        picker.setStartDate(m);
                        picker.setEndDate(m.clone().add(__HORIZON_HOURS, 'hour'));
                    }
                }
            } catch (e) {}
            update();
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

        // The album of a part as the channel draws it: the tiles its own count of
        // pictures asks for, the last of them counting whatever the grid has no
        // room for. The classes carry the layout — albumLayout() has already
        // decided it — and the sheet says what each of them means.
        var renderAlbumGrid = function (pictures) {
            var layout = albumLayout(pictures.length);
            var grid = document.createElement('div');
            grid.className = 'publication-preview-album album-' + layout.shown;

            pictures.slice(0, layout.shown).forEach(function (picture, index) {
                var cell = document.createElement('div');
                cell.className = 'publication-album-cell';

                var img = document.createElement('img');
                img.src = pictureUrl(picture);
                img.alt = 'Изображение публикации';
                cell.appendChild(img);

                if (index === layout.shown - 1 && layout.extra > 0) {
                    var more = document.createElement('span');
                    more.className = 'publication-album-more';
                    more.textContent = '+' + layout.extra;
                    cell.appendChild(more);
                }

                grid.appendChild(cell);
            });

            return grid;
        };

        // The slider a click on a strip opens. One node stands in the markup of
        // every album frame, so a part block is cloned with its own; the node is
        // named when it opens and the same name is written into the controls of
        // that node alone, because two sliders of one form may not answer to one
        // id. The album of the slider is the whole album of its field — the strip
        // only has room for a few of its pictures.
        var stripOpen = null;
        var carouselSeq = 0;

        function albumCarouselOf(strip) {
            var node = strip.nextElementSibling;

            return node && node.classList.contains('publication-album-carousel') ? node : null;
        }

        function stripPictures(strip) {
            var pictures = albumPictures();

            if (strip === imagesPreview) {
                return pictures[0] || [];
            }

            var index = albumStrips().indexOf(strip);

            return index === -1 ? [] : (pictures[index + 1] || []);
        }

        function slideKey(pictures) {
            return pictures.map(pictureUrl).join('\n');
        }

        function fillAlbumCarousel(node, pictures) {
            var id = 'publicationAlbumCarousel' + (carouselSeq += 1);
            var many = pictures.length > 1;
            var inner = node.querySelector('.carousel-inner');
            var marks = node.querySelector('.carousel-indicators');

            node.id = id;
            inner.textContent = '';
            marks.textContent = '';

            pictures.forEach(function (picture, index) {
                var item = document.createElement('div');
                item.className = 'carousel-item' + (index === 0 ? ' active' : '');

                var img = document.createElement('img');
                img.className = 'd-block w-100 rounded-2 bg-light-subtle publication-album-slide';
                img.src = pictureUrl(picture);
                img.alt = 'Изображение публикации';
                item.appendChild(img);
                inner.appendChild(item);

                if (!many) {
                    return;
                }

                var mark = document.createElement('button');
                mark.type = 'button';
                mark.setAttribute('data-bs-target', '#' + id);
                mark.setAttribute('data-bs-slide-to', index);
                mark.setAttribute('aria-label', 'Изображение ' + (index + 1));
                if (index === 0) {
                    mark.className = 'active';
                    mark.setAttribute('aria-current', 'true');
                }
                marks.appendChild(mark);
            });

            // A single picture has nothing to slide to, so the arrows and the marks
            // of the strip's slider stay out of the way of the picture itself.
            node.querySelectorAll('.carousel-control-prev, .carousel-control-next')
                .forEach(function (control) {
                    control.setAttribute('data-bs-target', '#' + id);
                    control.classList.toggle('d-none', !many);
                });
        }

        // The node is drawn whole every time it opens, so the instance of the
        // drawing before it goes with the slides it had already counted.
        function dropAlbumCarousel(node) {
            if (typeof bootstrap === 'undefined' || !bootstrap.Carousel || !node) {
                return;
            }

            var held = bootstrap.Carousel.getInstance(node);
            if (held) {
                held.dispose();
            }
        }

        function openAlbumSlider(strip, index) {
            var node = albumCarouselOf(strip);
            var pictures = stripPictures(strip);

            if (!node || !pictures.length || typeof bootstrap === 'undefined' || !bootstrap.Carousel) {
                return;
            }

            // One album is being looked at at a time: the slider of another album
            // goes away before this one is drawn.
            if (stripOpen && stripOpen.album !== strip) {
                closeAlbumSlider();
            }

            dropAlbumCarousel(node);
            fillAlbumCarousel(node, pictures);
            node.classList.remove('d-none');
            stripOpen = { album: strip, index: index, slides: slideKey(pictures) };
            new bootstrap.Carousel(node, { ride: false, wrap: true, touch: true }).to(index);
        }

        function closeAlbumSlider() {
            if (!stripOpen) {
                return;
            }

            var node = albumCarouselOf(stripOpen.album);
            dropAlbumCarousel(node);
            if (node) {
                node.classList.add('d-none');
            }
            stripOpen = null;
        }

        // An album changes while its slider stands open: a link typed, a file
        // picked, the album handed to a neighbour. The redraw draws it again and
        // keeps the picture that was being looked at — or the last one left, when
        // the album shrank under it. A field that lost its album, and a block that
        // left the form with its part, close the slider they held.
        function refreshAlbumSliders() {
            if (!stripOpen) {
                return;
            }

            var strip = stripOpen.album;
            var pictures = stripPictures(strip);

            if (!pictures.length || !document.contains(strip)) {
                closeAlbumSlider();
                return;
            }

            if (slideKey(pictures) === stripOpen.slides) {
                return;
            }

            openAlbumSlider(strip, Math.min(stripOpen.index, pictures.length - 1));
        }

        // A picture of a strip opens the slider of its album on that picture, and
        // the picture the slider already shows hides it again; the mark of the
        // pictures the strip had no room for opens it on the first of those.
        function watchAlbumStrips(node) {
            node.addEventListener('click', function (event) {
                var mark = event.target.closest('.publication-album-strip img, .publication-album-strip .plus');
                if (!mark) {
                    return;
                }

                var strip = mark.closest('.publication-album-strip');
                var shown = strip.querySelectorAll('img');
                var index = mark.tagName === 'IMG'
                    ? Array.prototype.indexOf.call(shown, mark)
                    : shown.length;
                var action = stripAction(stripOpen, { album: strip, index: index });

                if (action === 'close') {
                    closeAlbumSlider();
                    return;
                }

                if (action === 'slide') {
                    var slider = bootstrap.Carousel.getInstance(albumCarouselOf(strip));
                    if (slider) {
                        slider.to(index);
                        stripOpen.index = index;
                    }

                    return;
                }

                openAlbumSlider(strip, index);
            });
        }

        watchAlbumStrips(imagesPreview.parentNode);
        watchAlbumStrips(partsBox);

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
            refreshAlbumSliders();
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

        // What a notice says goes into the holder of its own: the notice is the box
        // the cross stands in, and writing the text of the box would take the cross
        // away with it.
        var renderNotice = function (notice, deadUrls) {
            if (!notice) {
                return;
            }
            if (deadUrls.length === 0) {
                notice.classList.add('d-none');

                return;
            }
            notice.querySelector('.publication-notice-text').textContent =
                'Мёртвые ссылки в изображения не добавлены ('
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
            notice.querySelector('.publication-notice-text').textContent = lines.join(' ');
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
            // A file that came into the picker comes into the rows of the same
            // album, behind the pictures the mask already knew.
            syncAlbumRows();
        };

        // The whole album goes into a field at once, so a form submitted while
        // the links are still being probed cannot lose a live image; the field
        // narrows to the ones that answered as soon as all of them have.
        // `fromFill` marks an album that came in from outside — a post of the forum, a
        // thread — and not from the hand of the reader: only such a write asks the mode
        // of the distribution to hand the pictures out. The question is settled by the
        // probe and not by the call: until the dead addresses are cut out of the field,
        // the album this fill wrote is not the one the parts are fed from.
        var writeImages = function (index, raw, fromFill) {
            if (!imageTargets()[index]) {
                return;
            }
            var urls = parseImageUrls(raw);
            var writes = albumWrites[index] || 0;

            writeImageGroup(index, urls);
            renderNotice(albumNotices()[index], []);
            // An album written from outside is a new album: the rows of it start at
            // the plain order, and the blanks the reader had opened with the old
            // addresses are gone with them.
            forgetAlbumBlanks(index);
            writeAlbumMask(index, []);
            updateImages();
            syncAlbumRows();
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
                syncAlbumRows();
                if (fromFill) {
                    distributeImages();
                }
            });
        };

        // A fill brings its album along with one text: until the parts of it are
        // known the links stand in the album of the first part, which is the
        // shared field of the form.
        var fillImages = function (raw) {
            writeImages(0, raw, true);
        };

        // The albums of every part handed out over the parts of the form the way the
        // channel will receive them: the pictures of all the albums are pooled in the
        // order of the parts, the slices come out contiguous and differ in size by at
        // most one image. Thrown on, the switch is a mode and not a word spent — the
        // pool is cut again whenever a fill brings an album in or the parts change.
        var distributedOver = 0;
        var distributeImages = function () {
            if (!distributeImagesInput || !distributeImagesInput.checked) {
                return;
            }
            var many = imageTargets().length;
            var pool = [];

            for (var index = 0; index < many; index++) {
                pool = pool.concat(readAlbumOrder(index));
            }

            if (many < 2 || pool.length === 0) {
                return;
            }

            // The order of the pool is what is handed out, so a file the reader stood
            // before a link keeps that place in the part it comes to, and the mask of
            // every part is written from the list its rows show.
            groupImages(pool, many).forEach(function (pictures, place) {
                forgetAlbumBlanks(place);
                setAlbumOrder(place, pictures);
            });
            distributedOver = many;
            updateImages();
            syncAlbumRows();
        };

        // The rebuild of the parts asks the mode again: the number of the albums the
        // pictures go into is what changed, and by the time this runs the boxes of the
        // new parts already stand in the DOM. A rebuild that leaves the number alone is
        // no reason to cut — the reader may have laid the pictures out by hand since.
        var redistributeOnPartCount = function () {
            if (imageTargets().length === distributedOver) {
                return;
            }
            distributeImages();
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

        // The moment the channel will put in the corner of a part: the slot of the
        // form moved on by the gap the settings page keeps between the parts, which
        // is the same shift PublicationsService::saveParts() makes while writing the
        // records. The number it is shifted by is the place of the part field in the
        // form, not the place of the card: a part with neither text nor pictures
        // draws no card and still holds its moment. A part that has left the day of
        // the slot names that day beside its time, because «00:20» of its own would
        // read as the night the publication began, and the day comes in the tokens
        // the portal was set to. A field that names no moment — empty, half-written,
        // or not a date at all — promises no corner.
        function previewTimeOf(slotValue, index) {
            var slot = moment(slotValue, __PICKER_FORMAT, true);
            if (!slot.isValid()) {
                return null;
            }
            var at = slot.clone().add(index * __PARTS_OFFSET, 'minute');

            return {
                time: at.format('HH:mm'),
                date: at.format('YYYY-MM-DD') === slot.format('YYYY-MM-DD')
                    ? ''
                    : at.format(__PICKER_FORMAT.split(' ')[0]),
            };
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
            var titleMarks = readTitleFormats();
            var shown = [];

            partValues().forEach(function (value, index) {
                var pictures = albums[index] || [];
                if (partIsShown(value, pictures)) {
                    // Every card shows the message the channel will get: the
                    // heading of the part over its text, wearing the marks the
                    // reader set over it or, where he set none, the boxes the
                    // block of the heading stands with, and the number of a
                    // numbered part at its end.
                    var message = composedMessage(
                        titles[index] || '', value, formattingOf(fields[index]), titleMarks[index] || [],
                        headingBlockFormats());
                    shown.push({
                        text: message.text,
                        pictures: pictures,
                        entities: message.entities,
                        buttons: buttons[index] || [],
                        index: index,
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
                    index: 0,
                });
            }

            var slotValue = publicationAtInput ? publicationAtInput.value : '';

            preview.textContent = '';
            shown.forEach(function (one) {
                var part = document.createElement('div');
                part.className = 'event-content bg-light-subtle rounded-3 p-3 flex-grow-1 telegram-preview-text';
                // The hour of the part and the buttons that may stand under it are
                // read before the album is drawn, because an album fills the whole
                // bubble only while nothing comes after it.
                var at = previewTimeOf(slotValue, one.index);
                var keyboard = renderPreviewKeyboard(one.buttons);
                // The photos go first and the text of the part is their caption —
                // in one message of the channel and in several the same way.
                if (one.pictures.length > 0) {
                    var album = renderAlbumGrid(one.pictures);
                    if (one.text === '' && !at && !keyboard) {
                        album.classList.add('album-only');
                    }
                    part.appendChild(album);
                }
                // A part of pictures and nothing else has no text line: that is
                // exactly how the channel shows an album without a caption.
                if (one.text !== '') {
                    var body = document.createElement('p');
                    body.className = 'publication-preview-part';
                    renderFormattedText(body, one.text, one.entities);
                    part.appendChild(body);
                }
                // The hour the card will carry in the channel, in the corner under
                // its text and above its buttons — where a client puts it.
                if (at) {
                    var corner = document.createElement('span');
                    corner.className = 'telegram-preview-time';
                    corner.textContent = (at.date === '' ? '' : at.date + ' ') + at.time;
                    part.appendChild(corner);
                }
                if (keyboard) {
                    part.appendChild(keyboard);
                }
                preview.appendChild(part);
            });
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
            // The text of a part is what its seam answers to, so the scissors of that
            // seam are re-asked by the same keystroke that moves the counters.
            updateMergeRows();
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
            // A mark clicked over a heading leaves its words untouched, so the write
            // of the mirror carries no text of a part and none of the hands below
            // writes the field the marks of this heading travel in.
            writeFormattingField(event.target);
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
        // The hand every control of the numbering is answered by: the switch of the
        // numbering, the switch of the header, the pair of radios under it, and the two
        // watchers that put a state the server refused back. The gate goes first, because
        // the end a number is written at is decided by the radios the click has just
        // greyed, and a form whose numbering was thrown off greys them before its fields
        // are written bare again.
        function renumberParts() {
            syncHeadingTemplateSwitch();
            applyPartNumbers();
            applyHeadingNumbers();
            // The stamp of the block is counted over the words a field holds, and the
            // numbers above put a word into two of them: the fields have to be written
            // again after the headings grew or lost their number, or the marks are
            // stored short of the line the heading is saved as.
            writeFormattingFields();
            updateCounters();
            update();
        }
        partsBox.addEventListener('focusout', hideSelectionActions);
        if (numberPartsInput) {
            numberPartsInput.addEventListener('change', function () {
                splitIfNeeded();
                renumberParts();
            });
        }
        // The same click on the switch of the header writes the number of the parts
        // into the fields of the headings or takes it off them: the two switches stand
        // behind one rule, so the rule is rewritten in both of their hands, and the
        // headings themselves are left as the split made them — a sentence is cut out
        // of a text where the parts are made, not where a switch is clicked. The line
        // of the number asks this switch as well, so the click that takes the number
        // off a heading puts it back at the head of the text of its part: a part
        // cannot be left with no number in either of the two places.
        if (titleFromFirstLineInput) {
            titleFromFirstLineInput.addEventListener('change', function () {
                renumberParts();
            });
        }
        mainPublicationAt.addEventListener('change', updatePartSchedules);
        partsBox.addEventListener('input', function (event) {
            if (event.target.classList.contains('publication-part-at')) {
                event.target.dataset.userChanged = '1';
                updatePartSchedules();
            }
        });
        // A time chosen in the calendar of a part is a time the person gave that part, but
        // the library writes it into the field without an `input` event of its own. Without
        // this line the flag above stays off the choice, and the next redraw of the rows —
        // a switch thrown, a part added or merged — pours the suggested time back over it.
        jQuery(partsBox).on('apply.daterangepicker', '.publication-part-at', function () {
            this.dataset.userChanged = '1';
            updatePartSchedules();
        });
        // The rows of an album live in the same frame as the fields they arrange, so
        // the frame of the shared block and the box of the parts answer for them
        // alike: an address typed into a row, an icon clicked in one, and the switch
        // that shows the rows of that album only.
        [imagesInput ? imagesInput.closest('.publication-field-frame') : null, partsBox]
            .forEach(function (node) {
                if (!node) {
                    return;
                }

                node.addEventListener('input', takeAlbumLinkEdit);
                node.addEventListener('click', takeAlbumRowClick);
                node.addEventListener('click', takeAlbumAdd);
                node.addEventListener('change', takeAlbumRowsSwitch);
            });
        // The preview answers to the buttons of the first part as it does to its
        // text: a row of the shared field is read on every keystroke, and the parts
        // that were handed these buttons follow it there.
        sharedButtonBox.addEventListener('input', function (event) {
            if (event.target.closest('.publication-button-row')) {
                syncPartButtons();
                update();
            }
        });
        // An icon of a box of the parts adds a row to that box, moves the row its own
        // icon stands in up or down the list, or takes that row out of the form, the
        // cloned boxes included.
        function takeButtonClick(box, event) {
            if (event.target.closest('.publication-button-add')) {
                addButtonRow(box);
            } else if (event.target.closest('.publication-button-up')) {
                moveButtonRow(event.target.closest('.publication-button-up'), -1);
            } else if (event.target.closest('.publication-button-down')) {
                moveButtonRow(event.target.closest('.publication-button-down'), 1);
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
        // The switch of the addresses a part wears in its own frame owns the waiting of
        // that part: thrown on, it starts the count over the next text change; thrown
        // off, it leaves the rows of that part alone. Nothing of the part is redrawn for
        // it, because the rows of a frame stand in it whether the fill is armed or not.
        partsBox.addEventListener('change', function (event) {
            if (event.target.classList.contains('publication-part-links-switch')) {
                armLinksToButtons();
            }
        });
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
        // One listener for the rows of every part, including the cloned ones. A row
        // holds two handles of the pair it stands between: the scissors cut the part
        // above the row, the collapse joins that pair back into one field.
        partsBox.addEventListener('click', function (event) {
            var row = event.target.closest('.publication-merge-row');

            if (!row) {
                return;
            }

            if (event.target.closest('.publication-seam-split-btn')) {
                splitPartAt(mergeRows().indexOf(row));

                return;
            }

            if (event.target.closest('.publication-merge-btn')) {
                mergeParts(mergeRows().indexOf(row));
            }
        });
        // A row of the group grows an empty part where it stands: above the first
        // block, below the last one, and in the seam of every two of them. The two
        // rows of the ends are drawn once in the markup, so the listener goes on the
        // group that holds them together with the box the seams are rebuilt in.
        partsGroup.addEventListener('click', function (event) {
            var button = event.target.closest('.publication-add-part-btn');

            if (!button) {
                return;
            }

            if (button.getAttribute('data-add-part') === 'seam') {
                addPartAt(mergeRows().indexOf(button.closest('.publication-merge-row')) + 1);

                return;
            }

            addPartAt(button.getAttribute('data-add-part') === 'first' ? 0 : partBlocks().length);
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
        // The arrows that trade a part with its neighbour, of the cloned parts too.
        partsBox.addEventListener('click', function (event) {
            var button = event.target.closest('[data-move-part]');

            if (!button) {
                return;
            }

            movePart(button, parseInt(button.getAttribute('data-move-part'), 10));
        });
        // The three handles of every part, the cloned parts included. A part with a row
        // behind it is moved by the server, and a part with no row is answered by the form
        // alone: its fields go empty or its block goes out, since there is no record to
        // carry anywhere. The handle that would move a part into the block it already
        // stands in is off, and says why in its own title.
        partsBox.addEventListener('click', function (event) {
            var button = event.target.closest('[data-part-action]');

            if (!button) {
                return;
            }

            var block = button.closest('.publication-text-block');
            var index = partBlocks().indexOf(block);
            var field = block.querySelector('.publication-part-slot');
            var held = parseSlot(field ? field.value : '');
            var action = button.getAttribute('data-part-action');

            if (action === 'clear') {
                clearPartAt(index);

                return;
            }

            if (action === 'delete') {
                if (held.id === null) {
                    removePartAt(index);

                    return;
                }

                movePartRow(block, __DELETE_URL, held, 'deleted');

                return;
            }

            if (held.id === null) {
                savePartRow(block, index);

                return;
            }

            movePartRow(block, __TO_DRAFT_URL, held, 'draft');
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
            publicationAtInput.addEventListener('input', update);
            publicationAtInput.addEventListener('change', update);
            publicationAtJq
                .off('.previewPub')
                .on('apply.daterangepicker.previewPub hide.daterangepicker.previewPub cancel.daterangepicker.previewPub', function () {
                    update();
                });
        }
        // The part the form starts with is not written by anyone, so its editor
        // is mounted here rather than by setTextParts.
        mountPartEditors();
        update();
        updateCounters();
        updateImages();
        syncAlbumRows();
        // A record that opens already cut has its parts before the page has painted any, so
        // the album is carried into the first of them here rather than by a rebuild.
        placeSharedAlbum();

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
            // The submit is the moment the marks of the heading block are asked for, so
            // the lists are written out again over the fields as they stand: a heading
            // that grew since the last click is stamped to its end.
            writeFormattingFields();
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

        // The parts of one publication laid out in the form as the server returned
        // them: every part brings the text, the heading, the highlighting, the
        // album, the keyboard and the time it was saved with, together with the
        // block it lives in now — which its own field row says with a pill. The
        // first of them is served by the shared fields of the form, so its album
        // goes under the list and its buttons into the shared box, exactly as the
        // rebuild of any other part does.
        function fillGroupParts(parts) {
            var slots = parts.map(function (part) {
                return JSON.stringify({ id: part.id, source: part.source, habitat: part.habitat });
            });
            var groups = parts.map(function (part) {
                return parseImageUrls(part.images || '');
            });
            var keyboards = parts.map(function (part) {
                return parseButtons(part.buttons);
            });
            var headed = headingsFromParts(
                parts.map(function (part) { return part.text || ''; }),
                parts.map(function (part) { return parseFormatting(part.formatting); }),
                parts.map(function (part) { return part.title || ''; }),
                parts.map(function (part) { return parseFormatting(part.titleFormatting); })
            );

            // Parts are individual here by the fact of standing in the lists at all,
            // and a keyboard belongs to the part that was saved with it, not to the
            // whole publication.
            if (partsIndividualInput) {
                partsIndividualInput.checked = true;
            }

            setTextParts(headed.values, asTexts(groups), emptyFileGroups(parts.length),
                headed.formats, headed.titles, headed.titleFormats, slots);

            // A keyboard belongs to the part that was saved with it, and the part takes
            // it into the box that answers for it: the block of the form while the mode
            // sleeps, the frame of that part as soon as the mode stands. The old switch
            // has nothing to hand out in that mode — every part already has its own.
            if (!buttonsPerPartOn() && keyboards.some(function (list) { return list.length > 0; })
                    && buttonEveryPartInput) {
                buttonEveryPartInput.checked = true;
            }
            keyboards.forEach(function (list, index) {
                writeButtons(buttonBoxForPart(index), list);
            });
            updateButtonBoxes();

            // A time a part was saved with is a time the reader gave that part, so
            // the row of the part takes it instead of the interval the page would
            // suggest, and says so with the flag that keeps the suggestion off it.
            // The first part also hands its time to the field of the whole form.
            Array.prototype.slice.call(partsBox.querySelectorAll('.publication-part-at'))
                .forEach(function (input, index) {
                    var at = utcToPickerValue((parts[index] || {}).publishedAt || '');

                    if (at === '') {
                        return;
                    }
                    input.value = at;
                    input.dataset.userChanged = '1';

                    if (index === 0) {
                        applyPublicationSlot(at);
                    }
                });
            updatePartSchedules();
        }

        // The group the form carries and the button that saves each part where it
        // lies are one state: a form without a group has one record, and updating
        // it in place would be the ordinary «Сохранить».
        function setPartGroupId(groupId) {
            if (partGroupIdInput) {
                partGroupIdInput.value = groupId;
            }

            if (saveInPlaceButton) {
                saveInPlaceButton.classList.toggle('d-none', groupId === '');
            }
        }

        // The neighbours of a clicked row, asked of the server and put into the
        // form in the place of the one record it was filled with: a part of a
        // group is never edited alone, the whole publication opens. The parts that
        // were deleted beside the clicked one stay in their block — a row comes back
        // from the deleted list one at a time.
        function loadGroupParts(groupId) {
            return postForJson(__PART_GROUP_URL, {
                publicationPartGroupId: groupId,
                publicationSource: sourceTypeInput ? sourceTypeInput.value : 'new',
                publicationId: sourceIdInput ? sourceIdInput.value : ''
            }).then(function (payload) {
                if (!payload.ok || !Array.isArray(payload.parts) || payload.parts.length === 0) {
                    // The neighbours did not come, so the form holds only the row
                    // that was clicked. The group id goes with them: a save of this
                    // form rewrites the one record it really carries rather than
                    // rebuilding the whole group from a single part of it.
                    setPartGroupId('');

                    return;
                }

                fillGroupParts(payload.parts);
            }).catch(function (error) {
                setPartGroupId('');

                showFlash('error', 'Не удалось загрузить части публикации: '
                    + (error && error.message ? error.message : error));
            });
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
                log.getAttribute('data-title') || '',
                parseFormatting(log.getAttribute('data-title-formatting')),
                slotOfRow(log)
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
                applyPublicationSlot(newVal);
            }
            if (forumTypeInput && forumIdInput) {
                forumTypeInput.value = '';
                forumIdInput.value = '';
            }

            // The block this row stands in holds one part of a publication that
            // continues in the other two, so the rest of its parts are asked for
            // and take the place of the single record the form was filled with.
            // A row of its own wipes the group the previous row named: without
            // this the save of a plain record would answer to the last group the
            // reader looked at.
            var groupId = log.getAttribute('data-part-group-id') || '';

            setPartGroupId(groupId);

            if (groupId !== '') {
                loadGroupParts(groupId);
            }
        };

        var resetForm = document.querySelector('form[action*="publication-create"]');
        if (resetForm) {
            watchNoticeDismiss(resetForm);
            resetForm.addEventListener('submit', function (event) {
                // A part the user made too long by hand is broken again right
                // before the fields are read for the request.
                splitIfNeeded();
                // Parts make one group of a publication only when the reader asked
                // for it and the text really is cut: a draft and a plain
                // publication carry no word about a group, so the field of the
                // parts goes empty with them.
                var group = partsIndividualInput.checked && textParts().length > 1
                    && sourceTypeInput.value === 'new'
                    && event.submitter && event.submitter.value === 'publish';
                individualPartsInput.value = group ? '1' : '';
                if (group) {
                    if (!partGroupIdInput.value) {
                        setPartGroupId(Array.from(crypto.getRandomValues(new Uint8Array(16)))
                            .map(function (value) { return value.toString(16).padStart(2, '0'); }).join(''));
                    }
                    updatePartSchedules();
                }
                applyPartNumbers();
                applyHeadingNumbers();
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
            // A slider of an album goes with the album the form loses here, and it
            // goes before the blocks are rebuilt: the node it stands in may be the
            // one the rebuild takes out of the page.
            closeAlbumSlider();
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
            setTextParts([''], [''], [], undefined, [''], [[]], ['']);
            // The group the form has saved is not the group of the next one: its
            // id has gone into the records with the parts themselves, and a time
            // the reader typed for a part of that group is no time of this form.
            Array.prototype.slice.call(partsBox.querySelectorAll('.publication-part-at')).forEach(function (input) {
                input.value = '';
                delete input.dataset.userChanged;
            });
            setPartGroupId('');
            if (individualPartsInput) {
                individualPartsInput.value = '';
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
                applyPublicationSlot(computeNextPublicationSlot().format(pickerFormat));
            }
        };

        // The reset a save runs is offered by hand too. Asking for it is asking for the
        // blank form the page opens in, so the record taken over goes with the fields:
        // keeping its id would let the next submit write over a record nobody meant to
        // change. The states that live in the session — eight switches, the two groups of
        // radios under the numbering and the template field beside them — keep their state,
        // as they do after a save: the reader left the page with them on purpose.
        var clearFormButton = document.getElementById('publicationClearBtn');
        if (clearFormButton) {
            clearFormButton.addEventListener('click', function () {
                clearEditingState();
                showFlash('success', 'Форма очищена.');
                source.focus();
            });
        }

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
            var formData = new FormData(form, event.submitter);
            postForBlocks(form.getAttribute('action'), formData)
                .then(function (payload) {
                    if (!payload || !payload.ok) {
                        // A record the server did not take leaves the form as the reader
                        // filled it — its time included, since that is what they will fix
                        // the text around and send again.
                        return;
                    }
                    if (form.hasAttribute('data-clear-editing')) {
                        // The blank form the page returns to carries the moment the next
                        // record would go out at, so this reset is where the field of
                        // «Дата и время публикации» takes a fresh slot — and the only place.
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
            loadText(heading.text, undefined, heading.title, undefined, forumSlot());
            fillImages(btn.getAttribute('data-image-urls') || '');
            if (sourceTypeInput && sourceIdInput) {
                sourceTypeInput.value = 'new';
                sourceIdInput.value = '';
            }
            // The text a fill of the forum brings is no part of any group, and the
            // parts of it carry no id: the group a previously clicked row named has
            // to leave the form with that row, or «Сохранить по местам» would stay
            // visible over these parts and write them into the stale group.
            setPartGroupId('');
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
                    var headed = headingsFromParts([heading.text].concat(parts.slice(1)),
                        [], [heading.title], [[]]);
                    setTextParts(headed.values,
                        undefined, undefined, headed.formats, headed.titles, headed.titleFormats,
                        headed.values.map(function () { return forumSlot(); }));
                    asTexts(threadImageGroups(entities)).forEach(function (raw, index) {
                        writeImages(index, raw, true);
                    });
                } else {
                    // The thread is one text here, so its pictures are one album
                    // of the first part.
                    var whole = firstLineAsHeading(threadTexts(entities).join("\n\n"));
                    loadText(whole.text, undefined, whole.title, undefined, forumSlot());
                    fillImages(threadImages(entities));
                }
                if (sourceTypeInput && sourceIdInput) {
                    sourceTypeInput.value = 'new';
                    sourceIdInput.value = '';
                }
                // Both branches of a thread fill lay forum parts into the form, and
                // none of them is a row of a group: the group the previously clicked
                // row named leaves with the record it was filled from.
                setPartGroupId('');
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

        // The four boxes beside it are remembered as one row, and a click among them
        // restates the fields the marks of a heading ride out in.
        watchHeadingBlock();

        // So is the switch of the addresses, and it is handed the waiting of the
        // fill it owns: the two states of one turn have to agree with each other.
        watchLinksSwitch(armLinksToButtons);

        // And so is the switch of the album rows. It is handed the layout of the
        // rows: a save the server refused has to leave the album in the state the
        // page was rendered with, not in the one the reader clicked it into.
        watchAlbumRowsSwitch(syncAlbumRows);

        // And so is the switch of the parts, which is drawn into the rows of their
        // own times and buttons: a save the server refused has to leave the form
        // with the rows it was rendered with.
        watchPartsPublicationSwitch(updatePartSchedules);

        // And so is the switch of the mode that gives every part its own block of the
        // buttons: a save the server refused has to leave the blocks of the parts, and
        // the block of the form, in the state the page was drawn with.
        watchButtonsPerPartSwitch(redrawButtonBoxes);

        // And so is the switch of the distribution, whose state is a mode rather than a
        // click: thrown on, it hands the albums out at once and goes on cutting over the
        // parts whenever an album arrives or their number changes; thrown off, it leaves
        // the arrangement the reader has.
        watchDistributeImagesSwitch(distributeImages);

        // The two switches of the part numbering are remembered the same way: the
        // numbering itself and the end of the heading its number stands at. Each of
        // them has to renumber the parts as soon as it is thrown, because the number
        // the reader already sees changes its place with the answer — and the radios
        // change their grey with the switch.
        watchNumberPartsSwitch(renumberParts);
        watchHeadingTemplateSwitch(renumberParts);

        // The row of the first part is the one the markup drew and the rebuild of the parts
        // never replaces, so the picker the rows below it are given in their clone has to be
        // put on it here. Left out, that field — the first one the person meets — stays a
        // box to type a date in, while every part under it opens a calendar.
        setupDateTimePicker(jQuery(partsBox.querySelector('.publication-part-at')));

        // The height of the forum block is the page under the sticky header, so
        // the header is measured once the lists are wired and again whenever the
        // window changes — the header itself changes with the breakpoint it is
        // laid out in, and the block has to follow it.
        syncAppHeaderHeight();
        window.addEventListener('resize', syncAppHeaderHeight);

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
    // The brand mark of the header is the tallest thing in it below `lg`, and an
    // image knows its own height only once it has come, so the measure of the
    // header is taken again when the window has loaded everything.
    jQuery(window).on('load', function () {
        syncAppHeaderHeight();
        setTimeout(initAll, 0);
    });
});
JS
);
?>
