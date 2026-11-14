<?php

declare(strict_types=1);

namespace app\shared\Forum\Service;

use app\shared\Forum\Dto\ForumData;
use app\shared\Forum\Infrastructure\ForumLoginRequiredException;
use app\shared\Forum\Infrastructure\ForumPageNotFoundException;
use RuntimeException;

/**
 * Parses a phpBB forum page (forum.awd.ru/viewforum.php?f=...) into a
 * ForumData DTO. The forum title is the page heading the breadcrumb and
 * the browser title repeat.
 */
final class ForumSectionPageParser extends ForumPageDomParser
{
    public function parse(int $forumId, string $sourceUrl, string $html): ForumData
    {
        $xpath = $this->loadXPath($html);

        $title = $this->cleanText($this->firstText($xpath, ['//h1']));
        if ($title === '') {
            $body = $this->cleanText($this->firstText($xpath, ['//body']));
            if (str_contains($body, 'вы должны быть авторизованы')) {
                throw new ForumLoginRequiredException('Forum section is available to authorized users only.');
            }
            if (str_contains($body, 'Запрошенного форума не существует')) {
                throw new ForumPageNotFoundException('Forum section does not exist.');
            }
            throw new RuntimeException('Forum title was not found.');
        }

        return new ForumData($forumId, $sourceUrl, $title);
    }
}
