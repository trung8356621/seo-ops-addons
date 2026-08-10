<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectOperation;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Carbon\Carbon;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Replay failed operations via CommandBus — observability recovery path.
 */
final class ContentProjectOpsReplayService
{
    /** @var list<string> */
    private const REPLAYABLE_COMMANDS = [
        'content_project.publish_now',
        'content_project.retry_publish',
        'content_project.process_scheduled_publish',
        'content_project.generate',
        'content_project.rerun',
        'content_project.schedule',
    ];

    public function __construct(
        private readonly ContentProjectCommandBus $commandBus,
    ) {}

    public function replay(string $operationId, int $actorUserId): ContentProjectActionResult
    {
        $operation = ContentProjectOperation::query()
            ->where('operation_id', $operationId)
            ->first();

        if ($operation === null) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::PROJECT_NOT_FOUND,
                'Operation not found.',
            );
        }

        if ($operation->success) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Only failed operations can be replayed.',
            );
        }

        if (! in_array($operation->command, self::REPLAYABLE_COMMANDS, true)) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Command is not replayable: '.$operation->command,
            );
        }

        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $commandClass = isset($metadata['command_class']) ? (string) $metadata['command_class'] : '';
        $payload = isset($metadata['command_payload']) && is_array($metadata['command_payload'])
            ? $metadata['command_payload']
            : null;

        if ($commandClass === '' || $payload === null) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Operation metadata insufficient for replay (missing command_class or command_payload).',
            );
        }

        try {
            $command = $this->rebuildCommand($commandClass, $payload);
        } catch (InvalidArgumentException $e) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                $e->getMessage(),
            );
        }

        $siteId = $this->resolveSiteId($operation->tenant_ref);
        $idempotencyKey = sprintf('ui:%d:replay:%s', $actorUserId, $operationId);

        return $this->commandBus->dispatch(
            $command,
            ActorContext::user($actorUserId, $siteId, $idempotencyKey),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rebuildCommand(string $commandClass, array $payload): ContentProjectCommand
    {
        if (! class_exists($commandClass)) {
            throw new InvalidArgumentException('Command class no longer exists: '.$commandClass);
        }

        $ref = new ReflectionClass($commandClass);
        if (! $ref->implementsInterface(ContentProjectCommand::class)) {
            throw new InvalidArgumentException('Invalid command class for replay.');
        }

        return match ($commandClass) {
            RetryProjectItemPublishingCommand::class => new RetryProjectItemPublishingCommand(
                projectRef: (string) ($payload['projectRef'] ?? $payload['project_ref'] ?? ''),
                itemRefs: $this->normalizeItemRefs($payload),
            ),
            PublishProjectItemsNowCommand::class => new PublishProjectItemsNowCommand(
                projectRef: (string) ($payload['projectRef'] ?? $payload['project_ref'] ?? ''),
                itemRefs: $this->normalizeItemRefs($payload),
                dryRun: (bool) ($payload['dryRun'] ?? $payload['dry_run'] ?? false),
                confirmationToken: isset($payload['confirmationToken']) ? (string) $payload['confirmationToken'] : null,
            ),
            GenerateProjectItemsCommand::class => new GenerateProjectItemsCommand(
                projectRef: (string) ($payload['projectRef'] ?? $payload['project_ref'] ?? ''),
                itemRefs: $this->normalizeItemRefs($payload),
                mode: (string) ($payload['mode'] ?? 'full'),
            ),
            RerunProjectItemsCommand::class => new RerunProjectItemsCommand(
                projectRef: (string) ($payload['projectRef'] ?? $payload['project_ref'] ?? ''),
                itemRefs: $this->normalizeItemRefs($payload),
                mode: (string) ($payload['mode'] ?? 'full'),
            ),
            ScheduleProjectItemsCommand::class => new ScheduleProjectItemsCommand(
                projectRef: (string) ($payload['projectRef'] ?? $payload['project_ref'] ?? ''),
                itemRefs: $this->normalizeItemRefs($payload),
                scheduledAt: Carbon::parse((string) ($payload['scheduledAt'] ?? $payload['scheduled_at'] ?? now()->toIso8601String())),
                dryRun: (bool) ($payload['dryRun'] ?? $payload['dry_run'] ?? false),
            ),
            ProcessScheduledProjectItemPublishCommand::class => new ProcessScheduledProjectItemPublishCommand(
                itemRef: (string) ($payload['itemRef'] ?? $payload['item_ref'] ?? ''),
                projectRef: isset($payload['projectRef']) ? (string) $payload['projectRef'] : (isset($payload['project_ref']) ? (string) $payload['project_ref'] : null),
                attemptRef: isset($payload['attemptRef']) ? (string) $payload['attemptRef'] : null,
            ),
            default => throw new InvalidArgumentException('Unsupported command class for replay: '.$commandClass),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int|string>
     */
    private function normalizeItemRefs(array $payload): array
    {
        $refs = $payload['itemRefs'] ?? $payload['item_refs'] ?? $payload['affected_item_refs'] ?? [];

        return is_array($refs) ? array_values($refs) : [];
    }

    private function resolveSiteId(?string $tenantRef): ?int
    {
        if ($tenantRef === null || ! preg_match('/^site:(\d+)/', $tenantRef, $m)) {
            return null;
        }

        $siteId = (int) $m[1];

        return $siteId > 0 ? $siteId : null;
    }
}
