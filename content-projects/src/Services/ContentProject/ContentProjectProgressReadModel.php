<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPercent;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPresentation;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lightweight Progress page read-model — latest run + article-execution items only.
 * Selects ITEM_COLUMNS only (no snapshot JSON columns, no AI history).
 */
final class ContentProjectProgressReadModel
{
    private const ITEM_COLUMNS = [
        'id',
        'run_id',
        'task_id',
        'article_id',
        'action',
        'status',
        'attempt',
        'message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array{
     *     has_run: bool,
     *     run: ?array{
     *         id: int,
     *         status: string,
     *         started_at: ?string,
     *         finished_at: ?string,
     *         is_active: bool,
     *         should_poll: bool,
     *         is_terminal: bool,
     *         ai_cost_policy: ?string,
     *         ai_mode_label: ?string
     *     },
     *     overview: array{
     *         total: int,
     *         snapshot_total: int,
     *         completed: int,
     *         processing: int,
     *         failed: int,
     *         pending: int,
     *         skipped: int,
     *         cancelled: int,
     *         eligible_total: int,
     *         processed: int,
     *         percent: int,
     *         no_eligible_work: bool,
     *         last_completed_duration_seconds: ?int,
     *         last_completed_duration_label: ?string
     *     },
     *     now_playing: ?array<string, mixed>,
     *     playlist: list<array<string, mixed>>
     * }
     */
    public function forProject(SeoProject $project): array
    {
        $run = $this->resolveDisplayRun((int) $project->getKey());
        if (! $run instanceof SeoProjectRun) {
            return $this->emptyPayload();
        }

        /** @var Collection<int, SeoProjectRunItem> $items */
        $items = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->orderBy('id')
            ->get(self::ITEM_COLUMNS);

        $engine = $this->engineBag($run);
        $activeDispatch = is_array($engine['active_dispatch'] ?? null)
            ? $engine['active_dispatch']
            : null;

        $titles = $this->resolveTitles($items);
        $thumbnails = $this->resolveThumbnails($items);

        $playlist = [];
        $counts = [
            'completed' => 0,
            'processing' => 0,
            'failed' => 0,
            'pending' => 0,
            'skipped' => 0,
            'cancelled' => 0,
        ];
        $lastCompletedSeconds = null;
        $nowPlaying = null;
        $total = $items->count();
        $runStatus = (string) $run->status;
        $runCancelled = $runStatus === SeoProjectRun::STATUS_CANCELLED;

        foreach ($items as $index => $item) {
            $status = SeoProjectRunItemStatus::fromLegacy(
                $item->status !== null ? (string) $item->status : null
            );
            $bucket = $this->bucketForStatus($status, $runCancelled);
            $counts[$bucket]++;

            $position = $index + 1;
            $durationSeconds = $this->durationSeconds($item, $status);
            $isCurrent = $status === SeoProjectRunItemStatus::Processing
                || (
                    is_array($activeDispatch)
                    && (int) ($activeDispatch['run_item_id'] ?? 0) === (int) $item->id
                    && in_array($status, [
                        SeoProjectRunItemStatus::Pending,
                        SeoProjectRunItemStatus::Processing,
                    ], true)
                );

            if ($status === SeoProjectRunItemStatus::Success && $durationSeconds !== null) {
                $lastCompletedSeconds = $durationSeconds;
            }

            $articleId = (int) ($item->article_id ?? 0);
            $taskId = (int) ($item->task_id ?? 0);
            $title = $titles[$taskId] ?? ($titles['a:'.$articleId] ?? ('#'.$taskId));
            $thumbnail = $thumbnails[$articleId] ?? null;
            $stepLabel = ContentProjectArticleRuntimeStatusResolver::stepLabel($item->action)
                ?? (
                    $isCurrent && is_array($activeDispatch)
                        ? ContentProjectArticleRuntimeStatusResolver::stepLabel($activeDispatch['current_step'] ?? null)
                        : null
                );

            $row = [
                'run_item_id' => (int) $item->id,
                'position' => $position,
                'task_id' => $taskId,
                'article_id' => $articleId > 0 ? $articleId : null,
                'title' => $title,
                'thumbnail_url' => $thumbnail,
                'status' => $status->value,
                'status_bucket' => $bucket,
                'status_label' => $this->statusLabel($status, $bucket),
                'duration_seconds' => $durationSeconds,
                'duration_label' => $durationSeconds !== null
                    ? self::formatDuration($durationSeconds)
                    : null,
                'step_label' => $stepLabel,
                'is_current' => $isCurrent,
                'article_url' => $articleId > 0
                    ? \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('edit', [
                        'record' => $articleId,
                    ])
                    : null,
            ];

            $playlist[] = $row;

            if ($isCurrent && $nowPlaying === null) {
                $nowPlaying = $this->buildNowPlaying(
                    $row,
                    $total,
                    $run,
                    $activeDispatch,
                );
            }
        }

        // Prefer the first processing row when multiple signals disagree.
        if ($nowPlaying === null) {
            foreach ($playlist as $row) {
                if (($row['status_bucket'] ?? '') === 'processing') {
                    $nowPlaying = $this->buildNowPlaying($row, $total, $run, $activeDispatch);
                    break;
                }
            }
        }

        $completed = $counts['completed'];
        $isActive = in_array($runStatus, [
            SeoProjectRun::STATUS_RUNNING,
            SeoProjectRun::STATUS_STOPPING,
        ], true);
        $isTerminal = in_array($runStatus, [
            SeoProjectRun::STATUS_COMPLETED,
            SeoProjectRun::STATUS_FAILED,
            SeoProjectRun::STATUS_CANCELLED,
            'stopped',
            'completed_with_errors',
        ], true);

        if ($isTerminal) {
            $nowPlaying = null;
            foreach ($playlist as &$row) {
                $row['is_current'] = false;
            }
            unset($row);
        }

        $progressMath = ContentProjectProgressPercent::compute(
            $total,
            $completed,
            $counts['failed'],
            $counts['skipped'],
            $isTerminal,
        );

        $presentation = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: $runStatus,
            snapshotTotal: $total,
            succeeded: $completed,
            failed: $counts['failed'],
            skipped: $counts['skipped'],
            pending: $counts['pending'],
            processing: $counts['processing'],
            cancelled: $counts['cancelled'],
            isTerminal: $isTerminal,
        );

