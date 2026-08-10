<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Feature-flagged debug/recovery: Approved ↔ Scheduled ↔ Published.
 * Never calls WordPress / publisher / Publish Now.
 */
final class ContentProjectDebugLifecycleOverrideService
{
    public const TO_APPROVED = 'approved';

    public const TO_SCHEDULED = 'scheduled';

    public const TO_PUBLISHED = 'published';

    public const REASON = 'debug_recovery';

    /** Marker in last_publish_error — not a real WP failure. */
    public const DEBUG_PUBLISHED_MARKER = 'DEBUG_LIFECYCLE_OVERRIDE:not_wordpress_publish';

    public function __construct(
        private readonly ContentProjectItemStateResolver $stateResolver = new ContentProjectItemStateResolver,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('seo-content-ai.content_project.debug_lifecycle_override', false);
    }

    public function assertActorAllowed(): void
    {
        if (! SeoAccessControl::canDebugContentProjectLifecycle()) {
            throw new RuntimeException('Debug lifecycle override is not allowed.');
        }
    }

    /**
     * @param  list<int>  $taskIds
     * @return array{
     *     affected_ids: list<int>,
     *     transitions: list<array{task_id:int,from:string,to:string}>,
     *     rejected: list<array{task_id:int,reason:string}>
     * }
     */
    public function apply(
        SeoProject $project,
        array $taskIds,
        string $toLifecycle,
        ?Carbon $scheduledAt = null,
        ?string $note = null,
    ): array {
        $this->assertActorAllowed();

        if (! $this->isEnabled()) {
            throw new RuntimeException('Debug lifecycle override flag is off.');
        }

        $to = strtolower(trim($toLifecycle));
        if (! in_array($to, [self::TO_APPROVED, self::TO_SCHEDULED, self::TO_PUBLISHED], true)) {
            throw new RuntimeException('Unsupported debug target lifecycle.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            throw new RuntimeException('Item list is empty.');
        }

        if ($project->archived_at !== null || $project->isArchive()) {
            throw new RuntimeException('Project archived.');
        }

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $project,
            $ids,
            $to,
            $scheduledAt,
            $note,
        ): array {
            $tasks = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->whereIn('id', $ids)
                ->with(['article'])
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->id);

            $rejected = [];
            $prepared = [];

            foreach ($ids as $tid) {
                $task = $tasks->get($tid);
                if (! $task instanceof SeoProjectTask) {
                    $rejected[] = ['task_id' => $tid, 'reason' => 'Item not found in project.'];

                    continue;
                }

                $from = $this->resolveLifecycleBucket($task);
                if ($from === $to) {
                    $rejected[] = ['task_id' => $tid, 'reason' => 'Already in target lifecycle.'];

                    continue;
                }

                if (! in_array($from, [self::TO_APPROVED, self::TO_SCHEDULED, self::TO_PUBLISHED], true)) {
                    $rejected[] = [
                        'task_id' => $tid,
                        'reason' => 'Debug override only for Approved/Scheduled/Published (got '.$from.').',
                    ];

                    continue;
                }

                $at = $scheduledAt;
                if ($to === self::TO_SCHEDULED) {
                    $at = $this->resolveScheduleAt($task, $scheduledAt);
                    if ($at === null) {
                        $rejected[] = [
                            'task_id' => $tid,
                            'reason' => 'scheduled_publish_at required (future datetime).',
                        ];

                        continue;
                    }
                    if ($at->lte(now())) {
                        $rejected[] = [
                            'task_id' => $tid,
                            'reason' => 'scheduled_publish_at must be in the future.',
                        ];

                        continue;
                    }
                }

                $prepared[] = ['task' => $task, 'from' => $from, 'to' => $to, 'at' => $at];
            }

            if ($rejected !== []) {
                return [
                    'affected_ids' => [],
                    'transitions' => [],
                    'rejected' => $rejected,
                ];
            }

            $affected = [];
            $transitions = [];
            foreach ($prepared as $row) {
                /** @var SeoProjectTask $task */
                $task = $row['task'];
                $this->writeOverride($task, $row['from'], $row['to'], $row['at']);
                $tid = (int) $task->id;
                $affected[] = $tid;
                $transitions[] = [
                    'task_id' => $tid,
                    'from' => $row['from'],
                    'to' => $row['to'],
                ];

                RuntimeLogger::info('seo.content_project.debug_lifecycle_override', [
                    'reason' => self::REASON,
                    'project_id' => (int) $project->getKey(),
                    'item_id' => $tid,
                    'from' => $row['from'],
                    'to' => $row['to'],
                    'actor_id' => auth()->id(),
                    'note' => $note,
                    'wordpress_called' => false,
                ]);
            }

            return [
                'affected_ids' => $affected,
                'transitions' => $transitions,
                'rejected' => [],
            ];
        });
    }

    public function resolveLifecycleBucket(SeoProjectTask $task): string
    {
        $state = $this->stateResolver->resolve(
            $task,
            $task->relationLoaded('article') ? $task->article : null,
        );
        $lifecycle = $state->lifecycleState->value;

        return match ($lifecycle) {
            'waiting_publish' => self::TO_SCHEDULED,
            'published' => self::TO_PUBLISHED,
            'approved' => self::TO_APPROVED,
            default => $lifecycle,
        };
    }

    private function resolveScheduleAt(SeoProjectTask $task, ?Carbon $requested): ?Carbon
    {
        if ($requested instanceof Carbon) {
            return $requested;
        }

        $existing = $task->scheduled_publish_at;
        if ($existing instanceof Carbon && $existing->gt(now())) {
            return $existing;
        }

        return null;
    }

    private function writeOverride(
        SeoProjectTask $task,
        string $from,
        string $to,
        ?Carbon $scheduledAt,
    ): void {
        $attrs = match ($to) {
            self::TO_APPROVED => [
                'scheduled_publish_at' => null,
                'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
                'publish_published_at' => null,
                'last_publish_error' => null,
            ],
            self::TO_SCHEDULED => [
                'scheduled_publish_at' => $scheduledAt,
                'publish_queue_status' => ContentProjectPublishQueueStatus::Waiting->value,
                'publish_published_at' => null,
                'last_publish_error' => null,
            ],
            self::TO_PUBLISHED => [
                // Domain needs publish_published_at for Published lifecycle.
                // Marker proves this is debug override — not WordPress publisher success.
                'scheduled_publish_at' => null,
                'publish_queue_status' => ContentProjectPublishQueueStatus::Published->value,
                'publish_published_at' => now(),
                'last_publish_error' => self::DEBUG_PUBLISHED_MARKER,
            ],
            default => throw new RuntimeException('Unsupported target.'),
        };

        $task->forceFill($attrs)->save();
    }
}
