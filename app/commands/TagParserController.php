<?php

declare(strict_types=1);

namespace app\commands;

use app\shared\Forum\Service\ForumTagScanService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Runs the tag pass over the stored forum sections.
 *
 * Usage:
 *   yii tag-parser/scan
 *   yii tag-parser/scan --from=158 --to=158 --limit=10
 */
final class TagParserController extends Controller
{
    public ?int $from = null;
    public ?int $to = null;
    public ?int $limit = null;

    public function __construct(
        $id,
        $module,
        private readonly ForumTagScanService $scanService,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['from', 'to', 'limit']);
    }

    public function actionScan(): int
    {
        $stats = $this->scanService->run($this->from, $this->to, $this->limit);
        if ($stats === null) {
            $this->stdout("Skipped: another tag pass is already running\n");
            return ExitCode::OK;
        }
        $this->stdout(sprintf(
            "Processed: %d, tagged: %d, untagged: %d, tags created: %d, links created: %d, failed: %d\n",
            $stats['processed'],
            $stats['tagged'],
            $stats['untagged'],
            $stats['tags_created'],
            $stats['links_created'],
            $stats['failed'],
        ));
        return ExitCode::OK;
    }
}
