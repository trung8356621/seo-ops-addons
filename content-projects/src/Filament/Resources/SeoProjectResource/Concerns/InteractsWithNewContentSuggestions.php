<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectDraftAiHistory;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectPlannerRunDetail;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteTargetAllocator;
use Omnichannel\Addons\ContentProjects\Jobs\GenerateNewContentSuggestionsJob;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationBatchPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationReadiness;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationReadinessService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Create-new AI planner — shared by ViewSeoProject Draft card and ContentProjectNewContentPlanner.
 */
trait InteractsWithNewContentSuggestions
{
    public int|string $newContentQuantity = 20;

    public string $newContentNotes = '';

    public string $newContentPostType = NewContentSuggestionOptions::CONTENT_TYPE_POST;

    public string $newContentLastResult = '';

    public ?int $newContentActiveRunId = null;

    public string $newContentActiveStatus = '';

    /**
     * Livewire snapshot compat only — inline results drawer was replaced by
     * ContentProjectPlannerRunDetail. Keep declared so stale client snapshots
     * do not break unrelated actions (e.g. send to publishing queue).
     */
    public ?int $newContentViewRunId = null;

    /** @var array<string, mixed>|null Livewire snapshot compat — see $newContentViewRunId. */
    public ?array $newContentViewResults = null;

    /**
     * Livewire snapshot compat only — inline planner history drawer removed.
     *
     * @var list<array<string, mixed>>
     */
    public array $newContentHistory = [];

    /** @var array<string, mixed>|null */
    public ?array $newContentPlanningPreview = null;

    protected function resolveNewContentProject(): ?SeoProject
    {
        if (method_exists($this, 'resolvePlannerProject')) {
            /** @var callable $resolver */
            $resolver = [$this, 'resolvePlannerProject'];

            $project = $resolver();

            return $project instanceof SeoProject ? $project : null;
        }

        return property_exists($this, 'project') && $this->project instanceof SeoProject
            ? $this->project
            : null;
    }

