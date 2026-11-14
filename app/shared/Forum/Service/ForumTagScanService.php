<?php

declare(strict_types=1);

namespace app\shared\Forum\Service;

use app\shared\Forum\Contract\TagRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Reads the forum sections the forum parser stored and turns the country
 * named in each title into a tag. The pass is idempotent: a second run over
 * the same rows creates neither a tag nor a link twice.
 */
final class ForumTagScanService
{
    public function __construct(
        private readonly TagRepositoryInterface $repository,
        private readonly CountryTagExtractor $extractor,
        private readonly LoggerInterface $logger,
        private readonly string $lockCode = 'awd_forum_tags',
    ) {
    }

    /**
     * @param int|null $from lower forum id bound
     * @param int|null $to upper forum id bound
     * @param int|null $limit max number of forum rows in this run
     * @return array{processed: int, tagged: int, untagged: int, tags_created: int, links_created: int, failed: int}|null null when another pass is already running
     */
    public function run(?int $from = null, ?int $to = null, ?int $limit = null): ?array
    {
        if (!$this->repository->acquireLock($this->lockCode)) {
            $this->logger->warning('Forum tag pass is already running, launch skipped.', ['code' => $this->lockCode]);
            return null;
        }

        try {
            return $this->doRun($from, $to, $limit);
        } finally {
            $this->repository->releaseLock($this->lockCode);
        }
    }

    /**
     * @return array{processed: int, tagged: int, untagged: int, tags_created: int, links_created: int, failed: int}
     */
    private function doRun(?int $from, ?int $to, ?int $limit): array
    {
        $stats = [
            'processed' => 0,
            'tagged' => 0,
            'untagged' => 0,
            'tags_created' => 0,
            'links_created' => 0,
            'failed' => 0,
        ];

        foreach ($this->repository->forums($from, $to, $limit) as $forum) {
            $stats['processed']++;
            try {
                $tagNames = $this->extractor->extract($forum['title']);
                $written = $this->repository->saveForumTags(
                    $forum['id'],
                    $tagNames,
                    (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                );
                $tagNames === [] ? $stats['untagged']++ : $stats['tagged']++;
                $stats['tags_created'] += $written['tags_created'];
                $stats['links_created'] += $written['links_created'];
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->logger->warning('Forum tag pass skipped a forum.', [
                    'forum_id' => $forum['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Forum tag pass finished.', array_merge(['code' => $this->lockCode], $stats));
        return $stats;
    }
}
