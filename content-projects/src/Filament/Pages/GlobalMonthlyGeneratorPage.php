<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StopGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyAiPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchProgressReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Manager/Planner Global Monthly Generator — setup / progress playlist / history.
 */
final class GlobalMonthlyGeneratorPage extends SeoPanelPage
{
    protected static ?string $slug = 'global-monthly-generator';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.global-monthly-generator';

    #[Url(as: 'month')]
    public ?string $planningMonth = null;

    #[Url(as: 'batch')]
    public ?int $batchId = null;

    /** Selected AI Mode for new Global batches (default: Auto Cost). */
    public string $aiCostPolicy = GlobalMonthlyAiPolicy::AUTO_COST;

    /** @var list<int> */
    public array $selectedProjectIds = [];

    public bool $selectAllProjects = true;

    public string $activeTab = 'setup';

    /** @var array<string, mixed> */
    public array $preflight = [];

    /** @var array<string, mixed> */
    public array $progress = [];

    /** @var list<array<string, mixed>> */
    public array $history = [];

    /** @var array{state: string, holder: ?array<string, mixed>, message: ?string, reason: ?string} */
    public array $lockInspect = [
        'state' => GlobalGenerationLock::STATE_UNAVAILABLE,
        'holder' => null,
        'message' => null,
        'reason' => null,
    ];

    public static function canAccess(): bool
    {
        return SeoAccessControl::canMutateContentProjects(Auth::user());
    }

    public function mount(): void
    {
        $this->planningMonth = ContentProjectMonthContext::normalize($this->planningMonth);
        $this->aiCostPolicy = GlobalMonthlyAiPolicy::AUTO_COST;
        $this->refreshLock();
        $this->refreshPreflight();
        $this->refreshHistory();

        if ($this->batchId === null || $this->batchId <= 0) {
            try {
                if (is_array($this->lockInspect['holder'] ?? null)
                    && ($this->lockInspect['holder']['owner_type'] ?? '') === SeoContentGenerationLock::OWNER_GLOBAL_BATCH) {
                    $this->batchId = (int) ($this->lockInspect['holder']['owner_id'] ?? 0);
                } else {
                    $activeBatch = SeoContentGlobalBatch::query()
                        ->where('planning_month', (string) $this->planningMonth)
                        ->whereIn('status', [
                            SeoContentGlobalBatch::STATUS_RUNNING,
                            SeoContentGlobalBatch::STATUS_PENDING,
                            SeoContentGlobalBatch::STATUS_STOPPING,
                        ])
                        ->latest('id')
                        ->first()
                        ?? SeoContentGlobalBatch::query()
                            ->whereIn('status', [
                                SeoContentGlobalBatch::STATUS_RUNNING,
                                SeoContentGlobalBatch::STATUS_PENDING,
                                SeoContentGlobalBatch::STATUS_STOPPING,
                            ])
                            ->latest('id')
                            ->first();

                    if ($activeBatch instanceof SeoContentGlobalBatch) {
                        $this->batchId = (int) $activeBatch->id;
                    }
                }
            } catch (\Throwable) {
                // resilient if table not yet migrated or query fails
            }
        }

        if ($this->batchId !== null && $this->batchId > 0) {
            $this->activeTab = 'progress';
            $this->refreshProgress();
        }
    }

    public function updatedPlanningMonth(): void
    {
        $this->planningMonth = ContentProjectMonthContext::normalize($this->planningMonth);
        $this->selectedProjectIds = [];
        $this->selectAllProjects = true;
        $this->refreshPreflight();
    }

    public function updatedAiCostPolicy(): void
    {
        $this->aiCostPolicy = GlobalMonthlyAiPolicy::normalizeBatchPolicy($this->aiCostPolicy);
    }

    public function updatedSelectAllProjects(bool $value): void
    {
        if ($value) {
            $this->selectedProjectIds = $this->defaultEligibleProjectIds();
        } else {
            $this->selectedProjectIds = [];
        }
    }

