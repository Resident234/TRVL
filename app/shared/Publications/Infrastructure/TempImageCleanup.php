<?php

declare(strict_types=1);

namespace app\shared\Publications\Infrastructure;

use Psr\Log\LoggerInterface;

/**
 * Removes the pictures the publishing queue downloaded for a channel album.
 *
 * Every picture of a record is fetched by the portal itself and written into
 * the temp directory as `publication_img_*` (see `PublicationsService::downloadImage()`),
 * and the bytes are needed for one thing only: the moment the message carrying
 * them is sent. After that no reader comes back for the file — an edit of the
 * record touches the text of the message, and a re-send reads the addresses of
 * the album again — so a leftover is pure disk, and it grows with every album
 * the queue has carried.
 *
 * The sweep looks at that one name pattern and nothing else. The temp directory
 * of the container holds files another process owns — the crontab the entrypoint
 * writes for supercronic, the composer cache mounted into it — and none of them
 * is this queue's to remove.
 *
 * A file younger than the age limit is left alone on purpose: the queue runs
 * every few minutes, and a daily sweep can meet an album in the middle of being
 * sent, at the point where the client is reading the file to put it into the
 * request. Removing it there would fail the publication over its own garbage
 * collection.
 */
final class TempImageCleanup
{
    /**
     * The name every downloaded picture takes, and the single place that name is
     * written: `downloadImage()` builds its path from it, this class finds the
     * files by it.
     */
    public const PREFIX = 'publication_img_';

    /** How old a download has to be before the sweep may take it. */
    public const MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly ?string $tempDir = null,
        private readonly int $maxAgeSeconds = self::MAX_AGE_SECONDS,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Removes the expired downloads of the temp directory.
     *
     * `processed` counts the files this sweep took up — the ones that matched the
     * name and the age — so it always equals `removed` plus `failed`. A file that
     * refused to go is counted, logged and left for the next run: one unreadable
     * entry stops neither the sweep nor the queue.
     *
     * @return array{processed: int, removed: int, failed: int}
     */
    public function run(): array
    {
        $stats = ['processed' => 0, 'removed' => 0, 'failed' => 0];

        foreach ($this->expiredDownloads() as $path) {
            $stats['processed']++;

            // Suppressed because Yii turns a warning of a built-in function into an
            // ErrorException, and an unlink() of a file that vanished under us — or
            // that we are not allowed to take — warns. The answer is read here as a
            // return value, which is the whole point of counting a failure.
            if (@unlink($path)) {
                $stats['removed']++;

                continue;
            }

            $stats['failed']++;
            $this->logger?->warning('Failed to remove a downloaded publication image: {path}', ['path' => $path]);
        }

        return $stats;
    }

    /**
     * The downloads that are old enough to go: plain files, carrying the name of
     * a download, answered with a modification time past the age limit.
     *
     * @return string[]
     */
    private function expiredDownloads(): array
    {
        $pattern = $this->directory() . '/' . self::PREFIX . '*';
        // glob() answers false when it cannot read the directory at all.
        $candidates = @glob($pattern);

        if ($candidates === false) {
            $this->logger?->warning('Failed to list the temp directory for publication images: {pattern}', [
                'pattern' => $pattern,
            ]);

            return [];
        }

        $deadline = time() - $this->maxAgeSeconds;
        $expired = [];

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $modifiedAt = @filemtime($candidate);

            // A file whose age cannot be read is left where it stands: the sweep has
            // no licence to remove what it cannot date.
            if ($modifiedAt === false || $modifiedAt > $deadline) {
                continue;
            }

            $expired[] = $candidate;
        }

        return $expired;
    }

    /**
     * The directory swept: the one the queue writes its downloads into, unless a
     * caller — a test — named another.
     */
    private function directory(): string
    {
        return $this->tempDir ?? sys_get_temp_dir();
    }
}
