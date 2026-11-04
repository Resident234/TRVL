<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Settings\Infrastructure;

use app\shared\Settings\Infrastructure\DirectoryTextStyleCatalog;
use Codeception\Test\Unit;

final class DirectoryTextStyleCatalogTest extends Unit
{
    private string $_root = '';

    protected function _before(): void
    {
        parent::_before();

        $root = sys_get_temp_dir() . '/text-styles-' . getmypid() . '-' . uniqid('', false);
        $this->_root = $root;
        mkdir($root, 0777, true);
    }

    protected function _after(): void
    {
        parent::_after();

        $this->purgeTree($this->_root);
    }

    private function purgeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->purgeTree($full);
                continue;
            }

            unlink($full);
        }

        rmdir($path);
    }

    public function testNamesAreTheSubfoldersInByteOrder(): void
    {
        mkdir($this->_root . '/g_jobbot', 0777, true);
        mkdir($this->_root . '/Ватник в Америке', 0777, true);
        mkdir($this->_root . '/Alpha', 0777, true);

        $this->assertSame(
            ['Alpha', 'g_jobbot', 'Ватник в Америке'],
            (new DirectoryTextStyleCatalog($this->_root))->names(),
        );
    }

    public function testFilesAndHiddenEntriesAreSkipped(): void
    {
        mkdir($this->_root . '/used', 0777, true);
        mkdir($this->_root . '/.cache', 0777, true);
        touch($this->_root . '/styles.json');

        $this->assertSame(['used'], (new DirectoryTextStyleCatalog($this->_root))->names());
    }

    public function testOnlyTheFirstDepthCounts(): void
    {
        mkdir($this->_root . '/used/css', 0777, true);
        mkdir($this->_root . '/used/images', 0777, true);

        $this->assertSame(['used'], (new DirectoryTextStyleCatalog($this->_root))->names());
    }

    public function testMissingPathGivesNoStyles(): void
    {
        $this->assertSame([], (new DirectoryTextStyleCatalog($this->_root . '/absent'))->names());
    }

    public function testEmptyFolderGivesNoStyles(): void
    {
        $this->assertSame([], (new DirectoryTextStyleCatalog($this->_root))->names());
    }

    public function testDefaultPathIsTheStylesFolderBesideTheApplicationRoot(): void
    {
        // This file sits at <application root>/tests/Unit/shared/Settings/Infrastructure,
        // so six levels up is where the catalog expects to find its folder.
        $this->assertSame(dirname(__DIR__, 6) . '/styles', DirectoryTextStyleCatalog::defaultPath());
    }

    public function testEnvironmentMovesTheCatalog(): void
    {
        mkdir($this->_root . '/styled', 0777, true);

        putenv('STYLES_PATH=' . $this->_root);
        try {
            $catalog = DirectoryTextStyleCatalog::fromEnvironment();
        } finally {
            putenv('STYLES_PATH');
        }

        $this->assertSame($this->_root, $catalog->path);
        $this->assertSame(['styled'], $catalog->names());
    }

    public function testEmptyEnvironmentLeavesTheDefaultPlace(): void
    {
        putenv('STYLES_PATH=');
        try {
            $catalog = DirectoryTextStyleCatalog::fromEnvironment();
        } finally {
            putenv('STYLES_PATH');
        }

        $this->assertSame(DirectoryTextStyleCatalog::defaultPath(), $catalog->path);
    }
}