    public function updatedSelectedProjectIds(): void
    {
        $eligibleIds = $this->defaultEligibleProjectIds();
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $this->selectedProjectIds),
            fn (int $id): bool => $id > 0 && in_array($id, $eligibleIds, true),
        )));
        $this->selectedProjectIds = $normalized;
        $this->selectAllProjects = $eligibleIds !== [] && count($normalized) === count($eligibleIds);
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['setup', 'progress', 'history'], true)) {
            return;
        }

        $this->activeTab = $tab;
        if ($tab === 'setup') {
            $this->refreshPreflight();
        } elseif ($tab === 'progress') {
            $this->refreshProgress();
        } elseif ($tab === 'history') {
            $this->refreshHistory();
        }
    }

    public function refreshLock(): void
    {
        try {
            $this->lockInspect = app(GlobalGenerationLock::class)->inspect();
        } catch (\Throwable) {
            $this->lockInspect = [
                'state' => GlobalGenerationLock::STATE_UNAVAILABLE,
                'holder' => null,
                'message' => GlobalGenerationLock::UNAVAILABLE_MESSAGE,
                'reason' => 'inspect_exception',
            ];
        }
    }

    public function refreshPreflight(): void
    {
        $lock = app(GlobalGenerationLock::class);
        try {
            $this->lockInspect = $lock->inspect();
            $this->preflight = app(GlobalMonthlyBatchSnapshotBuilder::class)
                ->preflight((string) $this->planningMonth, $lock);
            $this->syncSelectedProjectsFromPreflight();
        } catch (\Throwable) {
            $this->lockInspect = $lock->inspect();
            $this->preflight = [
                'projects' => [],
                'eligible_count' => 0,
                'legacy_active_runs' => [],
                'lock_busy' => false,
                'lock_unavailable' => ($this->lockInspect['state'] ?? '') === GlobalGenerationLock::STATE_UNAVAILABLE,
                'lock_state' => (string) ($this->lockInspect['state'] ?? GlobalGenerationLock::STATE_UNAVAILABLE),
                'lock_holder' => $this->lockInspect['holder'] ?? null,
                'lock_message' => (string) ($this->lockInspect['message'] ?? GlobalGenerationLock::UNAVAILABLE_MESSAGE),
                'can_start' => false,
            ];
            $this->selectedProjectIds = [];
            $this->selectAllProjects = false;
        }
    }

    public function refreshProgress(): void
    {
        $this->refreshLock();
        if ($this->batchId === null || $this->batchId <= 0) {
            $this->progress = [];

            return;
        }

        try {
            $batch = SeoContentGlobalBatch::query()->find($this->batchId);
            if (! $batch instanceof SeoContentGlobalBatch) {
                $this->progress = ['not_found' => true];

                return;
            }

            $this->progress = app(GlobalMonthlyBatchProgressReadModel::class)->forBatch($batch);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('GlobalMonthlyGeneratorPage::refreshProgress failed: '.$e->getMessage(), [
                'exception' => $e,
                'batch_id' => $this->batchId,
            ]);
            $this->progress = ['error' => true];
        }
    }

    public function refreshHistory(): void
    {
        try {
            $this->history = SeoContentGlobalBatch::query()
                ->orderByDesc('id')
                ->limit(30)
                ->get(['id', 'planning_month', 'status', 'total_items', 'succeeded', 'failed', 'skipped', 'started_at', 'finished_at', 'ai_cost_policy', 'project_order', 'settings'])
                ->map(static function (SeoContentGlobalBatch $batch): array {
                    $settings = is_array($batch->settings) ? $batch->settings : [];
                    $policyToken = GlobalMonthlyAiPolicy::normalizeBatchPolicy($batch->ai_cost_policy);
                    $isAutoCost = GlobalMonthlyAiPolicy::isAutoCost($policyToken);
                    $freeOk = (int) ($settings['free_succeeded'] ?? 0);
                    $paidOk = (int) ($settings['paid_fallback_succeeded'] ?? 0);
                    $projectCount = is_array($batch->project_order)
                        ? count(array_unique(array_filter($batch->project_order)))
                        : (int) ($settings['selected_project_count'] ?? 0);

                    return [
                        'id' => (int) $batch->id,
                        'planning_month' => ContentProjectMonthContext::normalize($batch->planning_month),
                        'status' => (string) $batch->status,
                        'total_items' => (int) $batch->total_items,
                        'succeeded' => (int) $batch->succeeded,
                        'failed' => (int) $batch->failed,
                        'skipped' => (int) $batch->skipped,
                        'project_count' => $projectCount,
                        'started_at' => $batch->started_at?->toDateTimeString(),
                        'finished_at' => $batch->finished_at?->toDateTimeString(),
                        'ai_cost_policy' => $policyToken,
                        'requested_mode_label' => GlobalMonthlyAiPolicy::modeLabel($policyToken),
                        'is_auto_cost' => $isAutoCost,
                        'free_succeeded' => $freeOk,
                        'paid_fallback_succeeded' => $paidOk,
                    ];
                })
                ->all();
        } catch (\Throwable) {
            $this->history = [];
        }
    }

    /**
     * @return list<int>
     */
    public function defaultEligibleProjectIds(): array
    {
        $ids = [];
        foreach (($this->preflight['projects'] ?? []) as $project) {
            $pid = (int) ($project['project_id'] ?? 0);
            $eligible = (int) ($project['eligible_count'] ?? 0);
            if ($pid > 0 && $eligible > 0) {
                $ids[] = $pid;
            }
        }

        return array_values(array_unique($ids));
    }

    public function syncSelectedProjectsFromPreflight(): void
    {
        $eligibleIds = $this->defaultEligibleProjectIds();
        if ($eligibleIds === []) {
            $this->selectedProjectIds = [];
            $this->selectAllProjects = false;

            return;
        }

        if ($this->selectedProjectIds === [] && $this->selectAllProjects) {
            $this->selectedProjectIds = $eligibleIds;

            return;
        }

        $filtered = array_values(array_intersect($eligibleIds, array_map('intval', $this->selectedProjectIds)));
        $this->selectedProjectIds = $filtered;
        $this->selectAllProjects = count($filtered) === count($eligibleIds);
    }

    /**
     * @return array{project_count: int, eligible_count: int, pending_count: int, failed_count: int}
     */
    public function selectedSummary(): array
    {
        $selectedSet = array_flip(array_map('intval', $this->selectedProjectIds));
        $projectCount = 0;
        $eligibleCount = 0;
        $pendingCount = 0;
        $failedCount = 0;

        foreach (($this->preflight['projects'] ?? []) as $project) {
            $pid = (int) ($project['project_id'] ?? 0);
            if ($pid <= 0 || ! isset($selectedSet[$pid])) {
                continue;
            }
            $eligible = (int) ($project['eligible_count'] ?? 0);
            if ($eligible <= 0) {
                continue;
            }
            $projectCount++;
            $eligibleCount += $eligible;
            $pendingCount += (int) ($project['pending_count'] ?? $eligible);
            $failedCount += (int) ($project['failed_count'] ?? 0);
        }

        return [
            'project_count' => $projectCount,
            'eligible_count' => $eligibleCount,
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
        ];
    }

    public function canStartBatch(): bool
    {
        $summary = $this->selectedSummary();

        return ($this->preflight['can_start'] ?? false)
            && ($this->preflight['eligible_count'] ?? 0) > 0
            && $summary['project_count'] > 0
            && $summary['eligible_count'] > 0
            && ($this->preflight['legacy_active_runs'] ?? []) === [];
    }

    /**
     * Setup button visual gate: ready | blocked | running | recovery.
     */
    public function startGateState(): string
    {
        $lockState = (string) ($this->lockInspect['state'] ?? GlobalGenerationLock::STATE_UNAVAILABLE);
        $holder = is_array($this->lockInspect['holder'] ?? null) ? $this->lockInspect['holder'] : null;

        if ($lockState === GlobalGenerationLock::STATE_HELD && ! empty($holder['is_recoverable'])) {
            return 'recovery';
        }
        if ($lockState === GlobalGenerationLock::STATE_HELD) {
            return 'running';
        }
        if ($this->canStartBatch()) {
            return 'ready';
        }

        return 'blocked';
    }

    public function startBlockReason(): ?string
    {
        if ($this->canStartBatch()) {
            return null;
        }

        $gate = $this->startGateState();
        $holder = is_array($this->lockInspect['holder'] ?? null) ? $this->lockInspect['holder'] : null;
        $label = (string) ($holder['owner_label'] ?? $holder['label'] ?? 'Global Batch');

        $summary = $this->selectedSummary();
        $noGlobalEligible = ($this->preflight['eligible_count'] ?? 0) <= 0;
        $noSelectedProjects = $summary['project_count'] <= 0 || $summary['eligible_count'] <= 0;

        return match ($gate) {
            'recovery' => $label.' đang chờ recovery hoặc resume — không thể bắt đầu Generate mới.',
            'running' => GlobalGenerationLock::BUSY_MESSAGE,
            default => (string) (
                $noGlobalEligible
                    ? 'Không có bài eligible.'
                    : ($noSelectedProjects
                        ? 'Chưa chọn Project hợp lệ để Generate.'
                        : (($this->preflight['legacy_active_runs'] ?? []) !== []
                            ? GlobalGenerationLock::BUSY_MESSAGE.' (legacy Project Run đang chạy).'
                            : ($this->preflight['lock_message'] ?? GlobalGenerationLock::UNAVAILABLE_MESSAGE)))
            ),
        };
    }

    public function startBatch(): void
    {
        $this->refreshPreflight();
        if (! $this->canStartBatch()) {
            $msg = $this->startBlockReason()
                ?? (string) ($this->preflight['lock_message'] ?? GlobalGenerationLock::BUSY_MESSAGE);
            Notification::make()->title($msg)->danger()->send();

            return;
        }

        $policy = GlobalMonthlyAiPolicy::normalizeBatchPolicy($this->aiCostPolicy);
        $selectedIds = array_values(array_unique(array_filter(
            array_map('intval', $this->selectedProjectIds),
            static fn (int $id): bool => $id > 0,
        )));

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new StartGlobalMonthlyBatchCommand(
                (string) $this->planningMonth,
                $policy,
                $selectedIds,
            ),
            ActorContext::user((int) (Auth::id() ?? 0)),
        );

        if (! $result->success) {
            Notification::make()->title($result->message)->danger()->send();
            $this->refreshPreflight();

            return;
        }

        $this->batchId = (int) ($result->metadata['batch_id'] ?? 0);
        $this->activeTab = 'progress';
        $this->refreshProgress();
        $this->refreshHistory();
        Notification::make()->title('Đã bắt đầu Global Generate')->success()->send();
    }

    public function stopBatch(): void
    {
        if ($this->batchId === null || $this->batchId <= 0) {
            return;
        }

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new StopGlobalMonthlyBatchCommand($this->batchId, 'Emergency stop from Global Generator UI.'),
            ActorContext::user((int) (Auth::id() ?? 0)),
        );

        Notification::make()
            ->title($result->message)
            ->{$result->success ? 'success' : 'danger'}()
            ->send();
        $this->refreshProgress();
    }

    public function resumeBatch(): void
    {
        if ($this->batchId === null || $this->batchId <= 0) {
            return;
        }

        $this->refreshLock();
        if (($this->lockInspect['state'] ?? '') === GlobalGenerationLock::STATE_UNAVAILABLE) {
            Notification::make()->title(GlobalGenerationLock::UNAVAILABLE_MESSAGE)->danger()->send();

            return;
        }

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new ResumeGlobalMonthlyBatchCommand($this->batchId),
            ActorContext::user((int) (Auth::id() ?? 0)),
        );

        Notification::make()
            ->title($result->message)
            ->{$result->success ? 'success' : 'danger'}()
            ->send();
        $this->refreshProgress();
    }

    public function openBatch(int $batchId): void
    {
        $this->batchId = $batchId;
        $this->activeTab = 'progress';
        $this->refreshProgress();
    }

    public function getTitle(): string
    {
        return 'Generate tháng';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Generate tháng';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $month = ContentProjectMonthContext::display($this->planningMonth);
        $totalProjects = count($this->preflight['projects'] ?? []);

        return "Tháng {$month} · {$totalProjects} Project";
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('tabs')
                ->view('seo-content-ai::filament.pages.partials.global-monthly-tabs'),
        ];
    }
}
