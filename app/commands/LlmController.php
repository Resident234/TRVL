<?php

declare(strict_types=1);

namespace app\commands;

use app\shared\Llm\Service\LlmJobProcessor;
use app\shared\Llm\Service\LlmModelProbeService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * The background queue of the text tools: stylization is the only operation
 * that leaves the request, and this command is where it comes back.
 */
final class LlmController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly LlmJobProcessor $jobs,
        private readonly LlmModelProbeService $probe,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Drain the queue: finish what is queued, return what a killed container
     * abandoned, sweep what finished three days ago.
     */
    public function actionProcessJobs(): int
    {
        $stats = $this->jobs->drain();

        if ($stats['locked']) {
            $this->stdout("Дрейнер уже работает в другом запуске — шаг пропущен.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Processed: %d, done: %d, failed: %d, reclaimed: %d, purged: %d\n",
            $stats['processed'],
            $stats['done'],
            $stats['failed'],
            $stats['reclaimed'],
            $stats['purged'],
        ));

        return $stats['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Probe every model the proxy exposes and update the fallback list.
     * Run this once an hour from the llm cron container.
     */
    public function actionProbeModels(): int
    {
        $result = $this->probe->run();

        $this->stdout(sprintf(
            "Probe done: probed=%d, ok=%d, skipped=%d\n",
            $result['probed'],
            $result['ok'],
            $result['skipped'],
        ));

        return ExitCode::OK;
    }
}