        $aiMode = $this->resolveRunAiMode($run);

        return [
            'has_run' => true,
            'run' => [
                'id' => (int) $run->id,
                'status' => $runStatus,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'is_active' => $isActive,
                'should_poll' => $isActive || $counts['processing'] > 0,
                'is_terminal' => $isTerminal,
                'ai_cost_policy' => $aiMode['policy'],
                'ai_mode_label' => $aiMode['label'],
            ],
            'overview' => [
                'total' => $total,
                'snapshot_total' => $progressMath['snapshot_total'],
                'completed' => $completed,
                'processing' => $counts['processing'],
                'failed' => $counts['failed'],
                'pending' => $counts['pending'],
                'skipped' => $counts['skipped'],
                'cancelled' => $counts['cancelled'],
                'eligible_total' => $progressMath['eligible_total'],
                'processed' => $progressMath['processed'],
                'percent' => $progressMath['percent'],
                'raw_percent' => $progressMath['raw_percent'],
                'no_eligible_work' => $progressMath['no_eligible_work'],
                'last_completed_duration_seconds' => $lastCompletedSeconds,
                'last_completed_duration_label' => $lastCompletedSeconds !== null
                    ? self::formatDuration($lastCompletedSeconds)
                    : null,
                'presentation' => $presentation,
            ],
            'now_playing' => $nowPlaying,
            'playlist' => $playlist,
        ];
    }

    /**
     * @return array{
     *     has_run: bool,
     *     run: null,
     *     overview: array{
     *         total: int,
     *         completed: int,
     *         processing: int,
     *         failed: int,
     *         pending: int,
     *         skipped: int,
     *         cancelled: int,
     *         percent: int,
     *         last_completed_duration_seconds: null,
     *         last_completed_duration_label: null
     *     },
     *     now_playing: null,
     *     playlist: list<empty>
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'has_run' => false,
            'run' => null,
            'overview' => [
                'total' => 0,
                'snapshot_total' => 0,
                'completed' => 0,
                'processing' => 0,
                'failed' => 0,
                'pending' => 0,
                'skipped' => 0,
                'cancelled' => 0,
                'eligible_total' => 0,
                'processed' => 0,
                'percent' => 0,
                'raw_percent' => 0,
                'no_eligible_work' => false,
                'last_completed_duration_seconds' => null,
                'last_completed_duration_label' => null,
                'presentation' => ContentProjectProgressPresentation::present(
                    hasRun: false,
                    runStatus: null,
                    snapshotTotal: 0,
                    succeeded: 0,
                    failed: 0,
                    skipped: 0,
                    pending: 0,
                    processing: 0,
                    cancelled: 0,
                    isTerminal: false,
                ),
            ],
            'now_playing' => null,
            'playlist' => [],
        ];
    }

    /**
     * @return array{policy: ?string, label: ?string}
     */
    private function resolveRunAiMode(SeoProjectRun $run): array
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $raw = $settings[AiCostPolicy::SETTING_KEY]
            ?? $settings['ai_generation_mode']
            ?? null;
        if ($raw === null || $raw === '') {
            return ['policy' => null, 'label' => null];
        }

        $policy = AiCostPolicy::tryFromMixed($raw);

        return [
            'policy' => $policy->value,
            'label' => $policy->generationModeValue() === 'free_only'
                ? (string) __('seo-content-ai::filament.projects.mode_free')
                : (string) __('seo-content-ai::filament.projects.mode_fast'),
        ];
    }

    private function resolveDisplayRun(int $projectId): ?SeoProjectRun
    {
        $active = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->whereIn('status', [
                SeoProjectRun::STATUS_RUNNING,
                SeoProjectRun::STATUS_STOPPING,
            ])
            ->notConsolidated()
            ->orderByDesc('id')
            ->first([
                'id',
                'project_id',
                'status',
                'settings',
                'started_at',
                'finished_at',
                'total',
                'succeeded',
                'failed',
            ]);

        if ($active instanceof SeoProjectRun) {
            return $active;
        }

        return SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->notConsolidated()
            ->orderByDesc('id')
            ->first([
                'id',
                'project_id',
                'status',
                'settings',
                'started_at',
                'finished_at',
                'total',
                'succeeded',
                'failed',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function engineBag(SeoProjectRun $run): array
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] ?? null;

        return is_array($engine) ? $engine : [];
    }

    /**
     * @param  Collection<int, SeoProjectRunItem>  $items
     * @return array<int|string, string>
     */
    private function resolveTitles(Collection $items): array
    {
        $taskIds = $items->pluck('task_id')->map(static fn (mixed $id): int => (int) $id)->filter()->unique()->values()->all();
        $articleIds = $items->pluck('article_id')->map(static fn (mixed $id): int => (int) $id)->filter()->unique()->values()->all();
        $out = [];

        if ($taskIds !== []) {
            SeoProjectTask::query()
                ->whereIn('id', $taskIds)
                ->get(['id', 'title', 'article_id'])
                ->each(static function (SeoProjectTask $task) use (&$out): void {
                    $title = trim((string) ($task->title ?? ''));
                    if ($title !== '') {
                        $out[(int) $task->id] = $title;
                    }
                });
        }

        if ($articleIds !== []) {
            SeoArticle::query()
                ->whereIn('id', $articleIds)
                ->get(['id', 'title'])
                ->each(static function (SeoArticle $article) use (&$out): void {
                    $title = trim((string) ($article->title ?? ''));
                    if ($title !== '') {
                        $out['a:'.(int) $article->id] = $title;
                    }
                });
        }

        return $out;
    }

    /**
     * @param  Collection<int, SeoProjectRunItem>  $items
     * @return array<int, string>
     */
    private function resolveThumbnails(Collection $items): array
    {
        $articleIds = $items->pluck('article_id')->map(static fn (mixed $id): int => (int) $id)->filter()->unique()->values()->all();
        if ($articleIds === []) {
            return [];
        }

        $out = [];
        SeoArticle::query()
            ->whereIn('id', $articleIds)
            ->with(['articleMetas' => static function ($query): void {
                $query->where('meta_key', 'wp_featured_image_url')->limit(1);
            }])
            ->get(['id'])
            ->each(static function (SeoArticle $article) use (&$out): void {
                $url = trim((string) (
                    $article->articleMetas->firstWhere('meta_key', 'wp_featured_image_url')?->meta_value ?? ''
                ));
                if ($url !== '') {
                    $out[(int) $article->id] = $url;
                }
            });

        return $out;
    }

    private function bucketForStatus(SeoProjectRunItemStatus $status, bool $runCancelled): string
    {
        return match ($status) {
            SeoProjectRunItemStatus::Success => 'completed',
            SeoProjectRunItemStatus::Processing => 'processing',
            SeoProjectRunItemStatus::Failed => 'failed',
            SeoProjectRunItemStatus::Skipped => 'skipped',
            SeoProjectRunItemStatus::Pending,
            SeoProjectRunItemStatus::Manual,
            SeoProjectRunItemStatus::Paused => $runCancelled ? 'cancelled' : 'pending',
        };
    }

    private function statusLabel(SeoProjectRunItemStatus $status, string $bucket): string
    {
        return match ($bucket) {
            'completed' => (string) __('seo-content-ai::filament.projects.progress_status_completed'),
            'processing' => (string) __('seo-content-ai::filament.projects.progress_status_processing'),
            'failed' => (string) __('seo-content-ai::filament.projects.progress_status_failed'),
            'skipped' => (string) __('seo-content-ai::filament.projects.progress_status_skipped'),
            'cancelled' => (string) __('seo-content-ai::filament.projects.progress_status_cancelled'),
            default => (string) __('seo-content-ai::filament.projects.progress_status_pending'),
        };
    }

    private function durationSeconds(SeoProjectRunItem $item, SeoProjectRunItemStatus $status): ?int
    {
        $started = $item->started_at;
        if (! $started instanceof CarbonInterface) {
            return null;
        }

        if ($status === SeoProjectRunItemStatus::Processing) {
            return max(0, (int) $started->diffInSeconds(now(), false));
        }

        $finished = $item->finished_at;
        if (! $finished instanceof CarbonInterface) {
            return null;
        }

        return max(0, (int) $started->diffInSeconds($finished, false));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $activeDispatch
     * @return array<string, mixed>
     */
    private function buildNowPlaying(
        array $row,
        int $total,
        SeoProjectRun $run,
        ?array $activeDispatch,
    ): array {
        $startedAt = $run->started_at instanceof CarbonInterface
            ? $run->started_at
            : null;
        $elapsed = $row['duration_seconds'] ?? null;
        if ($elapsed === null && $startedAt instanceof CarbonInterface) {
            $elapsed = max(0, (int) $startedAt->diffInSeconds(now(), false));
        }

        $step = $row['step_label']
            ?? (
                is_array($activeDispatch)
                    ? ContentProjectArticleRuntimeStatusResolver::stepLabel($activeDispatch['current_step'] ?? null)
                    : null
            )
            ?? ContentProjectArticleRuntimeStatus::STEP_WRITING;

        return [
            'run_item_id' => $row['run_item_id'],
            'position' => $row['position'],
            'total' => $total,
            'title' => $row['title'],
            'thumbnail_url' => $row['thumbnail_url'],
            'article_id' => $row['article_id'],
            'article_url' => $row['article_url'],
            'status' => $row['status'],
            'status_label' => $row['status_label'],
            'step_label' => $step,
            'elapsed_seconds' => $elapsed,
            'elapsed_label' => is_int($elapsed) ? self::formatDuration($elapsed) : null,
            'run_status' => (string) $run->status,
        ];
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return $hours.' giờ '.$minutes.' phút '.$secs.' giây';
        }

        if ($minutes > 0) {
            return $minutes.' phút '.$secs.' giây';
        }

        return $secs.' giây';
    }
}
