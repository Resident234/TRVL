<?php

declare(strict_types=1);

namespace app\shared\Publications\Dto;

use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Dto\MessageEntities;

/**
 * A channel publication: a scheduled or published post, a draft, or
 * a soft-deleted record. telegramId is null until the post is
 * actually sent to Telegram. deletedAt is only filled for
 * publications_deleted rows: null means the record is still awaiting
 * removal from the channel, a value means the channel message is
 * already gone. formatting holds the inline highlighting of the text,
 * which the text column keeps plain, button the link button drawn
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
        public LinkButton $button = new LinkButton(),
        public string $title = '',
    ) {
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
     * @return array{string, string, int}
     */
    private function splitHeader(): array
    {
        $title = trim($this->title);

        if ($title === '') {
            return ['', '', 0];
        }

        if (preg_match(
            '/^' . preg_quote(self::NUMBERING_PREFIX, '/') . ' (\d+)[.:]?\s*(\n|$)/u',
            $this->text,
            $matches,
        ) !== 1) {
            return [$title, $this->text, 0];
        }

        return [
            $title . '. ' . rtrim($matches[0]),
            substr($this->text, strlen($matches[0])),
            MessageEntities::utf16Length($matches[0]),
        ];
    }
}
