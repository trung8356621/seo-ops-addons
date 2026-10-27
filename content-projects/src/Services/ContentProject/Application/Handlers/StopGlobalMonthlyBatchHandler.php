<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StopGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use InvalidArgumentException;

final class StopGlobalMonthlyBatchHandler implements ContentProjectCommandHandler
{
    public function __construct(
        private readonly GlobalMonthlyBatchOrchestrator $orchestrator,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof StopGlobalMonthlyBatchCommand) {
            throw new InvalidArgumentException('Expected StopGlobalMonthlyBatchCommand.');
        }

        $result = $this->orchestrator->requestStop($command->batchId, $actor, $command->reason);
        if (! ($result['ok'] ?? false)) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                (string) ($result['message'] ?? 'Stop failed.'),
                null,
                metadata: $result['metadata'] ?? [],
            );
        }

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::GLOBAL_BATCH_STOP_REQUESTED,
            (string) ($result['message'] ?? 'Stop requested.'),
            null,
            metadata: $result['metadata'] ?? [],
        );
    }
}
