<?php

declare(strict_types=1);

namespace app\shared\Settings\Contract;

/**
 * The text styles a reader can pick on the settings page.
 *
 * A style is one folder of the catalog: the settings only remember which
 * folder is active, so what the catalog is made of — markup, pictures, a
 * dump of messages — stays the business of whoever produced it.
 */
interface TextStyleCatalogInterface
{
    /**
     * The names of the styles, sorted, without the path in front of them.
     * A catalog that cannot be read gives no names at all.
     *
     * @return string[]
     */
    public function names(): array;
}
