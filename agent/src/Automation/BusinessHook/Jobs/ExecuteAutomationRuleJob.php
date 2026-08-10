<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ExecuteAutomationRuleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 180;

    public function __construct(
        public readonly int $automationExecutionId,
    ) {
        // Never silently fall back to queue `default` — worker may still process default.
        $this->onQueue(AutomationQueueName::Critical->value);
    }

    public function handle(AutomationExecutionService $executionService): void
    {
        try {
            $execution = \Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution::query()
                ->with('rule')
                ->find($this->automationExecutionId);

            if ($execution?->rule?->isGraphMode()) {
                app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphExecutionService::class)
                    ->bootstrap($this->automationExecutionId);

                return;
            }

            $executionService->run($this->automationExecutionId);
        } catch (\Throwable $e) {
            Log::error('automation.job.failed', [
                'automation_execution_id' => $this->automationExecutionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
