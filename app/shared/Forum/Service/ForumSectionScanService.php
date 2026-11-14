<?php

declare(strict_types=1);

namespace app\shared\Forum\Service;

use app\shared\Forum\Contract\ForumHttpClientInterface;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Forum\Infrastructure\ForumLoginRequiredException;
use app\shared\Forum\Infrastructure\ForumPageNotFoundException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Iterates the configured forum id range, parses every viewforum page and
 * upserts the forum section. A failure of a single id never stops the run.
 */
final class ForumSectionScanService
{
    public function __construct(
        private readonly ForumRepositoryInterface $repository,
        private readonly ForumHttpClientInterface $httpClient,
        private readonly ForumSectionPageParser $parser,
        private readonly LoggerInterface $logger,
        private readonly string $configCode = 'awd_forum_sections',
    ) {
    }

    /**
     * @param int|null $from explicit range start override
     * @param int|null $to explicit range end override
     * @param int|null $limit max number of forum ids to process in this run
     * @return array{processed: int, saved: int, updated: int, not_found: int, login_required: int, failed: int}|null null when another scan is already running
     */
    public function run(?int $from = null, ?int $to = null, ?int $limit = null): ?array
    {
        if (!$this->repository->acquireLock($this->configCode)) {
            $this->logger->warning('Forum section scan is already running, launch skipped.', ['code' => $this->configCode]);
            return null;
        }

        try {
            return $this->doRun($from, $to, $limit);
        } finally {
            $this->repository->releaseLock($this->configCode);
        }
    }

    /**
     * @return array{processed: int, saved: int, updated: int, not_found: int, login_required: int, failed: int}
     */
    private function doRun(?int $from, ?int $to, ?int $limit): array
    {
        $config = $this->repository->activeConfig($this->configCode);
        if ($config === null) {
            $this->logger->warning('Parser config is missing or disabled.', ['code' => $this->configCode]);
            return ['processed' => 0, 'saved' => 0, 'updated' => 0, 'not_found' => 0, 'login_required' => 0, 'failed' => 0];
        }

        $start = max((int)$config['t_from'], $from ?? (int)$config['t_from']);
        $end = min((int)$config['t_to'], $to ?? (int)$config['t_to']);
        $stats = ['processed' => 0, 'saved' => 0, 'updated' => 0, 'not_found' => 0, 'login_required' => 0, 'failed' => 0];

        for ($id = $start; $id <= $end && ($limit === null || $stats['processed'] < $limit); $id++) {
            $stats['processed']++;
            $url = rtrim((string)$config['base_url'], '=') . '=' . $id;
            try {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $forum = $this->parser->parse($id, $url, $this->httpClient->get($url));
                $isNew = $this->repository->saveForum($forum, $now->format('Y-m-d H:i:s'));
                $isNew ? $stats['saved']++ : $stats['updated']++;
            } catch (ForumPageNotFoundException $e) {
                $stats['not_found']++;
                $this->logger->info($e->getMessage(), ['forum_id' => $id]);
            } catch (ForumLoginRequiredException $e) {
                $stats['login_required']++;
                $this->logger->info($e->getMessage(), ['forum_id' => $id]);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->logger->warning('Forum section skipped.', ['forum_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $endNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->repository->markRun($this->configCode, $endNow->format('Y-m-d H:i:s'));
        $this->logger->info('Forum section scan finished.', array_merge(['code' => $this->configCode], $stats));
        return $stats;
    }
}
