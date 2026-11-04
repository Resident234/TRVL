<?php

declare(strict_types=1);

namespace app\shared\Settings\Infrastructure;

use app\shared\Settings\Contract\TextStyleCatalogInterface;
use DirectoryIterator;

/**
 * The text styles as a folder on the disk: every subfolder of the catalog is
 * one style, named exactly as it is spelled there.
 *
 * The folder lives beside the application rather than inside it, so the styles
 * a reader collects are never mistaken for the code of the portal.
 */
final class DirectoryTextStyleCatalog implements TextStyleCatalogInterface
{
    public function __construct(public readonly string $path)
    {
    }

    /**
     * Where the catalog sits when nobody names another place: beside the
     * application root, which is `styles` next to `app` outside a container and
     * `/var/www/styles` inside one, where the mount puts the same folder.
     */
    public static function defaultPath(): string
    {
        return dirname(__DIR__, 4) . '/styles';
    }

    /**
     * The catalog the environment points at: STYLES_PATH names a folder, and an
     * unset or empty variable leaves the default place. Both applications build
     * the catalog this way, so the settings page and the queue see one list.
     */
    public static function fromEnvironment(): self
    {
        $path = (string)(getenv('STYLES_PATH') ?: '');

        return new self($path !== '' ? $path : self::defaultPath());
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $names = [];
        foreach (new DirectoryIterator($this->path) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $name = $entry->getFilename();
            if (str_starts_with($name, '.')) {
                continue;
            }

            $names[] = $name;
        }

        usort($names, static fn (string $left, string $right): int => strcmp($left, $right));

        return $names;
    }
}
