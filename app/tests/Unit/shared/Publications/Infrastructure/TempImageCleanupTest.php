<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Infrastructure;

use app\shared\Publications\Infrastructure\TempImageCleanup;
use Codeception\Test\Unit;

/**
 * The daily sweep of the pictures the publishing queue downloaded for its
 * albums. It works on one name, publication_img_*, and on one age: a file
 * younger than the limit can still be inside a message being sent, and the
 * rest of the temp directory belongs to other processes — the crontab of
 * supercronic and the composer cache live there too.
 */
final class TempImageCleanupTest extends Unit
{
    /** The scratch directory the sweep is pointed at, so no real temp file is taken. */
    private string $_dir;

    protected function _before(): void
    {
        parent::_before();

        $this->_dir = sys_get_temp_dir() . '/tmpgc_' . bin2hex(random_bytes(6));
        mkdir($this->_dir);
    }

    protected function _after(): void
    {
        foreach (glob($this->_dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                rmdir($path);

                continue;
            }

            unlink($path);
        }

        rmdir($this->_dir);

        parent::_after();
    }

    /**
     * Writes a file into the scratch directory and ages it by the given number
     * of seconds, which is how a download left over from an earlier run is made.
     */
    private function put(string $name, int $ageSeconds = 0): string
    {
        $path = $this->_dir . '/' . $name;
        file_put_contents($path, 'bytes');
        clearstatcache();
        touch($path, time() - $ageSeconds);
        clearstatcache();

        return $path;
    }

    private function sweep(int $maxAgeSeconds = TempImageCleanup::MAX_AGE_SECONDS): array
    {
        return (new TempImageCleanup($this->_dir, $maxAgeSeconds))->run();
    }

    public function testADownloadOlderThanTheLimitIsRemoved(): void
    {
        $path = $this->put(TempImageCleanup::PREFIX . 'a.jpg', 7200);

        $this->assertSame(['processed' => 1, 'removed' => 1, 'failed' => 0], $this->sweep());
        $this->assertFileDoesNotExist($path);
    }

    public function testADownloadOfExactlyTheLimitIsOldEnough(): void
    {
        $path = $this->put(TempImageCleanup::PREFIX . 'a.jpg', TempImageCleanup::MAX_AGE_SECONDS);

        $this->assertSame(['processed' => 1, 'removed' => 1, 'failed' => 0], $this->sweep());
        $this->assertFileDoesNotExist($path);
    }

    public function testADownloadYoungerThanTheLimitStands(): void
    {
        $path = $this->put(TempImageCleanup::PREFIX . 'a.jpg', 60);

        $this->assertSame(['processed' => 0, 'removed' => 0, 'failed' => 0], $this->sweep());
        $this->assertFileExists($path);
    }

    public function testTheAgeLimitIsTheOneTheSweepWasBuiltWith(): void
    {
        $path = $this->put(TempImageCleanup::PREFIX . 'a.jpg', 20);

        $this->assertSame(['processed' => 1, 'removed' => 1, 'failed' => 0], $this->sweep(10));
        $this->assertFileDoesNotExist($path);
    }

    public function testNamesThatAreNotTheQueuesAreLeftAlone(): void
    {
        // What the container keeps in the same directory: the crontab of the
        // entrypoint, the composer cache, and a file of no known name at all.
        $cronJobs = $this->put('cron-jobs', 7200);
        $composer = $this->put('composer-cache', 7200);
        $stranger = $this->put('other.jpg', 7200);

        $this->assertSame(['processed' => 0, 'removed' => 0, 'failed' => 0], $this->sweep());
        $this->assertFileExists($cronJobs);
        $this->assertFileExists($composer);
        $this->assertFileExists($stranger);
    }

    public function testADirectoryUnderTheNameIsNotAFileToRemove(): void
    {
        $path = $this->_dir . '/' . TempImageCleanup::PREFIX . 'dir';
        mkdir($path);
        touch($path, time() - 7200);

        $this->assertSame(['processed' => 0, 'removed' => 0, 'failed' => 0], $this->sweep());
        $this->assertDirectoryExists($path);
    }

    public function testEveryExpiredDownloadOfTheDirectoryIsTaken(): void
    {
        $first = $this->put(TempImageCleanup::PREFIX . 'a.jpg', 7200);
        $second = $this->put(TempImageCleanup::PREFIX . 'b.png', 7200);
        $fresh = $this->put(TempImageCleanup::PREFIX . 'c.gif', 60);

        $this->assertSame(['processed' => 2, 'removed' => 2, 'failed' => 0], $this->sweep());
        $this->assertFileDoesNotExist($first);
        $this->assertFileDoesNotExist($second);
        $this->assertFileExists($fresh);
    }

    public function testADirectoryThatCannotBeReadIsNotAnError(): void
    {
        $cleanup = new TempImageCleanup(sys_get_temp_dir() . '/tmpgc_absent_' . bin2hex(random_bytes(6)));

        $this->assertSame(['processed' => 0, 'removed' => 0, 'failed' => 0], $cleanup->run());
    }

    public function testTheSweptDirectoryDefaultsToTheOneTheQueueWritesInto(): void
    {
        // No directory named: the sweep looks at sys_get_temp_dir(), which is where
        // downloadImage() puts its files. The probe carries a name of its own, and
        // anything else expired it finds there is garbage the daily run would take
        // too, so the counter is read as "at least one".
        $path = sys_get_temp_dir() . '/' . TempImageCleanup::PREFIX . bin2hex(random_bytes(6)) . '.jpg';
        file_put_contents($path, 'bytes');
        touch($path, time() - 7200);
        clearstatcache();

        try {
            $stats = (new TempImageCleanup())->run();
            clearstatcache();

            $this->assertFileDoesNotExist($path);
            $this->assertGreaterThanOrEqual(1, $stats['removed']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
