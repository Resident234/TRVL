<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\shared\Forum\Infrastructure\ForumPageNotFoundException;
use app\shared\Forum\Service\ForumSectionPageParser;
use Codeception\Test\Unit;
use RuntimeException;

final class ForumSectionPageParserTest extends Unit
{
    private const URL = 'https://forum.awd.ru/viewforum.php?f=158';

    private const FORUM_HTML = <<<'HTML'
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="ru">
<body id="phpbb" class="section-viewforum ltr">
<div id="page-header">
	<ul class="linklist navlinks">
		<li>
			<a href="./index.php">Список форумов</a>
			<strong>&#8249;</strong>
			<a href="./viewforum.php?f=826">ЕВРОПА форум</a>
			<strong>&#8249;</strong>
			<a href="./viewforum.php?f=158">ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании</a>
		</li>
	</ul>
</div>
<div id="page-body">
	<h1>ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании</h1>
</div>
</body>
</html>
HTML;

    public function testParsesForumIdAndTitle(): void
    {
        $parser = new ForumSectionPageParser();
        $forum = $parser->parse(158, self::URL, self::FORUM_HTML);

        $this->assertSame(158, $forum->id);
        $this->assertSame(self::URL, $forum->sourceUrl);
        $this->assertSame('ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании', $forum->title);
    }

    public function testNormalizesWhitespaceInTitle(): void
    {
        $parser = new ForumSectionPageParser();
        $html = str_replace(
            '<h1>ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании</h1>',
            "<h1>\n  ИСПАНИЯ&nbsp;форум —  отдых,\n путешествия  </h1>",
            self::FORUM_HTML,
        );
        $forum = $parser->parse(158, self::URL, $html);

        $this->assertSame('ИСПАНИЯ форум — отдых, путешествия', $forum->title);
    }

    public function testMissingForumIsReportedAsNotFound(): void
    {
        $parser = new ForumSectionPageParser();

        $this->expectException(ForumPageNotFoundException::class);
        $parser->parse(
            999999,
            'https://forum.awd.ru/viewforum.php?f=999999',
            '<html><body><h1></h1><p>Запрошенного форума не существует.</p></body></html>',
        );
    }

    public function testPageWithoutHeadingFails(): void
    {
        $parser = new ForumSectionPageParser();

        $this->expectException(RuntimeException::class);
        $parser->parse(158, self::URL, '<html><body><p>broken</p></body></html>');
    }
}
