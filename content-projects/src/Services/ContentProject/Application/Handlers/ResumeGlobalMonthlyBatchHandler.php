<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use InvalidArgumentException;

final class ResumeGlobalMonthlyBatchHandler implements ContentProjectCommandHandler
{
    public function __construct(
        private readonly GlobalMonthlyBatchOrchestrator $orchestrator,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ResumeGlobalMonthlyBatchCommand) {
            throw new InvalidArgumentException('Expected ResumeGlobalMonthlyBatchCommand.');
        }

        $result = $this->orchestrator->resume($command->batchId, $actor);
        if (! ($result['ok'] ?? false)) {
            $code = (($result['metadata']['reason'] ?? '') === 'legacy_active_run')
                ? ContentProjectActionCodes::OPERATION_LOCKED
                : ContentProjectActionCodes::VALIDATION_FAILED;

            return ContentProjectActionResult::fail(
                $code,
                (string) ($result['message'] ?? 'Resume failed.'),
                null,
                metadata: $result['metadata'] ?? [],
            );
        }

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::GLOBAL_BATCH_RESUMED,
            (string) ($result['message'] ?? 'Batch resumed.'),
            null,
            metadata: $result['metadata'] ?? [],
        );
    }
}
