<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\ContentProjectDraftAiCallHistoryService;

/**
 * Draft-level AI Calls (PromptResult history) for dedicated Draft AI History page.
 */
trait InteractsWithDraftAiCalls
{
    public string $draftAiCallFilterType = 'all';

    public string $draftAiCallFilterStatus = 'all';

    public int $draftAiCallPage = 1;

    public function clearDraftAiCallFilters(): void
    {
        $this->draftAiCallFilterType = 'all';
        $this->draftAiCallFilterStatus = 'all';
        $this->draftAiCallPage = 1;
    }

    public function updatedDraftAiCallFilterType(): void
    {
        $this->draftAiCallPage = 1;
    }

    public function updatedDraftAiCallFilterStatus(): void
    {
        $this->draftAiCallPage = 1;
    }

    public function loadMoreDraftAiCalls(): void
    {
        $this->draftAiCallPage++;
    }

    public function draftAiCallCount(): int
    {
        $project = $this->resolveDraftAiCallsProject();
        if (! $project instanceof SeoProject) {
            return 0;
        }

        return app(ContentProjectDraftAiCallHistoryService::class)->count($project);
    }

    /**
     * @return array{
     *   groups: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   has_more: bool,
     *   context: array{eyebrow: string, title: string, description: string, domain: string}
     * }
     */
    public function draftAiCallsPayload(): array
    {
        $project = $this->resolveDraftAiCallsProject();
        $empty = [
            'groups' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => ContentProjectDraftAiCallHistoryService::DEFAULT_PAGE_SIZE,
            'has_more' => false,
            'context' => $this->draftAiCallsContext($project),
        ];

        if (! $project instanceof SeoProject) {
            return $empty;
        }

        $listed = app(ContentProjectDraftAiCallHistoryService::class)->list($project, [
            'type' => $this->draftAiCallFilterType,
            'status' => $this->draftAiCallFilterStatus,
            'page' => 1,
            'per_page' => max(
                ContentProjectDraftAiCallHistoryService::DEFAULT_PAGE_SIZE,
                $this->draftAiCallPage * ContentProjectDraftAiCallHistoryService::DEFAULT_PAGE_SIZE,
            ),
        ]);

        return [
            'groups' => $listed['groups'],
            'total' => $listed['total'],
            'page' => $this->draftAiCallPage,
            'per_page' => $listed['per_page'],
            'has_more' => $listed['has_more'],
            'context' => $this->draftAiCallsContext($project),
        ];
    }

    /**
     * @return array{success: bool, title?: string, prompt?: string, output?: string, meta?: string, message?: string, prompt_result_id?: int, artifact_ref?: string}
     */
    public function loadDraftRawAiCallDetail(string $artifactRef): array
    {
        $project = $this->resolveDraftAiCallsProject();
        if (! $project instanceof SeoProject) {
            return [
                'success' => false,
                'message' => 'Draft not found.',
            ];
        }

        return app(ContentProjectDraftAiCallHistoryService::class)->rawDetail($project, trim($artifactRef));
    }

    protected function resolveDraftAiCallsProject(): ?SeoProject
    {
        if (property_exists($this, 'project') && $this->project instanceof SeoProject) {
            return $this->project;
        }

        if (method_exists($this, 'resolveNewContentProject')) {
            $resolved = $this->resolveNewContentProject();
            if ($resolved instanceof SeoProject) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array{eyebrow: string, title: string, description: string, domain: string}
     */
    protected function draftAiCallsContext(?SeoProject $project): array
    {
        $domain = '';
        $title = (string) __('seo-content-ai::filament.projects.content_planning_draft_label');
        if ($project instanceof SeoProject) {
            $name = trim((string) ($project->name ?? ''));
            if ($name !== '') {
                $title = $name;
            }
            $site = $project->relationLoaded('site') ? $project->site : $project->site()->first();
            $domain = trim((string) ($site?->domain ?? ''));
        }

        return [
            'eyebrow' => (string) __('seo-content-ai::filament.projects.draft_ai_calls_eyebrow'),
            'title' => $domain !== '' ? $title.' — '.$domain : $title,
            'description' => (string) __('seo-content-ai::filament.projects.draft_ai_calls_description'),
            'domain' => $domain,
        ];
    }
}
