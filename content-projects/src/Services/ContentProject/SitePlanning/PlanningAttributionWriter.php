<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningSignalResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;

/**
 * Persist immutable planning attribution for a task at create time.
 */
final class PlanningAttributionWriter
{
    public function __construct(
        private readonly McpPlanningSignalResolver $resolver = new McpPlanningSignalResolver,
    ) {}

    /**
     * @param  array{
     *   planning_month?: string|null,
     *   planner_run_id?: int|null,
     *   source_type?: string|null,
     *   source_keyword_id?: int|null,
     *   cluster_ref?: string|null,
     *   cluster_name_snapshot?: string|null,
     *   dna_phrases?: list<string>|null,
     *   allowed_cluster_refs?: list<string>|null,
     * }  $input
     */
    public function writeForTask(SeoProjectTask $task, ?SeoContentProjectItemOrigin $origin = null, array $input = []): ?SeoContentProjectTaskPlanningAttribution
    {
        if (! $this->tableReady()) {
            return null;
        }

        $taskId = (int) $task->getKey();
        $siteId = (int) ($task->site_id ?? 0);
        if ($taskId <= 0 || $siteId <= 0) {
            return null;
        }

        $planningMonth = ContentProjectMonthContext::normalize(
            $input['planning_month']
                ?? $task->planning_month
                ?? null,
        );

        $sourceType = trim((string) ($input['source_type'] ?? $origin?->source_type ?? ''));
        $sourceKeywordId = isset($input['source_keyword_id'])
            ? ((int) $input['source_keyword_id'] ?: null)
            : null;

        if ($sourceKeywordId === null && $origin instanceof SeoContentProjectItemOrigin) {
            $resolved = $this->resolver->resolve($task, $origin);
            $sourceKeywordId = $resolved['keyword_id'];
        }

        $clusterRef = trim((string) ($input['cluster_ref'] ?? ''));
        $clusterName = trim((string) ($input['cluster_name_snapshot'] ?? ''));
        $dnaPhrases = $this->normalizeDnaPhrases($input['dna_phrases'] ?? null);

        $allowed = [];
        foreach ($input['allowed_cluster_refs'] ?? [] as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '') {
                $allowed[$ref] = true;
            }
        }

        if ($clusterRef !== '' && $allowed !== [] && ! isset($allowed[$clusterRef])) {
            // Reject AI-invented cluster_ref outside note_items — never guess from title/keyword.
            $clusterRef = '';
            $clusterName = '';
            $dnaPhrases = [];
        }

        // Live Topic Core resolve preferred; legacy cluster snapshots are retired.

        // Single-cluster batch fallback when AI omitted cluster_ref (explicit, tested).
        if ($clusterRef === '' && count($allowed) === 1) {
            $clusterRef = (string) array_key_first($allowed);
        }

        if ($clusterName === '' && $clusterRef !== '') {
            $clusterName = $this->resolveClusterNameSnapshot($siteId, $clusterRef);
        }

        $status = $clusterRef !== ''
            ? SeoContentProjectTaskPlanningAttribution::STATUS_ATTRIBUTED
            : SeoContentProjectTaskPlanningAttribution::STATUS_UNATTRIBUTED;

        return SeoContentProjectTaskPlanningAttribution::query()->updateOrCreate(
            ['project_task_id' => $taskId],
            [
                'site_id' => $siteId,
                'planner_run_id' => isset($input['planner_run_id']) && (int) $input['planner_run_id'] > 0
                    ? (int) $input['planner_run_id']
                    : ($origin?->planner_run_id),
                'planning_month' => $planningMonth,
                'source_type' => $sourceType !== '' ? $sourceType : null,
                'source_keyword_id' => $sourceKeywordId,
                'cluster_ref' => $clusterRef !== '' ? $clusterRef : null,
                'cluster_name_snapshot' => $clusterName !== '' ? $clusterName : null,
                'dna_phrases' => $dnaPhrases !== [] ? $dnaPhrases : null,
                'attribution_status' => $status,
            ],
        );
    }

    /**
     * @param  list<mixed>|null  $raw
     * @return list<string>
     */
    public function normalizeDnaPhrases(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $phrase = '';
            if (is_string($item)) {
                $phrase = trim($item);
            } elseif (is_array($item)) {
                $phrase = trim((string) ($item['phrase'] ?? $item['value'] ?? ''));
            }
            $phrase = AuditNoteDnaNormalizer::normalizeSeedText($phrase) !== ''
                ? trim($phrase)
                : $phrase;
            $key = AuditNoteDnaNormalizer::normalizeKey($phrase);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $phrase;
        }

        return $out;
    }

    private function resolveClusterNameSnapshot(int $siteId, string $clusterRef): string
    {
        $topicId = TopicPlanningRef::decode($clusterRef);
        if ($topicId !== null && $siteId > 0 && TopicReclusterService::tablesReady()) {
            $name = SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('id', $topicId)
                ->value('name');
            $name = trim((string) ($name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        // Prefer human label from keyword phrase when cluster_ref equals a keyword-backed key.
        if (Schema::connection('omi_seo_ai')->hasTable('keywords')) {
            $decoded = Keyword::decodePhrase($clusterRef);
            if ($decoded !== '' && $decoded !== $clusterRef) {
                return $decoded;
            }
        }

        return $clusterRef;
    }

    private function tableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_content_project_task_planning_attributions');
    }
}
