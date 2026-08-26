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
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationReadiness;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationReadinessService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
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

        $siteId = (int) ($project->site_id ?? 0);
        $site = $siteId > 0 ? Site::query()->find($siteId) : null;
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

    public function generateNewContentSuggestions(): void
    {
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
        $idemKey = 'new-content:'.$project->getKey().':'.Str::uuid()->toString();

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                new GenerateNewContentSuggestionsCommand(
                    (int) $project->getKey(),
                    $options['quantity'],
                    $options,
                ),
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($project->site_id ?? 0) ?: null,
                    $idemKey,
                ),
            );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.generate_new_content_suggestions',
                'project_id' => (int) $project->getKey(),
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

        if ($before !== '' && $before !== $after && in_array($after, ['completed', 'failed', ''], true)) {
            if (method_exists($this, 'dispatch')) {
                $this->dispatch('cp-ops-refresh');
            }
            if ($this->newContentLastResult !== '') {
                $emptySuccess = $after === 'completed'
                    && (preg_match('/\b0 added\b/i', $this->newContentLastResult) === 1
                        || preg_match('/·\s*0\s+added/i', $this->newContentLastResult) === 1);

                Notification::make()
                    ->title($after === 'failed'
                        ? __('seo-content-ai::filament.projects.planner_generate_failed')
                        : ($emptySuccess
                            ? __('seo-content-ai::filament.projects.planner_generate_empty')
                            : __('seo-content-ai::filament.projects.planner_generate_done')))
                    ->body($this->newContentLastResult)
                    ->{$after === 'failed' || $emptySuccess ? 'warning' : 'success'}()
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
                (int) ($project->site_id ?? 0) ?: null,
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
            && ! in_array($this->newContentActiveStatus, ['queued', 'running'], true)
        ) {
            $this->newContentActiveRunId = null;
        }

        if ($this->newContentLastResult === '') {
            $latest = app(ContentProjectPlannerRunService::class)
                ->listExecuted($project, SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT, 1)
                ->first();
            if ($latest instanceof SeoContentProjectPlannerRun) {
                $summary = is_array($latest->result_summary) ? $latest->result_summary : [];
                $message = trim((string) ($summary['message'] ?? ''));
                if ($message !== '') {
                    $this->newContentLastResult = $message;
                }
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
            'supports_product' => $this->newContentSiteSupportsProduct($project),
            'content_type_options' => $this->newContentContentTypeOptions($project),
            'block_reasons' => $readiness->blockReasons,
            'readiness' => $readiness->toArray(),
        ];
    }

    protected function resolveNewContentReadiness(?SeoProject $project): NewContentGenerationReadiness
    {
        return app(NewContentGenerationReadinessService::class)->evaluate(
            $project,
            auth()->id() !== null ? (int) auth()->id() : null,
        );
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
            'notes' => $this->newContentNotes,
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
            if (! in_array($this->newContentActiveStatus, ['queued', 'running'], true)) {
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
        $siteId = (int) ($project->site_id ?? 0);
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
            return \Omnichannel\Addons\Content\Models\SeoArticle::query()
                ->where('site_id', $siteId)
                ->where(static function ($q): void {
                    $q->where('type', 'product')
                        ->orWhereHas('articleMetas', static function ($meta): void {
                            $meta->where('meta_key', 'wp_post_type')->where('meta_value', 'product');
                        });
                })
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{primary_configured: bool, primary_language: ?string, primary_language_label: ?string, domain_edit_url: ?string}
     */
    protected function newContentPrimaryLanguagePayload(SeoProject $project): array
    {
        $siteId = (int) ($project->site_id ?? 0);
        $site = $project->site instanceof Site ? $project->site : null;
        if (! $site instanceof Site && $siteId > 0) {
            $site = Site::query()->find($siteId);
        }
        if (! $site instanceof Site) {
            return [
                'primary_configured' => false,
                'primary_language' => null,
                'primary_language_label' => null,
                'domain_edit_url' => null,
            ];
        }

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
