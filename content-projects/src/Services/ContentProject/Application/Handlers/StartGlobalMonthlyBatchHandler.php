<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyAiPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use InvalidArgumentException;

final class StartGlobalMonthlyBatchHandler implements ContentProjectCommandHandler
{
    public function __construct(
        private readonly GlobalMonthlyBatchOrchestrator $orchestrator,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof StartGlobalMonthlyBatchCommand) {
            throw new InvalidArgumentException('Expected StartGlobalMonthlyBatchCommand.');
        }

        $policy = GlobalMonthlyAiPolicy::normalize($command->aiCostPolicy);
        $result = $this->orchestrator->start($command->planningMonth, $policy, $actor);

        if (! ($result['ok'] ?? false)) {
            $code = (($result['metadata']['reason'] ?? '') === 'lock_busy'
                || ($result['metadata']['reason'] ?? '') === 'lock_acquire_failed'
                || ($result['metadata']['reason'] ?? '') === 'legacy_active_run')
                ? ContentProjectActionCodes::OPERATION_LOCKED
                : ContentProjectActionCodes::VALIDATION_FAILED;

            return ContentProjectActionResult::fail(
                $code,
                (string) ($result['message'] ?? 'Failed to start global batch.'),
                null,
                metadata: array_merge($result['metadata'] ?? [], [
                    'batch_id' => $result['batch_id'] ?? null,
                ]),
            );
        }

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::GLOBAL_BATCH_STARTED,
            (string) ($result['message'] ?? 'Global batch started.'),
            null,
            metadata: array_merge($result['metadata'] ?? [], [
                'batch_id' => $result['batch_id'] ?? null,
            ]),
        );
    }
}
