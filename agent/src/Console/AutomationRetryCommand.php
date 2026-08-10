<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Console\Command;

final class AutomationRetryCommand extends Command
{
    protected $signature = 'automation:retry {execution_id : Automation execution ID}';

    protected $description = 'Retry a failed or partial automation execution.';

    public function handle(AutomationExecutionService $executionService): int
    {
        $executionId = (int) $this->argument('execution_id');

        if (! AutomationExecution::query()->whereKey($executionId)->exists()) {
            $this->error("Execution [{$executionId}] not found.");

            return self::FAILURE;
        }

        try {
            $execution = $executionService->retry($executionId);
        } catch (AutomationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Execution [{$executionId}] queued for retry. Status: {$execution->status}");

        return self::SUCCESS;
    }
}
