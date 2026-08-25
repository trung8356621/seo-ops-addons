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
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
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

            $draftBlock = ContentProjectDraftExecutionGuard::rejectIfDraft($project, $projectId);
            if ($draftBlock !== null) {
                return $draftBlock;
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            }

            $allowReschedule = array_key_exists('allow_reschedule', $command->options)
                ? (bool) $command->options['allow_reschedule']
                : (($command->options['mode'] ?? '') !== 'monthly_even');
            $preview = $this->autoScheduleService->preview($project, $itemIds, $command->options + [
                'allow_reschedule' => $allowReschedule,
            ]);
            $resolvedItemIds = $preview['eligible_ids'];
            $distributionMeta = is_array($preview['distribution_meta'] ?? null) ? $preview['distribution_meta'] : [];
            $preservedCount = (int) ($distributionMeta['preserved_count'] ?? count(array_filter(
                $preview['excluded'] ?? [],
                static fn (array $row): bool => ($row['reason'] ?? '') === 'scheduled_locked',
            )));
            $newlyScheduledCount = count($preview['slots'] ?? []);

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
                        'distribution_meta' => $distributionMeta,
                        'preserved_count' => $preservedCount,
                        'newly_scheduled_count' => $newlyScheduledCount,
                    ],
                    requiresConfirmation: false,
                );
            }

            if ($resolvedItemIds === []) {
                $modeEmpty = (string) ($command->options['mode'] ?? '');
                if ($modeEmpty === 'monthly_even') {
                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_SCHEDULED,
                        '0 item(s) auto-scheduled.',
                        $projectId,
                        [],
                        metadata: [
                            'affected_count' => 0,
                            'newly_scheduled_count' => 0,
                            'preserved_count' => $preservedCount,
                            'excluded' => $preview['excluded'],
                            'timezone' => $preview['timezone'],
                            'distribution_meta' => $distributionMeta,
                            'wordpress_called' => false,
                        ],
                    );
                }

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

            $mode = (string) ($command->options['mode'] ?? '');
            $siteId = (int) ($project->site_id ?? 0);
            $monthYm = $project->month instanceof \Carbon\Carbon
                ? $project->month->format('Y-m')
                : (is_string($project->month) && $project->month !== '' ? substr($project->month, 0, 7) : '');

            $lockKeys = [$this->businessLock->projectSchedule($projectId)];
            if ($mode === 'monthly_even' && $siteId > 0 && $monthYm !== '') {
                $lockKeys[] = $this->businessLock->siteSchedule($siteId, $monthYm);
            }

            return $this->withScheduleLocks(
                $lockKeys,
                function () use ($project, $projectId, $resolvedItemIds, $command, $preview, $distributionMeta, $preservedCount, $newlyScheduledCount): ContentProjectActionResult {
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
                            'distribution_meta' => $result['distribution_meta'] ?? $distributionMeta,
                            'preserved_count' => $preservedCount,
                            'newly_scheduled_count' => $newlyScheduledCount,
                            'wordpress_called' => false,
                        ],
                    );
                },
            );
        });
    }

    /**
     * @param  list<string>  $lockKeys
     */
    private function withScheduleLocks(array $lockKeys, callable $callback): ContentProjectActionResult
    {
        $keys = array_values(array_unique(array_filter($lockKeys, static fn (string $k): bool => $k !== '')));
        if ($keys === []) {
            return $callback();
        }

        $head = array_shift($keys);

        return $this->businessLock->withLock($head, function () use ($keys, $callback): ContentProjectActionResult {
            if ($keys === []) {
                return $callback();
            }

            return $this->withScheduleLocks($keys, $callback);
        });
    }
}
