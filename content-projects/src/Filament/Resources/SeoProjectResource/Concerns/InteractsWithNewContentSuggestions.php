<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
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

    public string $newContentDirection = NewContentSuggestionOptions::DIRECTION_AUTOMATIC;

    public string $newContentFocus = '';

    public string $newContentPostType = NewContentSuggestionOptions::POST_TYPE_ARTICLE;

    public string $newContentTaxonomy = '';

    public string $newContentLastResult = '';

    public ?int $newContentActiveRunId = null;

    public string $newContentActiveStatus = '';

    public ?int $newContentViewRunId = null;

    /** @var list<array<string, mixed>> */
    public array $newContentHistory = [];

    /** @var array<string, mixed>|null */
    public ?array $newContentViewResults = null;

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

        $snapshot = app(ContentProjectPlannerRunService::class)
            ->latestConfigurationSnapshot($project, SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT);
        if (is_array($snapshot)) {
            $this->applyNewContentOptions(NewContentSuggestionOptions::fromSnapshot($snapshot));
        }

        $this->refreshNewContentRunState();
        $this->reloadNewContentHistory();
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

        $primary = $this->newContentPrimaryLanguagePayload($project);
        if (! (bool) ($primary['primary_configured'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_primary_language_missing'))
                ->body(__('seo-content-ai::filament.projects.planner_primary_language_missing_help'))
                ->warning()
                ->send();

            return;
        }

        if ($this->newContentActiveRunId !== null && $this->newContentActiveRunId > 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_busy'))
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
        $this->reloadNewContentHistory();

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
            $this->reloadNewContentHistory();
            if (method_exists($this, 'dispatch')) {
                $this->dispatch('cp-ops-refresh');
            }
            if ($this->newContentLastResult !== '') {
                Notification::make()
                    ->title($after === 'failed'
                        ? __('seo-content-ai::filament.projects.planner_generate_failed')
                        : __('seo-content-ai::filament.projects.planner_generate_done'))
                    ->body($this->newContentLastResult)
                    ->{$after === 'failed' ? 'danger' : 'success'}()
                    ->send();
            }
        }
    }

    public function saveNewContentOptions(): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->notifyNewContentProjectRequired();

            return;
        }

        $primary = $this->newContentPrimaryLanguagePayload($project);
        $language = (string) ($primary['primary_language'] ?? '');
        app(ContentProjectPlannerRunService::class)->recordSavedConfig(
            $project,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
            NewContentSuggestionOptions::snapshot($this->buildNewContentOptions(), $language !== '' ? $language : 'und'),
            auth()->id() !== null ? (int) auth()->id() : null,
        );

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.planner_options_saved'))
            ->success()
            ->send();
        $this->reloadNewContentHistory();
    }

    public function loadNewContentHistory(int $runId): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->notifyNewContentProjectRequired();

            return;
        }

        $run = app(ContentProjectPlannerRunService::class)->findForProject($project, $runId);
        if ($run === null || ! is_array($run->configuration_snapshot)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_history_missing'))
                ->warning()
                ->send();

            return;
        }

        $this->applyNewContentOptions(NewContentSuggestionOptions::fromSnapshot($run->configuration_snapshot));
        if ((int) ($run->requested_quantity ?? 0) > 0) {
            $this->newContentQuantity = (int) $run->requested_quantity;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.planner_options_loaded'))
            ->success()
            ->send();
    }

    public function runNewContentHistory(int $runId): void
    {
        $this->loadNewContentHistory($runId);
        $this->generateNewContentSuggestions();
    }

    public function viewNewContentHistoryResults(int $runId): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->notifyNewContentProjectRequired();

            return;
        }

        $run = app(ContentProjectPlannerRunService::class)->findForProject($project, $runId);
        if ($run === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_history_missing'))
                ->warning()
                ->send();

            return;
        }

        $summary = is_array($run->result_summary) ? $run->result_summary : [];
        $this->newContentViewRunId = (int) $run->getKey();
        $this->newContentViewResults = [
            'run_id' => (int) $run->getKey(),
            'requested' => (int) ($run->requested_quantity ?? ($summary['requested'] ?? 0)),
            'status' => (string) ($summary['status'] ?? ''),
            'message' => (string) ($summary['message'] ?? ''),
            'candidates' => is_array($summary['candidates'] ?? null) ? $summary['candidates'] : [],
            'configuration' => is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [],
        ];
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
            return [
                'can_write' => false,
                'has_project' => false,
                'is_draft' => false,
                'primary_configured' => false,
                'primary_language_label' => null,
                'domain_edit_url' => null,
                'is_generating' => false,
            ];
        }

        $primary = $this->newContentPrimaryLanguagePayload($project);
        $generating = $this->newContentActiveRunId !== null
            && in_array($this->newContentActiveStatus, ['queued', 'running'], true);

        return [
            'can_write' => $project->isDraftPlanning() && (bool) $primary['primary_configured'],
            'has_project' => true,
            'is_draft' => $project->isDraftPlanning(),
            'primary_configured' => (bool) $primary['primary_configured'],
            'primary_language_label' => $primary['primary_language_label'],
            'domain_edit_url' => $primary['domain_edit_url'],
            'is_generating' => $generating,
            'active_run_id' => $this->newContentActiveRunId,
            'active_status' => $this->newContentActiveStatus,
            'last_result' => $this->newContentLastResult,
        ];
    }

    /**
     * @return NewContentSuggestionOptions::normalize array
     */
    protected function buildNewContentOptions(): array
    {
        return NewContentSuggestionOptions::normalize([
            'quantity' => (int) $this->newContentQuantity,
            'direction' => $this->newContentDirection,
            'focus' => $this->newContentFocus,
            'post_type' => $this->newContentPostType,
            'taxonomy' => $this->newContentTaxonomy,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function applyNewContentOptions(array $options): void
    {
        $normalized = NewContentSuggestionOptions::normalize($options);
        $this->newContentQuantity = $normalized['quantity'];
        $this->newContentDirection = $normalized['direction'];
        $this->newContentFocus = $normalized['focus'];
        $this->newContentPostType = $normalized['post_type'];
        $this->newContentTaxonomy = $normalized['taxonomy'];
    }

    protected function refreshNewContentRunState(): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->newContentActiveRunId = null;
            $this->newContentActiveStatus = '';

            return;
        }

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

    protected function reloadNewContentHistory(): void
    {
        $project = $this->resolveNewContentProject();
        if (! $project instanceof SeoProject) {
            $this->newContentHistory = [];

            return;
        }

        $rows = [];
        foreach (app(ContentProjectPlannerRunService::class)->listExecuted(
            $project,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
            20,
        ) as $run) {
            $summary = is_array($run->result_summary) ? $run->result_summary : [];
            $config = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
            $planning = is_array($summary['planning_context'] ?? null) ? $summary['planning_context'] : [];
            $contextSummary = '';
            if ($planning !== []) {
                $contextSummary = sprintf(
                    'KI: %d · Clusters: %d · MCP: %s',
                    (int) ($planning['principal_keywords_count'] ?? 0),
                    (int) ($planning['cluster_count'] ?? 0),
                    (string) ($planning['mcp_period'] ?? '—'),
                );
            }
            $rows[] = [
                'id' => (int) $run->getKey(),
                'created_at' => $run->created_at?->format('d M H:i') ?? '',
                'requested' => (int) ($run->requested_quantity ?? ($summary['requested'] ?? 0)),
                'added' => (int) ($summary['added'] ?? 0),
                'status' => (string) ($summary['status'] ?? ''),
                'direction' => (string) ($config['direction'] ?? 'automatic'),
                'focus' => (string) ($config['focus'] ?? ''),
                'primary_language' => (string) ($config['primary_language'] ?? ''),
                'message' => (string) ($summary['message'] ?? ''),
                'context_summary' => $contextSummary,
            ];
        }
        $this->newContentHistory = $rows;
    }

    /**
     * @return array{primary_configured: bool, primary_language: ?string, primary_language_label: ?string, domain_edit_url: ?string}
     */
    protected function newContentPrimaryLanguagePayload(SeoProject $project): array
    {
        $siteId = (int) ($project->site_id ?? 0);
        $site = $siteId > 0 ? Site::query()->find($siteId) : null;
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
