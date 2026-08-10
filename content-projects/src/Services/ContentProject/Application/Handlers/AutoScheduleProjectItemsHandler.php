<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService;
use InvalidArgumentException;

final class AutoScheduleProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectAutoScheduleService $autoScheduleService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AutoScheduleProjectItemsCommand) {
            throw new InvalidArgumentException('Expected AutoScheduleProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            }

            $allowReschedule = (bool) ($command->options['allow_reschedule'] ?? true);
            $preview = $this->autoScheduleService->preview($project, $itemIds, $command->options + [
                'allow_reschedule' => $allowReschedule,
            ]);
            $resolvedItemIds = $preview['eligible_ids'];

            $fingerprint = $this->buildFingerprint($command->name(), $projectId, [
                'item_ids' => $resolvedItemIds,
                'options' => $command->options,
            ]);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $resolvedItemIds,
                    $fingerprint,
                    [
                        'action' => 'auto_schedule',
                        'options' => $command->options,
                        'item_count' => count($resolvedItemIds),
                        'excluded_count' => count($preview['excluded']),
                        'excluded' => $preview['excluded'],
                        'first_publish_at' => $preview['first_publish_at'],
                        'last_publish_at' => $preview['last_publish_at'],
                        'timezone' => $preview['timezone'],
                        'blocked' => $preview['blocked'],
                        'suggested_max_interval' => $preview['suggested_max_interval'],
                        'slots' => $preview['slots'],
                        'item_schedule_map' => $preview['item_schedule_map'] ?? [],
                    ],
                    requiresConfirmation: false,
                );
            }

            if ($resolvedItemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Không có bài chưa lên lịch phù hợp.',
                    $projectId,
                    metadata: [
                        'excluded' => $preview['excluded'],
                        'timezone' => $preview['timezone'],
                    ],
                );
            }

            if (! empty($preview['blocked'])) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    (string) $preview['blocked'],
                    $projectId,
                    metadata: [
                        'suggested_max_interval' => $preview['suggested_max_interval'],
                        'timezone' => $preview['timezone'],
                        'eligible_ids' => $resolvedItemIds,
                    ],
                    affectedItemIds: $resolvedItemIds,
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $resolvedItemIds, $command): ContentProjectActionResult {
                    $result = $this->autoScheduleService->schedule($project, $resolvedItemIds, $command->options);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_SCHEDULED,
                        "{$result['scheduled']} item(s) auto-scheduled.",
                        $projectId,
                        $resolvedItemIds,
                        metadata: [
                            'affected_count' => (int) $result['scheduled'],
                            'slots' => $result['slots'],
                            'item_schedule_map' => $result['item_schedule_map'] ?? [],
                            'excluded' => $result['excluded'],
                            'first_publish_at' => $result['first_publish_at'],
                            'last_publish_at' => $result['last_publish_at'],
                            'timezone' => $result['timezone'],
                            'wordpress_called' => false,
                        ],
                    );
                },
            );
        });
    }
}
