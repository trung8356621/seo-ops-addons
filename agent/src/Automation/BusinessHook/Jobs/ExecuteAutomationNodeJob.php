<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ExecuteAutomationNodeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public function __construct(
        public readonly int $nodeExecutionId,
    ) {
        // Caller may override via ->onQueue(); default must not be `default`.
        $this->onQueue(AutomationQueueName::Automation->value);
    }

    public function handle(AutomationGraphExecutionService $graphService): void
    {
        try {
            $graphService->executeNode($this->nodeExecutionId);
        } catch (\Throwable $e) {
            Log::error('automation.node_job.failed', [
                'node_execution_id' => $this->nodeExecutionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
