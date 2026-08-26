<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionDedupFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Dedicated planner run results detail — opens in its own tab.
 */
final class ContentProjectPlannerRunDetail extends SeoPanelPage
{
    protected static ?string $slug = 'content-projects/planner-runs';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-planner-run-detail';

    #[Url(as: 'project')]
    public ?int $projectId = null;

    #[Url(as: 'run')]
    public ?int $runId = null;

    public ?SeoProject $project = null;

    public ?SeoContentProjectPlannerRun $run = null;

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageContentProjectWorkflow();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $this->resolveOrAbort();
    }

    public function getTitle(): string|Htmlable
    {
        $when = $this->run?->created_at?->format('d M H:i') ?? '';

        return (string) __('seo-content-ai::filament.projects.planner_run_detail_title', [
            'when' => $when !== '' ? $when : '#'.(int) ($this->runId ?? 0),
        ]);
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::SevenExtraLarge;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        $projectParams = $this->projectId !== null && $this->projectId > 0
            ? ['project' => $this->projectId]
            : [];

        return [
            SeoProjectResource::getUrl() => SeoProjectResource::getNavigationLabel(),
            ContentProjectSeoAuditPlanner::getUrl($projectParams) => (string) __('seo-content-ai::filament.projects.content_planning_title'),
            static::getUrl(array_filter([
                'project' => $this->projectId,
                'run' => $this->runId,
            ])) => (string) __('seo-content-ai::filament.projects.planner_run_detail_nav'),
        ];
    }

    public static function urlFor(SeoProject|int $project, int $runId): string
    {
        $id = $project instanceof SeoProject ? (int) $project->getKey() : (int) $project;

        return static::getUrl(['project' => $id, 'run' => $runId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(): array
    {
        $run = $this->run;
        if (! $run instanceof SeoContentProjectPlannerRun) {
            return [];
        }

        $summary = is_array($run->result_summary) ? $run->result_summary : [];
        $config = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
        $candidates = is_array($summary['candidates'] ?? null) ? $summary['candidates'] : [];
        $breakdown = is_array($summary['duplicate_breakdown'] ?? null) ? $summary['duplicate_breakdown'] : null;
        $contentType = NewContentSuggestionOptions::normalizeContentType((string) (
            $config['content_type'] ?? $config['post_type'] ?? NewContentSuggestionOptions::CONTENT_TYPE_POST
        ));

        return [
            'run_id' => (int) $run->getKey(),
            'created_at' => $run->created_at?->format('d M H:i') ?? '',
            'source' => (string) ($run->source_type ?? ''),
            'requested' => (int) ($run->requested_quantity ?? ($summary['requested'] ?? 0)),
            'added' => (int) ($summary['added'] ?? 0),
            'duplicates' => (int) ($summary['duplicate_skipped'] ?? 0),
            'rejected' => (int) ($summary['rejected_skipped'] ?? 0),
            'invalid' => (int) ($summary['invalid'] ?? 0),
            'status' => (string) ($summary['status'] ?? ''),
            'message' => (string) ($summary['message'] ?? ''),
            'content_type' => $contentType,
            'language' => (string) ($config['primary_language'] ?? $summary['primary_language'] ?? ''),
            'notes' => (string) ($config['notes'] ?? (
                trim((string) ($config['focus'] ?? '')) !== '' ? (string) $config['focus'] : ''
            )),
            'duplicate_breakdown' => $breakdown,
            'candidates' => array_map(
                fn (array $row): array => $this->decorateCandidate($row),
                $candidates,
            ),
        ];
    }

    public function restoreFingerprint(string $fingerprint): void
    {
        abort_unless($this->project instanceof SeoProject, 404);
        $fp = trim($fingerprint);
        if ($fp === '') {
            return;
        }

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new RestoreNewContentSuggestionsCommand((int) $this->project->getKey(), [$fp]),
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($this->project->site_id ?? 0) ?: null,
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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decorateCandidate(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $row['decision_label'] = match ($status) {
            NewContentSuggestionDedupFilter::STATUS_ADDED => (string) __('seo-content-ai::filament.projects.planner_decision_added'),
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_IN_BATCH => (string) __('seo-content-ai::filament.projects.planner_decision_duplicate_in_batch'),
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_IN_BATCH_KEYWORD => (string) __('seo-content-ai::filament.projects.planner_decision_duplicate_in_batch_keyword'),
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_DRAFT => (string) __('seo-content-ai::filament.projects.planner_decision_duplicate_draft'),
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_COVERED_CONTENT,
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_SKIPPED => (string) __('seo-content-ai::filament.projects.planner_decision_duplicate_covered'),
            NewContentSuggestionDedupFilter::STATUS_PROJECT_REJECTED,
            NewContentSuggestionDedupFilter::STATUS_REJECTED_SKIPPED => (string) __('seo-content-ai::filament.projects.planner_decision_rejected'),
            default => $status !== '' ? $status : '—',
        };
        $row['can_restore'] = in_array($status, [
            NewContentSuggestionDedupFilter::STATUS_PROJECT_REJECTED,
            NewContentSuggestionDedupFilter::STATUS_REJECTED_SKIPPED,
        ], true) && trim((string) ($row['fingerprint'] ?? '')) !== '';

        return $row;
    }

    private function resolveOrAbort(): void
    {
        $projectId = (int) ($this->projectId ?? 0);
        $runId = (int) ($this->runId ?? 0);
        abort_if($projectId <= 0 || $runId <= 0, 404);

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->with('site:id,domain')
            ->find($projectId);

        abort_unless($project instanceof SeoProject, 404);
        abort_unless(SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0)), 403);
        abort_unless(SeoAccessControl::canAccessContentProjectRun($project), 403);

        $run = app(ContentProjectPlannerRunService::class)->findForProject($project, $runId);
        abort_unless($run instanceof SeoContentProjectPlannerRun, 404);

        $this->project = $project;
        $this->run = $run;
        $this->projectId = (int) $project->getKey();
        $this->runId = (int) $run->getKey();
    }
}
