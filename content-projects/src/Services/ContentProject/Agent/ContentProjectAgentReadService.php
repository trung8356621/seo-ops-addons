<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectOperation;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectReadModelService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectDailyReportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectSiteHealthService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Carbon\Carbon;
use RuntimeException;

/**
 * Agent read surface — strip numeric IDs, no SeoProjectRun exposure.
 */
final class ContentProjectAgentReadService
{
    public function __construct(
        private readonly ContentProjectReadModelService $reads,
        private readonly ContentProjectCapabilityRegistry $registry,
        private readonly ContentProjectLifecycle $lifecycle,
        private readonly ContentProjectDailyReportService $dailyReport,
        private readonly ContentProjectSiteHealthService $siteHealth,
        private readonly ContentProjectAgentPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listProjects(AgentExecutionContext $context, array $input = []): array
    {
        $actor = $context->toActorContext();
        $siteId = (int) ($context->resolvedSiteId ?? 0);
        $query = SeoProject::query()->with('user')->orderByDesc('id')->limit(100);
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $rows = [];
        foreach ($query->get() as $project) {
            try {
                $row = $this->serializeProject($this->reads->project($project, $actor), $context);
                $row['project_id'] = (int) $project->getKey();
                $memberName = trim((string) ($project->user?->name ?? ''));
                if ($memberName !== '') {
                    $row['member_name'] = $memberName;
                }
                $rows[] = $row;
            } catch (RuntimeException) {
                continue;
            }
        }

        return ['projects' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProject(AgentExecutionContext $context, array $input): array
    {
        $project = $this->findProject($input, $context);
        $project->loadMissing('user');
        $row = $this->serializeProject(
            $this->reads->project($project, $context->toActorContext()),
            $context,
        );
        $row['project_id'] = (int) $project->getKey();
        $memberName = trim((string) ($project->user?->name ?? ''));
        if ($memberName !== '') {
            $row['member_name'] = $memberName;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function listItems(AgentExecutionContext $context, array $input): array
    {
        $project = $this->findProject($input, $context);
        $items = array_map(
            fn ($dto) => $dto->toArray(),
            $this->reads->items($project, $context->toActorContext()),
        );

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function getItem(AgentExecutionContext $context, array $input): array
    {
        $itemRef = trim((string) ($input['item_ref'] ?? ''));
        $itemId = ContentProjectPublicRef::resolveItemIdStrict($itemRef);

        $task = SeoProjectTask::query()->find($itemId);
        if (! $task instanceof SeoProjectTask) {
            throw new RuntimeException('Item not found.');
        }

        $project = SeoProject::query()->find((int) $task->project_id);
        if (! $project instanceof SeoProject) {
            throw new RuntimeException('Project not found.');
        }

        foreach ($this->reads->items($project, $context->toActorContext()) as $dto) {
            if ($dto->itemRef === $itemRef) {
                return $dto->toArray();
            }
        }

        throw new RuntimeException('Item not found.');
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(AgentExecutionContext $context, array $input): array
    {
        $project = $this->findProject($input, $context);
        $project->loadMissing('user');
        $items = $this->reads->items($project, $context->toActorContext());

        $phases = [];
        foreach ($items as $item) {
            $phases[$item->lifecycle] = ($phases[$item->lifecycle] ?? 0) + 1;
        }

        $dominantPhase = $this->dominantPhase($phases, $project);
        $allowed = $this->allowedCapabilities($dominantPhase, $context->scopes);
        $blocked = $this->blockedCapabilities($dominantPhase, $context->scopes);
        $blockers = $this->blockers($project, $items);
        $nextActions = $this->recommendedNextActions($dominantPhase, $blockers);

        return [
            'project_id' => (int) $project->getKey(),
            'name' => (string) ($project->name ?? ''),
            'month' => $project->month instanceof \DateTimeInterface
                ? $project->month->format('Y-m-d')
                : (is_string($project->month ?? null) ? (string) $project->month : null),
            'member_name' => trim((string) ($project->user?->name ?? '')),
            'archived' => $project->archived_at !== null,
            'project_ref' => ContentProjectPublicRef::project((int) $project->getKey()),
            'phase' => $dominantPhase,
            'phase_counts' => $phases,
            'allowed_capabilities' => $allowed,
            'blocked_capabilities' => $blocked,
            'blockers' => $blockers,
            'recommended_next_actions' => $nextActions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublishingQueue(AgentExecutionContext $context, array $input): array
    {
        $project = $this->findProject($input, $context);
        $rows = array_map(
            static fn ($dto) => $dto->toArray(),
            $this->reads->publishingQueue($project, $context->toActorContext()),
        );

        return ['queue' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTimeline(AgentExecutionContext $context, array $input): array
    {
        $project = $this->findProject($input, $context);
        $rows = array_map(
            static fn ($dto) => $dto->toArray(),
            $this->reads->timeline($project, $context->toActorContext()),
        );

        return ['timeline' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDailyReport(AgentExecutionContext $context, array $input): array
    {
        $dateRaw = (string) ($input['date'] ?? now()->toDateString());
        $siteId = (int) ($context->resolvedSiteId ?? 0);
        $siteIds = $siteId > 0 ? [$siteId] : null;

        $report = $this->dailyReport->buildForDate(Carbon::parse($dateRaw), $siteIds);
        if ($siteId > 0) {
            $report['site_ref'] = ContentProjectPublicRef::site($siteId);
            unset($report['site_id']);
        }

        return $report;
    }

    /**
     * Compact Planning Intelligence summary (0 AI calls, no writes).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getPlanningIntelligence(AgentExecutionContext $context, array $input): array
    {
        $project = $this->findProject($input, $context);
        $siteId = (int) ($project->site_id ?? $context->resolvedSiteId ?? 0);
        $site = $siteId > 0 ? \App\Models\Site::query()->find($siteId) : null;
        if (! $site instanceof \App\Models\Site) {
            throw new RuntimeException('Project domain is required.');
        }

        $language = '';
        if (app()->bound(\Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService::class)) {
            $resolved = app(\Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService::class)
                ->resolvePrimaryLanguage($site);
            $language = is_string($resolved) ? trim($resolved) : '';
        }

        $summary = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService::class)
            ->summarize($project, $site, $language);

        return [
            'project_ref' => ContentProjectPublicRef::project((int) $project->getKey()),
            'principal_keyword_count' => (int) ($summary['principal_keyword_count'] ?? 0),
            'cluster_count' => (int) ($summary['cluster_count'] ?? 0),
            'missing_direction_count' => (int) ($summary['missing_direction_count'] ?? 0),
            'mcp_period' => $summary['mcp_period'] ?? null,
            'coverage' => is_array($summary['coverage'] ?? null) ? $summary['coverage'] : [],
            'covered_clusters' => array_slice(array_map(
                static fn (array $c): array => [
                    'label' => (string) ($c['label'] ?? ''),
                    'coverage' => (string) ($c['coverage'] ?? ''),
                    'article_count' => (int) ($c['article_count'] ?? 0),
                ],
                is_array($summary['covered_clusters'] ?? null) ? $summary['covered_clusters'] : [],
            ), 0, 15),
            'weak_clusters' => array_slice(array_map(
                static fn (array $c): array => [
                    'label' => (string) ($c['label'] ?? ''),
                    'coverage' => (string) ($c['coverage'] ?? ''),
                    'article_count' => (int) ($c['article_count'] ?? 0),
                ],
                is_array($summary['weak_clusters'] ?? null) ? $summary['weak_clusters'] : [],
            ), 0, 15),
            'missing_directions' => array_slice(
                is_array($summary['missing_directions'] ?? null) ? $summary['missing_directions'] : [],
                0,
                20,
            ),
            'gsc_signal_count' => (int) ($summary['gsc_signal_count'] ?? 0),
            'gsc_signals' => array_slice(
                is_array($summary['gsc_signals'] ?? null) ? $summary['gsc_signals'] : [],
                0,
                20,
            ),
            'improvement_signals' => array_slice(
                is_array($summary['improvement_signals'] ?? null) ? $summary['improvement_signals'] : [],
                0,
                15,
            ),
            'new_content_gsc_signals' => array_slice(
                is_array($summary['new_content_gsc_signals'] ?? null) ? $summary['new_content_gsc_signals'] : [],
                0,
                15,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSiteHealth(AgentExecutionContext $context, array $input = []): array
    {
        $siteId = (int) ($context->resolvedSiteId ?? 0);
        $siteRef = trim((string) ($input['site_ref'] ?? $context->siteRef ?? ''));

        if ($siteId <= 0 && $siteRef === '') {
            throw new RuntimeException('Thiếu site_ref — chọn site trước khi chạy /site-health.');
        }

        if ($siteId <= 0) {
            throw new RuntimeException('site_ref không resolve được site hiện tại: '.$siteRef);
        }

        $snapshots = $this->siteHealth->snapshot([$siteId]);
        $sites = [];
        foreach ($snapshots as $row) {
            unset($row['site_id']);
            $row['site_ref'] = ContentProjectPublicRef::site($siteId);
            $sites[] = $row;
        }

        if ($sites === []) {
            return [
                'sites' => [],
                'error' => 'Không lấy được snapshot health cho site hiện tại (site_id='.$siteId.').',
                'site_ref' => ContentProjectPublicRef::site($siteId),
            ];
        }

        return ['sites' => $sites];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOperation(AgentExecutionContext $context, array $input): array
    {
        $operationRef = trim((string) ($input['operation_ref'] ?? ''));
        if ($operationRef === '') {
            throw new RuntimeException('operation_ref is required.');
        }

        $operation = ContentProjectOperation::query()
            ->where('operation_id', $operationRef)
            ->first();

        if (! $operation instanceof ContentProjectOperation) {
            throw new RuntimeException('Operation not found.');
        }

        return [
            'operation_ref' => (string) $operation->operation_id,
            'status' => $this->mapOperationStatus($operation),
            'command' => (string) ($operation->command ?? ''),
            'result_code' => (string) ($operation->result_code ?? ''),
            'success' => (bool) ($operation->success ?? false),
            'project_ref' => $operation->project_ref,
            'item_ref' => $operation->item_ref,
            'tenant_ref' => $operation->tenant_ref,
            'started_at' => $operation->started_at?->toIso8601String(),
            'finished_at' => $operation->finished_at?->toIso8601String(),
            'duration_ms' => (int) ($operation->duration_ms ?? 0),
            'recommended_next_actions' => [
                ['capability' => 'content_project.get_status', 'reason' => 'Refresh project status.'],
            ],
        ];
    }

    private function mapOperationStatus(ContentProjectOperation $operation): string
    {
        $status = strtolower((string) ($operation->status ?? 'finished'));
        if (in_array($status, ['accepted', 'processing', 'running'], true)) {
            return $status === 'running' ? 'processing' : $status;
        }

        if (! (bool) $operation->success) {
            return 'failed';
        }

        return 'completed';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function findProject(array $input, AgentExecutionContext $context): SeoProject
    {
        $projectRef = trim((string) ($input['project_ref'] ?? ''));
        $projectId = ContentProjectPublicRef::resolveProjectIdStrict($projectRef);

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            throw new RuntimeException('Project not found.');
        }

        $siteId = (int) ($context->resolvedSiteId ?? 0);
        if ($siteId > 0 && (int) ($project->site_id ?? 0) !== $siteId) {
            throw new RuntimeException('Project does not belong to site context.');
        }

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProject(object $dto, AgentExecutionContext $context): array
    {
        $data = $dto->toArray();
        unset($data['site_id'], $data['site_ref']);

        $projectRef = trim((string) ($data['project_ref'] ?? ''));
        if ($projectRef !== '' && ! isset($data['project_id'])) {
            try {
                $data['project_id'] = ContentProjectPublicRef::decodeProject($projectRef);
            } catch (\Throwable) {
                // keep without numeric id
            }
        }

        // $context retained for call-site compatibility; site_ref must not leak to chat DTO.
        unset($context);

        return $data;
    }

    /**
     * @param  array<string, int>  $phaseCounts
     */
    private function dominantPhase(array $phaseCounts, SeoProject $project): string
    {
        if ($project->archived_at !== null) {
            return ContentProjectLifecyclePhase::Archived->value;
        }

        if ($phaseCounts === []) {
            return ContentProjectLifecyclePhase::Draft->value;
        }

        arsort($phaseCounts);

        return (string) array_key_first($phaseCounts);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function allowedCapabilities(string $phase, array $scopes): array
    {
        $allowed = [];
        foreach ($this->registry->all() as $cap) {
            $name = (string) ($cap['name'] ?? '');
            if ($name === '' || ! $this->registry->isAgentWriteExposed($name)) {
                continue;
            }

            $phases = $cap['allowed_lifecycle_phases'] ?? null;
            if (is_array($phases) && ! in_array($phase, $phases, true)) {
                continue;
            }

            $policyFail = $this->policy->assertScopes($scopes, $name);
            if ($policyFail !== null) {
                continue;
            }

            $allowed[] = $name;
        }

        sort($allowed);

        return $allowed;
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function blockedCapabilities(string $phase, array $scopes): array
    {
        $blocked = [];
        foreach ($this->registry->all() as $cap) {
            $name = (string) ($cap['name'] ?? '');
            if ($name === '' || ! $this->registry->isAgentWriteExposed($name)) {
                continue;
            }

            $phases = $cap['allowed_lifecycle_phases'] ?? null;
            if (is_array($phases) && ! in_array($phase, $phases, true)) {
                $blocked[] = $name;
            }
        }

        sort($blocked);

        return $blocked;
    }

    /**
     * @param  list<object>  $items
     * @return list<string>
     */
    private function blockers(SeoProject $project, array $items): array
    {
        $blockers = [];
        if ($project->archived_at !== null) {
            $blockers[] = 'project_archived';
        }

        foreach ($items as $item) {
            if (($item->publishQueueStatus ?? '') === 'processing') {
                $blockers[] = 'publishing_processing';
                break;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  list<string>  $blockers
     * @return list<array{capability: string, reason: string}>
     */
    private function recommendedNextActions(string $phase, array $blockers): array
    {
        if ($blockers !== []) {
            return [];
        }

        return match ($phase) {
            ContentProjectLifecyclePhase::Draft->value => [
                ['capability' => 'content_project.generate', 'reason' => 'Start AI generation for draft items.'],
            ],
            ContentProjectLifecyclePhase::Review->value => [
                ['capability' => 'content_project.approve', 'reason' => 'Approve reviewed items for scheduling.'],
            ],
            ContentProjectLifecyclePhase::Approved->value => [
                ['capability' => 'content_project.schedule', 'reason' => 'Schedule approved items for publishing.'],
            ],
            ContentProjectLifecyclePhase::WaitingPublish->value => [
                ['capability' => 'content_project.get_publishing_queue', 'reason' => 'Monitor publishing queue.'],
            ],
            ContentProjectLifecyclePhase::Failed->value => [
                ['capability' => 'content_project.rerun', 'reason' => 'Rerun failed generation.'],
            ],
            default => [],
        };
    }
}
