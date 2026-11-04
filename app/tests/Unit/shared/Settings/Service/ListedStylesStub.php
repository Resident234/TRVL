<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Settings\Service;

use app\shared\Settings\Contract\TextStyleCatalogInterface;

/**
 * The catalog of text styles with a list fixed by the test, so a style is named
 * without a folder on the disk behind it.
 */
final class ListedStylesStub implements TextStyleCatalogInterface
{
    /** @var string[] the names this catalog answers with */
    public array $folders;

    /**
     * @param string[] $folders
     */
    public function __construct(array $folders = [])
    {
        $this->folders = $folders;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return $this->folders;
    }
}
