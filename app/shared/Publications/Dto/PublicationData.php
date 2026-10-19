<?php

declare(strict_types=1);

namespace app\shared\Publications\Dto;

use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;

/**
 * A channel publication: a scheduled or published post, a draft, or
 * a soft-deleted record. telegramId is null until the post is
 * actually sent to Telegram. deletedAt is only filled for
 * publications_deleted rows: null means the record is still awaiting
 * removal from the channel, a value means the channel message is
 * already gone. formatting holds the inline highlighting of the text,
 * which the text column keeps plain, buttons the link buttons drawn
 * under the message, and title the heading of the part — the three of
 * them describe the same text, which is what the form holds, while the
 * channel gets the message this record composes out of them.
 */
final readonly class PublicationData
{
    /**
     * The word a part of a split publication opens with, written into its text by
     * the page — the twin of stripPartNumber() there. A record that has a title
     * moves that line into the title instead, so the number stands at the end of
     * the heading rather than over the body.
     */
    private const NUMBERING_PREFIX = 'Часть';

    /** The blank line the heading of a part is separated from its body by. */
    private const HEADING_GAP = "\n\n";

    /**
     * @param string[] $imageUrls
     * @param bool $partReleased whether the queue may take this part; a part of a
     *        group stays out of the queue until it is released, one after another
     * @param bool $partReleasable whether a part still waiting may be released now,
     *        which is true once its previous part has reached the channel
     * @param int|null $partGroupTotal how many parts of the group the record has
     *        alive in any of the three tables, which is what its row badges
     * @param string|null $habitat where the record lives, as the group read names
     *        it: 'channel', 'post', 'draft' or 'deleted'
     */
    public function __construct(
        public int $id,
        public string $text,
        public array $imageUrls,
        public ?int $telegramId,
        public ?string $publishedAt,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt = null,
        public MessageEntities $formatting = new MessageEntities(),
        public LinkButtons $buttons = new LinkButtons(),
        public string $title = '',
        public ?string $partGroupId = null,
        public ?int $partOrder = null,
        public bool $partReleased = true,
        public bool $partReleasable = false,
        public ?int $partGroupTotal = null,
        public ?string $habitat = null,
    ) {
    }

    /**
     * The record as it goes into another of the three tables: the content it is
     * given there and the time it is sent at, while its id, the moment it was
     * written, the group it belongs to, its place in that group and the state of
     * the queue stay its own. Moving between the blocks never stops a row from
     * being the same part of the same publication.
     *
     * @param string[] $imageUrls
     */
    public function movedTo(
        string $text,
        array $imageUrls,
        ?int $telegramId,
        ?string $publishedAt,
        MessageEntities $formatting,
        LinkButtons $buttons,
        string $title,
    ): PublicationData {
        return new PublicationData(
            $this->id,
            $text,
            $imageUrls,
            $telegramId,
            $publishedAt,
            $this->createdAt,
            $this->updatedAt,
            null,
            $formatting,
            $buttons,
            $title,
            $this->partGroupId,
            $this->partOrder,
            $this->partReleased,
        );
    }

    /**
     * The record at another place of its group: the part it is stays the same
     * part of the same publication, only its rank among them follows the form,
     * which is where the two arrows of a part put it. A record that belongs to
     * no group has no place to be moved to and stays as it is.
     *
     * A record carried into the block that holds the queue also takes the queue
     * flag of the place it is given: outside that block a row keeps its own flag,
     * and only the parts standing above it say whether the queue may take it.
     */
    public function standsAt(int $partOrder, ?bool $released = null): PublicationData
    {
        if ($this->partGroupId === null) {
            return $this;
        }

        return new PublicationData(
            $this->id,
            $this->text,
            $this->imageUrls,
            $this->telegramId,
            $this->publishedAt,
            $this->createdAt,
            $this->updatedAt,
            $this->deletedAt,
            $this->formatting,
            $this->buttons,
            $this->title,
            $this->partGroupId,
            $partOrder,
            $released ?? $this->partReleased,
            $this->partReleasable,
            $this->partGroupTotal,
            $this->habitat,
        );
    }

    /**
     * The text the channel is given: the title as the first line and the body
     * behind it. A record without a title is the text it was saved with, byte
     * for byte, which is every record written before the field existed.
     */
    public function messageText(): string
    {
        [$header, $body] = $this->splitHeader();

        if ($header === '') {
            return $this->text;
        }

        return $body === '' ? $header : $header . self::HEADING_GAP . $body;
    }

    /**
     * The highlighting of that text: the whole of the heading line in bold over
     * the spans of the body, which have moved behind it and over the blank line,
     * less the number the heading took out of the head of the text.
     */
    public function messageEntities(): MessageEntities
    {
        [$header, $body, $moved] = $this->splitHeader();

        return $this->formatting->underHeading($header, $body === '' ? '' : self::HEADING_GAP, $moved);
    }

    /**
     * The heading line and the text under it — the two lines a list draws to show
     * the message instead of the stored text, which still holds the number line
     * the heading took over. A record without a heading shows its text whole.
     *
     * @return array{string, string}
     */
    public function headingAndBody(): array
    {
        [$header, $body] = $this->splitHeader();

        return $header === '' ? ['', $this->text] : [$header, $body];
    }

    /**
     * The heading line, the text left under it and how long that line's share of
     * the stored text is in UTF-16 code units. The number the split gave this part
     * belongs to the heading: it is the same publication in the same order, and a
     * line of its own over the body is what the heading replaces. The spans of the
     * text count from its very beginning, prefix and all, so the prefix they lose
     * is what the heading gains.
     *
     * A heading that opens with its number already wears it where the form shows it,
     * which is the shape the page writes when the numbering of the parts and the
     * heading out of the first line both stand; the line of the text then only leaves
     * the body. A heading that carries no number is a record of before that rule, and
     * it goes out the way it always did — the number at the end of it.
     *
     * @return array{string, string, int}
     */
    private function splitHeader(): array
    {
        $title = trim($this->title);

        if ($title === '') {
            return ['', '', 0];
        }

        $matched = preg_match(
            '/^' . preg_quote(self::NUMBERING_PREFIX, '/') . ' (\d+)[.:]?\s*(\n|$)/u',
            $this->text,
            $matches,
        );

        if ($matched !== 1) {
            return [$title, $this->text, 0];
        }

        return [
            $this->headingCarriesItsNumber($title)
                ? $title
                : $title . '. ' . rtrim($matches[0]),
            substr($this->text, strlen($matches[0])),
            MessageEntities::utf16Length($matches[0]),
        ];
    }

    /**
     * Whether the heading of this part opens with the number of its part, which is
     * what the form writes into the field when the numbering stands under it. The
     * twin of the match the page makes over the heading of a part there.
     */
    private function headingCarriesItsNumber(string $title): bool
    {
        return preg_match(
            '/^' . preg_quote(self::NUMBERING_PREFIX, '/') . ' \d+[.:]?\s*/u',
            $title,
        ) === 1;
    }
}