    public function mountInteractsWithNewContentSuggestions(): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            return;
        }

        $this->newContentQuantity = 20;
        $this->newContentNotes = '';
        $this->newContentPostType = NewContentSuggestionOptions::CONTENT_TYPE_POST;
        if (method_exists($this, 'mountInteractsWithAuditNotes')) {
            $this->mountInteractsWithAuditNotes();
        }

        $this->refreshNewContentRunState();
        $this->refreshNewContentPlanningPreview();
    }

    public function refreshNewContentPlanningPreview(): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->newContentPlanningPreview = null;

            return;
        }

        $site = $this->resolveNewContentWorkingSite();
        if (! $site instanceof Site) {
            $this->newContentPlanningPreview = null;

            return;
        }

        try {
            $language = (string) (app(SitePrimaryLanguageService::class)->resolvePrimaryLanguage($site) ?? '');
            $summary = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService::class)
                ->summarize($project, $site, $language);
            $this->newContentPlanningPreview = [
                'principal_keywords_count' => (int) ($summary['principal_keyword_count'] ?? 0),
                'cluster_count' => (int) ($summary['cluster_count'] ?? 0),
                'missing_direction_count' => (int) ($summary['missing_direction_count'] ?? 0),
                'mcp_period' => $summary['mcp_period'] ?? null,
            ];
        } catch (Throwable) {
            $this->newContentPlanningPreview = null;
        }
    }

    public function generateNewContentSuggestions(?array $noteItems = null): void
    {
        if ($noteItems !== null && method_exists($this, 'applyAuditNoteItems')) {
            $this->applyAuditNoteItems($noteItems);
        }

        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->notifyNewContentProjectRequired();

            return;
        }
        if (! $project->isDraftPlanning()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_draft_only'))
                ->warning()
                ->send();

            return;
        }

        $readiness = $this->resolveNewContentReadiness($project);
        if (! $readiness->generateEnabled) {
            $reason = $readiness->blockReasons[0]
                ?? (string) __('seo-content-ai::filament.projects.planner_generate_busy');
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_blocked'))
                ->body($reason)
                ->warning()
                ->send();

            return;
        }

        $options = $this->buildNewContentOptions();
        $noteItems = is_array($options['note_items'] ?? null) ? $options['note_items'] : [];
        $requestedFromTargets = AuditNoteDnaNormalizer::totalTargetDnaCount($noteItems);

        if ($requestedFromTargets <= 0 || $noteItems === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_blocked'))
                ->body((string) __('seo-content-ai::filament.projects.audit_notes_empty_plan'))
                ->warning()
                ->send();

            return;
        }

        $batchPolicy = new NewContentGenerationBatchPolicy;
        if ($batchPolicy->exceedsHardCeiling($requestedFromTargets)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_blocked'))
                ->body((string) __('seo-content-ai::filament.projects.planner_plan_too_large'))
                ->warning()
                ->send();

            return;
        }

        // Sticky pool = SUM(targets). Allocator may redistribute AUTO topics by MCP within that pool.
        $allocation = AuditNoteTargetAllocator::apply($noteItems, $requestedFromTargets);
        if ($allocation['code'] === AuditNoteTargetAllocator::CODE_TOO_MANY_TOPICS) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_blocked'))
                ->body((string) __('seo-content-ai::filament.projects.audit_notes_too_many_topics', [
                    'topics' => $allocation['topic_count'],
                    'quantity' => $allocation['requested_quantity'],
                ]))
                ->warning()
                ->send();

            return;
        }
        if (method_exists($this, 'applyAuditNoteItems')) {
            $this->applyAuditNoteItems($allocation['items']);
        }
        $options['note_items'] = $allocation['items'];
        $options['quantity'] = max(1, (int) $allocation['total_target']);
        $this->newContentQuantity = $options['quantity'];

        $workingSite = $this->resolveNewContentWorkingSite();
        $workingSiteId = $workingSite instanceof Site ? (int) $workingSite->getKey() : 0;
        if ($workingSiteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_primary_language_missing'))
                ->body((string) __('seo-content-ai::filament.projects.planner_readiness_language_missing'))
                ->warning()
                ->send();

            return;
        }

        $idemKey = 'new-content:'.$project->getKey().':'.$workingSiteId.':'.Str::uuid()->toString();

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                new GenerateNewContentSuggestionsCommand(
                    (int) $project->getKey(),
                    $workingSiteId,
                    $options['quantity'],
                    $options,
                ),
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    $workingSiteId,
                    $idemKey,
                ),
            );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.generate_new_content_suggestions',
                'project_id' => (int) $project->getKey(),
                'site_id' => $workingSiteId,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($result->code === ContentProjectActionCodes::PRIMARY_LANGUAGE_MISSING) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_primary_language_missing'))
                ->warning()
                ->send();

            return;
        }

        $this->newContentLastResult = $result->message;
        $this->newContentActiveRunId = (int) ($result->metadata['planner_run_id'] ?? 0) ?: null;
        $this->newContentActiveStatus = (string) ($result->metadata['status'] ?? 'queued');

        Notification::make()
            ->title($result->success
                ? __('seo-content-ai::filament.projects.planner_generate_started')
                : __('seo-content-ai::filament.projects.planner_generate_failed'))
            ->body($result->message)
            ->{$result->success ? 'success' : 'danger'}()
            ->send();
    }

    public function refreshNewContentRun(): void
    {
        $before = $this->newContentActiveStatus;
        $this->refreshNewContentRunState();
        $after = $this->newContentActiveStatus;

        if ($before !== '' && $before !== $after && in_array($after, ['completed', 'partial', 'failed', ''], true)) {
            if (method_exists($this, 'dispatch')) {
                $this->dispatch('cp-ops-refresh');
            }
            if ($this->newContentLastResult !== '') {
                $emptySuccess = $after === 'completed'
                    && (preg_match('/\b0 added\b/i', $this->newContentLastResult) === 1
                        || preg_match('/·\s*0\s+added/i', $this->newContentLastResult) === 1);

                $titleKey = match ($after) {
                    'failed' => 'seo-content-ai::filament.projects.planner_generate_failed',
                    'partial' => 'seo-content-ai::filament.projects.planner_generate_partial',
                    default => $emptySuccess
                        ? 'seo-content-ai::filament.projects.planner_generate_empty'
                        : 'seo-content-ai::filament.projects.planner_generate_done',
                };

                Notification::make()
                    ->title(__($titleKey))
                    ->body($this->newContentLastResult)
                    ->{in_array($after, ['failed'], true) ? 'danger' : ($after === 'partial' ? 'warning' : 'success')}()
                    ->send();
            }
        }
    }

    public function newContentPlannerRunDetailUrl(int $runId): string
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject || $runId <= 0) {
            return '#';
        }

        return ContentProjectPlannerRunDetail::urlFor($project, $runId);
    }

    public function newContentDraftAiHistoryUrl(): string
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            return '#';
        }

        return ContentProjectDraftAiHistory::urlForProject($project);
    }

    public function restoreNewContentFingerprint(string $fingerprint): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->notifyNewContentProjectRequired();

            return;
        }

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new RestoreNewContentSuggestionsCommand((int) $project->getKey(), [$fingerprint]),
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                $this->resolveNewContentWorkingSiteId() ?: null,
            ),
        );

        Notification::make()
            ->title($result->success
                ? __('seo-content-ai::filament.projects.suggestions_action_done')
                : 'Failed')
            ->{$result->success ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function getNewContentPlannerPayloadProperty(): array
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $readiness = $this->resolveNewContentReadiness(null);

            return [
                'can_write' => false,
                'quantity_enabled' => false,
                'generate_enabled' => false,
                'has_project' => false,
                'is_draft' => false,
                'primary_configured' => false,
                'primary_language_label' => null,
                'domain_edit_url' => null,
                'is_generating' => false,
                'block_reasons' => $readiness->blockReasons,
                'readiness' => $readiness->toArray(),
            ];
        }

        $readiness = $this->resolveNewContentReadiness($project);
        $generating = $readiness->generation['active'] === true;
        if ($generating) {
            $this->newContentActiveRunId = (int) ($readiness->generation['run_id'] ?? 0) ?: null;
            $this->newContentActiveStatus = (string) ($readiness->generation['status'] ?? 'queued');
        } elseif ($this->newContentActiveRunId !== null
            && ! in_array($this->newContentActiveStatus, SeoContentProjectPlannerRun::activeStatuses(), true)
        ) {
            $this->newContentActiveRunId = null;
        }

        $workingSiteId = $this->resolveNewContentWorkingSiteId();

        // Last result must be site-scoped — never show another domain's partial/completion banner.
        if (! $generating) {
            $latestForSite = app(ContentProjectPlannerRunService::class)
                ->listExecuted(
                    $project,
                    SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
                    1,
                    $workingSiteId > 0 ? $workingSiteId : null,
                )
                ->first();
            if ($latestForSite instanceof SeoContentProjectPlannerRun) {
                $summary = is_array($latestForSite->result_summary) ? $latestForSite->result_summary : [];
                $this->newContentLastResult = trim((string) ($summary['message'] ?? ''));
            } else {
                $this->newContentLastResult = '';
            }
        } elseif ($this->newContentLastResult === '') {
            $latest = app(ContentProjectPlannerRunService::class)
                ->listExecuted(
                    $project,
                    SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
                    1,
                    $workingSiteId > 0 ? $workingSiteId : null,
                )
                ->first();
            if ($latest instanceof SeoContentProjectPlannerRun) {
                $summary = is_array($latest->result_summary) ? $latest->result_summary : [];
                $message = trim((string) ($summary['message'] ?? ''));
                if ($message !== '') {
                    $this->newContentLastResult = $message;
                }
            }
        }

        $progressAdded = 0;
        $progressRequested = 0;
        if ($generating && $this->newContentActiveRunId) {
            $activeRun = app(ContentProjectPlannerRunService::class)
                ->findForProject($project, (int) $this->newContentActiveRunId);
            if ($activeRun instanceof SeoContentProjectPlannerRun) {
                $summary = is_array($activeRun->result_summary) ? $activeRun->result_summary : [];
                $progressAdded = max(0, (int) ($summary['added'] ?? 0));
                $progressRequested = max(0, (int) ($summary['requested'] ?? $activeRun->requested_quantity ?? 0));
                $msg = trim((string) ($summary['message'] ?? ''));
                if ($msg !== '') {
                    $this->newContentLastResult = $msg;
                }
            }
        }

        $plannedTotal = 0;
        if (method_exists($this, 'auditNoteItemsForOptions')) {
            $plannedTotal = AuditNoteDnaNormalizer::totalTargetDnaCount($this->auditNoteItemsForOptions());
        }

        $partialFill = $this->resolveNewContentPartialFill($project);
        $activeStatus = (string) ($readiness->generation['status'] ?? '');
        $progressPhase = $activeStatus !== '' ? $activeStatus : 'running';
        $progressUserMessage = '';
        if ($generating) {
            $summaryMsg = trim((string) ($this->newContentLastResult ?? ''));
            if ($summaryMsg !== '') {
                $progressUserMessage = $summaryMsg;
            } elseif ($progressRequested > 0) {
                $progressUserMessage = (string) __('seo-content-ai::filament.projects.planner_auto_progress', [
                    'added' => $progressAdded,
                    'requested' => $progressRequested,
                    'remaining' => max(0, $progressRequested - $progressAdded),
                ]);
            }
        }

        return [
            'can_write' => $readiness->generateEnabled,
            'quantity_enabled' => $readiness->quantityEnabled,
            'generate_enabled' => $readiness->generateEnabled,
            'has_project' => true,
            'is_draft' => $project->isDraftPlanning(),
            'primary_configured' => $readiness->language['ready'],
            'primary_language_label' => $readiness->language['label'] ?? null,
            'domain_edit_url' => $readiness->language['domain_edit_url'] ?? null,
            'is_generating' => $generating,
            'active_run_id' => $this->newContentActiveRunId,
            'active_status' => $this->newContentActiveStatus,
            'last_result' => $this->newContentLastResult,
            'progress_added' => $progressAdded,
            'progress_requested' => $progressRequested,
            'progress_phase' => $progressPhase,
            'progress_user_message' => $progressUserMessage,
            'planned_total' => $plannedTotal,
            'partial_run_id' => $partialFill['run_id'],
            'partial_remaining' => $partialFill['remaining'],
            'partial_requested' => $partialFill['requested'],
            'partial_added' => $partialFill['added'],
            'can_fill_remaining' => $partialFill['can_fill'] && $readiness->generateEnabled && ! $generating,
            'supports_product' => $this->newContentSiteSupportsProduct($project),
            'content_type_options' => $this->newContentContentTypeOptions($project),
            'block_reasons' => $readiness->blockReasons,
            'readiness' => $readiness->toArray(),
            'working_site_id' => $this->resolveNewContentWorkingSiteId() ?: null,
        ];
    }

    /**
     * @return array{can_fill: bool, run_id: ?int, remaining: int, requested: int, added: int}
     */
    protected function resolveNewContentPartialFill(SeoProject $project): array
    {
        $workingSiteId = $this->resolveNewContentWorkingSiteId();
        $latest = app(ContentProjectPlannerRunService::class)
            ->listExecuted(
                $project,
                SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
                1,
                $workingSiteId > 0 ? $workingSiteId : null,
            )
            ->first();
        if (! $latest instanceof SeoContentProjectPlannerRun) {
            return ['can_fill' => false, 'run_id' => null, 'remaining' => 0, 'requested' => 0, 'added' => 0];
        }
        $summary = is_array($latest->result_summary) ? $latest->result_summary : [];
        if ((string) ($summary['status'] ?? '') !== SeoContentProjectPlannerRun::STATUS_PARTIAL) {
            return ['can_fill' => false, 'run_id' => null, 'remaining' => 0, 'requested' => 0, 'added' => 0];
        }
        $requested = max(0, (int) ($summary['requested'] ?? $latest->requested_quantity ?? 0));
        $added = max(0, (int) ($summary['added'] ?? 0));
        $remaining = max(0, (int) ($summary['remaining'] ?? ($requested - $added)));

        return [
            'can_fill' => $remaining > 0,
            'run_id' => (int) $latest->getKey(),
            'remaining' => $remaining,
            'requested' => $requested,
            'added' => $added,
        ];
    }

    public function fillRemainingNewContentSuggestions(): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject || ! $project->isDraftPlanning()) {
            $this->notifyNewContentProjectRequired();

            return;
        }

        $partial = $this->resolveNewContentPartialFill($project);
        if (! $partial['can_fill'] || $partial['run_id'] === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_blocked'))
                ->body((string) __('seo-content-ai::filament.projects.planner_fill_remaining_unavailable'))
                ->warning()
                ->send();

            return;
        }

        $workingSite = $this->resolveNewContentWorkingSite();
        if (! $workingSite instanceof Site) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_primary_language_missing'))
                ->warning()
                ->send();

            return;
        }

        $partialRun = app(ContentProjectPlannerRunService::class)
            ->findForProject($project, (int) $partial['run_id']);
        if (! $partialRun instanceof SeoContentProjectPlannerRun) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_blocked'))
                ->warning()
                ->send();

            return;
        }

        $runSiteId = (int) ($partialRun->site_id ?? 0);
        if ($runSiteId <= 0) {
            $snap = is_array($partialRun->configuration_snapshot) ? $partialRun->configuration_snapshot : [];
            $runSiteId = (int) ($snap['site_id'] ?? 0);
        }
        if ($runSiteId > 0 && $runSiteId !== (int) $workingSite->getKey()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_blocked'))
                ->body((string) __('seo-content-ai::filament.projects.planner_fill_remaining_unavailable'))
                ->warning()
                ->send();

            return;
        }

        try {
            $queued = app(NewContentSuggestionPlannerService::class)->queueFillRemaining(
                $project,
                $workingSite,
                $partialRun,
                auth()->id() !== null ? (int) auth()->id() : null,
            );
            $runId = (int) ($queued['planner_run_id'] ?? 0);
            if (! (bool) ($queued['already_active'] ?? false) && $runId > 0) {
                GenerateNewContentSuggestionsJob::dispatch(
                    $runId,
                    (int) $project->getKey(),
                    auth()->id() !== null ? (int) auth()->id() : 0,
                );
            }
            $this->newContentActiveRunId = $runId ?: null;
            $this->newContentActiveStatus = (string) ($queued['status'] ?? 'queued');
            $this->newContentLastResult = sprintf(
                'Đang tạo tiếp %d ý tưởng còn thiếu…',
                (int) ($queued['requested'] ?? $partial['remaining']),
            );
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_started'))
                ->body($this->newContentLastResult)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function resolveNewContentReadiness(?SeoProject $project): NewContentGenerationReadiness
    {
        return app(NewContentGenerationReadinessService::class)->evaluate(
            $project,
            $this->resolveNewContentWorkingSite(),
            auth()->id() !== null ? (int) auth()->id() : null,
        );
    }

    /**
     * Explicit working Site for AI New Content (Project Planner filterSiteId, else legacy project.site_id).
     */
    protected function resolveNewContentWorkingSite(): ?Site
    {
        if (method_exists($this, 'resolvePlanningSite')) {
            /** @var callable $resolver */
            $resolver = [$this, 'resolvePlanningSite'];
            $site = $resolver();
            if ($site instanceof Site) {
                return $site;
            }
        }

        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            return null;
        }

        $siteId = (int) ($project->site_id ?? 0);
        if ($siteId <= 0 || ! \Omnichannel\Addons\Seo\Support\SeoAccessControl::canAccessSite($siteId)) {
            return null;
        }

        $site = $project->site instanceof Site ? $project->site : Site::query()->find($siteId);

        return $site instanceof Site ? $site : null;
    }

    protected function resolveNewContentWorkingSiteId(): int
    {
        $site = $this->resolveNewContentWorkingSite();

        return $site instanceof Site ? (int) $site->getKey() : 0;
    }

    /**
     * @return NewContentSuggestionOptions::normalize array
     */
    protected function buildNewContentOptions(): array
    {
        $postType = NewContentSuggestionOptions::normalizeContentType($this->newContentPostType);
        $project = $this->resolveNewContentProject();
        if ($postType === NewContentSuggestionOptions::CONTENT_TYPE_PRODUCT
            && $project instanceof SeoProject
            && ! $this->newContentSiteSupportsProduct($project)
        ) {
            $postType = NewContentSuggestionOptions::CONTENT_TYPE_POST;
            $this->newContentPostType = $postType;
        }

        return NewContentSuggestionOptions::normalize([
            'quantity' => (int) $this->newContentQuantity,
            'direction' => NewContentSuggestionOptions::DIRECTION_AUTOMATIC,
            // Free-text notes retired — Selected note_items is the only prompt path.
            'notes' => '',
            'note_items' => method_exists($this, 'auditNoteItemsForOptions')
                ? $this->auditNoteItemsForOptions()
                : [],
            'focus' => '',
            'post_type' => $postType,
            'content_type' => $postType,
            'taxonomy' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function applyNewContentOptions(array $options): void
    {
        $normalized = NewContentSuggestionOptions::normalize($options);
        $this->newContentQuantity = $normalized['quantity'];
        $this->newContentNotes = $normalized['notes'];
        $this->newContentPostType = $normalized['content_type'];
        if (method_exists($this, 'applyAuditNoteItems')) {
            $this->applyAuditNoteItems(is_array($normalized['note_items'] ?? null) ? $normalized['note_items'] : []);
        }

        $project = $this->resolveNewContentProject();
        if ($project instanceof SeoProject
            && $this->newContentPostType === NewContentSuggestionOptions::CONTENT_TYPE_PRODUCT
            && ! $this->newContentSiteSupportsProduct($project)
        ) {
            $this->newContentPostType = NewContentSuggestionOptions::CONTENT_TYPE_POST;
        }
    }

    protected function refreshNewContentRunState(): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->newContentActiveRunId = null;
            $this->newContentActiveStatus = '';

            return;
        }

        app(NewContentGenerationReadinessService::class)->reconcileStaleActiveRun($project);

        $runs = app(ContentProjectPlannerRunService::class);
        $active = $runs->findActive($project, SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT);
        if ($active instanceof SeoContentProjectPlannerRun) {
            $this->newContentActiveRunId = (int) $active->getKey();
            $this->newContentActiveStatus = (string) (($active->result_summary ?? [])['status'] ?? 'queued');
            $this->newContentLastResult = (string) (($active->result_summary ?? [])['message'] ?? $this->newContentLastResult);

            return;
        }

        if ($this->newContentActiveRunId !== null && $this->newContentActiveRunId > 0) {
            $finished = $runs->findForProject($project, $this->newContentActiveRunId);
            if ($finished instanceof SeoContentProjectPlannerRun) {
                $summary = is_array($finished->result_summary) ? $finished->result_summary : [];
                $this->newContentActiveStatus = (string) ($summary['status'] ?? '');
                $this->newContentLastResult = (string) ($summary['message'] ?? $this->newContentLastResult);
            }
            if (! in_array($this->newContentActiveStatus, SeoContentProjectPlannerRun::activeStatuses(), true)) {
                $this->newContentActiveRunId = null;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function newContentContentTypeOptions(SeoProject $project): array
    {
        $options = [
            NewContentSuggestionOptions::CONTENT_TYPE_POST => (string) __('seo-content-ai::filament.projects.planner_content_type_post'),
        ];
        if ($this->newContentSiteSupportsProduct($project)) {
            $options[NewContentSuggestionOptions::CONTENT_TYPE_PRODUCT] = (string) __('seo-content-ai::filament.projects.planner_content_type_product');
        }

        return $options;
    }

    protected function newContentSiteSupportsProduct(SeoProject $project): bool
    {
        $site = $this->resolveNewContentWorkingSite();
        if ($site instanceof Site) {
            return $this->newContentSiteSupportsProductForSite($site);
        }

        // Legacy fallback for callers without working-site context.
        $siteId = (int) ($project->site_id ?? 0);
        if ($siteId <= 0) {
            return false;
        }

        return $this->newContentSiteSupportsProductForSite($siteId);
    }

    protected function newContentSiteSupportsProductForSite(Site|int $site): bool
    {
        $siteId = $site instanceof Site ? (int) $site->getKey() : (int) $site;
        if ($siteId <= 0) {
            return false;
        }

        try {
            $catalog = app(\Omnichannel\Addons\Content\Support\PublishCategoryOptionsAssembler::class)->forSite($siteId);
            if ((bool) ($catalog['status']['product_category']['ok'] ?? false)) {
                return true;
            }
            if (($catalog['product_category'] ?? []) !== []) {
                return true;
            }
        } catch (Throwable) {
            // Fall through to synced article evidence.
        }

        try {
            $query = \Omnichannel\Addons\Content\Models\SeoArticle::query()->where('site_id', $siteId);

            \Omnichannel\Addons\Content\Support\ArticleContentClassification::scopeContentType(
                $query,
                \Omnichannel\Addons\Content\Enums\ContentType::Product,
            );

            return $query->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{primary_configured: bool, primary_language: ?string, primary_language_label: ?string, domain_edit_url: ?string}
     */
    protected function newContentPrimaryLanguagePayload(SeoProject $project): array
    {
        unset($project);
        $site = $this->resolveNewContentWorkingSite();
        if (! $site instanceof Site) {
            return [
                'primary_configured' => false,
                'primary_language' => null,
                'primary_language_label' => null,
                'domain_edit_url' => null,
            ];
        }

        $siteId = (int) $site->getKey();
        $svc = app(SitePrimaryLanguageService::class);
        $code = $svc->resolvePrimaryLanguage($site);
        $configured = $code !== null && trim($code) !== '';

        return [
            'primary_configured' => $configured,
            'primary_language' => $configured ? trim((string) $code) : null,
            'primary_language_label' => $configured ? $svc->primaryLanguageLabel($site, $code) : null,
            'domain_edit_url' => DomainResource::getUrl('edit', ['record' => $siteId]),
        ];
    }

    protected function notifyNewContentProjectRequired(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.projects.seo_audit_draft_empty'))
            ->warning()
            ->send();
    }
}
