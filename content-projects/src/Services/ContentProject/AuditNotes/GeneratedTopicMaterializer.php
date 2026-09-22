<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipReconcileService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;

/**
 * Final-commit materialization for PR3 generated Topic candidates.
 *
 * Temporary identity = candidate_key / cluster_ref generated:{key}.
 * Creates Topic + DNA membership + rewrites planning attributions to topic:{id}.
 * Must run inside the caller's omi_seo_ai transaction (e.g. SplitDraft).
 */
final class GeneratedTopicMaterializer
{
    public function __construct(
        private readonly ?DiscoverNewTopicsDuplicateFilter $duplicateFilter = null,
        private readonly ?KeywordPersistenceService $keywords = null,
        private readonly ?TopicDnaService $dna = null,
        private readonly ?TopicMembershipReconcileService $reconcile = null,
    ) {}

    /**
     * @param  list<int>  $taskIds
     * @return array{
     *   materialized: list<array{candidate_key: string, topic_id: int, topic_name: string, task_ids: list<int>}>,
     *   skipped: int,
     *   mapping: array<string, int>
     * }
     */
    public function materializeForTasks(int $siteId, array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($siteId <= 0 || $taskIds === [] || ! TopicReclusterService::tablesReady()) {
            return ['materialized' => [], 'skipped' => 0, 'mapping' => []];
        }

        $duplicateFilter = $this->duplicateFilter ?? app(DiscoverNewTopicsDuplicateFilter::class);
        $keywords = $this->keywords ?? app(KeywordPersistenceService::class);

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_task_planning_attributions')) {
            return ['materialized' => [], 'skipped' => 0, 'mapping' => []];
        }

        $attrs = SeoContentProjectTaskPlanningAttribution::query()
            ->where('site_id', $siteId)
            ->whereIn('project_task_id', $taskIds)
            ->where('cluster_ref', 'like', AuditNoteDnaNormalizer::GENERATED_REF_PREFIX.'%')
            ->get();

        if ($attrs->isEmpty()) {
            return ['materialized' => [], 'skipped' => count($taskIds), 'mapping' => []];
        }

        // Owner scope = site_id + draft/project ids of the seed tasks.
        // Never expand by candidate_key site-wide (collision across workspaces).
        $ownerProjectIds = SeoProjectTask::query()
            ->whereIn('id', $taskIds)
            ->pluck('project_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($ownerProjectIds === []) {
            return ['materialized' => [], 'skipped' => count($taskIds), 'mapping' => []];
        }

        $seedKeys = [];
        foreach ($attrs as $attr) {
            $ref = trim((string) ($attr->cluster_ref ?? ''));
            if (AuditNoteDnaNormalizer::isGeneratedRef($ref)) {
                $seedKeys[AuditNoteDnaNormalizer::generatedCandidateKey($ref)] = true;
            }
        }
        $expandedRefs = [];
        foreach (array_keys($seedKeys) as $key) {
            $expandedRefs[] = AuditNoteDnaNormalizer::generatedRef($key);
        }

        $ownerTaskIds = SeoProjectTask::query()
            ->whereIn('project_id', $ownerProjectIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $attrs = SeoContentProjectTaskPlanningAttribution::query()
            ->where('site_id', $siteId)
            ->whereIn('project_task_id', $ownerTaskIds)
            ->whereIn('cluster_ref', $expandedRefs)
            ->get();

        if ($attrs->isEmpty()) {
            return ['materialized' => [], 'skipped' => count($taskIds), 'mapping' => []];
        }

        /** @var array<string, array{candidate_key: string, name: string, dna: list<string>, task_ids: list<int>, planner_run_ids: list<int>}> $groups */
        $groups = [];
        foreach ($attrs as $attr) {
            $ref = trim((string) ($attr->cluster_ref ?? ''));
            if (! AuditNoteDnaNormalizer::isGeneratedRef($ref)) {
                continue;
            }
            $key = AuditNoteDnaNormalizer::generatedCandidateKey($ref);
            if ($key === '') {
                throw new InvalidArgumentException('Generated Topic attribution missing candidate_key.');
            }
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'candidate_key' => $key,
                    'name' => trim((string) ($attr->cluster_name_snapshot ?? '')),
                    'dna' => [],
                    'task_ids' => [],
                    'planner_run_ids' => [],
                ];
            }
            $name = trim((string) ($attr->cluster_name_snapshot ?? ''));
            if ($name !== '' && $groups[$key]['name'] === '') {
                $groups[$key]['name'] = $name;
            }
            $taskId = (int) $attr->project_task_id;
            if ($taskId > 0) {
                $groups[$key]['task_ids'][] = $taskId;
            }
            $runId = (int) ($attr->planner_run_id ?? 0);
            if ($runId > 0) {
                $groups[$key]['planner_run_ids'][] = $runId;
            }
            foreach ($this->normalizePhraseList($attr->dna_phrases ?? []) as $phrase) {
                $groups[$key]['dna'][] = $phrase;
            }
        }

        $this->enrichGroupsFromPlannerRuns($groups);
        $this->enrichGroupsFromTaskKeywords($siteId, $groups);

        $candidatesForDup = [];
        foreach ($groups as $group) {
            $name = AuditNoteDnaNormalizer::displayPhrase($group['name']);
            if ($name === '') {
                throw new InvalidArgumentException(
                    'Generated Topic "'.$group['candidate_key'].'" has empty name before materialize.',
                );
            }
            $candidatesForDup[] = [
                'candidate_key' => $group['candidate_key'],
                'name' => $name,
                'target_dna_count' => max(1, count($group['dna'])),
                'dna' => array_values(array_unique($group['dna'])),
            ];
            $groups[$group['candidate_key']]['name'] = $name;
            $groups[$group['candidate_key']]['dna'] = array_values(array_unique($group['dna']));
            $groups[$group['candidate_key']]['task_ids'] = array_values(array_unique($group['task_ids']));
        }

        $dup = $duplicateFilter->filter($siteId, $candidatesForDup);
        if ($dup['rejected'] !== []) {
            $first = $dup['rejected'][0]['candidate']['name'] ?? '';
            throw new InvalidArgumentException(
                'Generated Topic name conflicts with an existing Topic before commit'
                .($first !== '' ? ': '.$first : '.'),
            );
        }

        $dnaService = $this->dna ?? app(TopicDnaService::class);
        $materialized = [];
        $mapping = [];

        foreach ($groups as $group) {
            $topicId = $this->createExclusiveTopic($siteId, $group['name']);
            $keywordIds = $this->attachPhrasesAsMembers($siteId, $topicId, $group['dna'], $keywords);
            $dnaService->rebuildForTopic($siteId, $topicId, $group['name'], $keywordIds);

            $topicRef = TopicPlanningRef::encode($topicId);
            SeoContentProjectTaskPlanningAttribution::query()
                ->where('site_id', $siteId)
                ->whereIn('project_task_id', $group['task_ids'])
                ->where('cluster_ref', AuditNoteDnaNormalizer::generatedRef($group['candidate_key']))
                ->update([
                    'cluster_ref' => $topicRef,
                    'cluster_name_snapshot' => $group['name'],
                    'updated_at' => now(),
                ]);

            $mapping[$group['candidate_key']] = $topicId;
            $materialized[] = [
                'candidate_key' => $group['candidate_key'],
                'topic_id' => $topicId,
                'topic_name' => $group['name'],
                'task_ids' => $group['task_ids'],
            ];
        }

        $reconcile = $this->reconcile ?? app(TopicMembershipReconcileService::class);
        foreach ($mapping as $topicId) {
            $reconcile->reconcile($siteId, $topicId);
        }

        return [
            'materialized' => $materialized,
            'skipped' => max(0, count($taskIds) - count($attrs)),
            'mapping' => $mapping,
        ];
    }

    private function createExclusiveTopic(int $siteId, string $name): int
    {
        $existing = SeoTopic::query()
            ->where('site_id', $siteId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($existing instanceof SeoTopic) {
            throw new InvalidArgumentException(
                'Generated Topic name already exists and cannot be materialized: '.$name,
            );
        }

        $topic = SeoTopic::query()->create([
            'site_id' => $siteId,
            'name' => $name,
            'source' => TopicSource::MANUAL,
            'status' => TopicStatus::ACTIVE,
            'is_locked' => false,
        ]);

        return (int) $topic->id;
    }

    /**
     * @param  list<string>  $phrases
     * @return list<int>
     */
    private function attachPhrasesAsMembers(
        int $siteId,
        int $topicId,
        array $phrases,
        KeywordPersistenceService $keywords,
    ): array {
        $ids = [];
        foreach ($phrases as $phrase) {
            $keyword = $keywords->upsert(
                $phrase,
                Keyword::TYPE_FREE,
                $siteId,
            );
            if ($keyword === null) {
                continue;
            }
            $keywordId = (int) $keyword->id;
            if ($keywordId <= 0) {
                continue;
            }

            $existing = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('keyword_id', $keywordId)
                ->first();
            if ($existing instanceof SeoTopicKeyword && (int) $existing->topic_id !== $topicId) {
                // Do not steal membership from another Topic — skip phrase for DNA rebuild.
                continue;
            }

            SeoTopicKeyword::query()->updateOrCreate(
                ['site_id' => $siteId, 'keyword_id' => $keywordId],
                [
                    'topic_id' => $topicId,
                    'source' => TopicKeywordSource::MANUAL,
                    'is_seed' => false,
                    'is_locked' => true,
                    'confidence' => null,
                ],
            );
            $ids[] = $keywordId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, array{candidate_key: string, name: string, dna: list<string>, task_ids: list<int>, planner_run_ids: list<int>}>  $groups
     */
    private function enrichGroupsFromPlannerRuns(array &$groups): void
    {
        $runIds = [];
        foreach ($groups as $group) {
            foreach ($group['planner_run_ids'] as $runId) {
                $runIds[] = $runId;
            }
        }
        $runIds = array_values(array_unique(array_filter($runIds)));
        if ($runIds === []) {
            return;
        }

        $runs = SeoContentProjectPlannerRun::query()->whereIn('id', $runIds)->get();
        foreach ($runs as $run) {
            $snapshot = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
            $noteItems = is_array($snapshot['note_items'] ?? null) ? $snapshot['note_items'] : [];
            foreach (AuditNoteDnaNormalizer::normalizeNoteItems($noteItems) as $item) {
                if (! AuditNoteDnaNormalizer::isGenerated($item)) {
                    continue;
                }
                $key = (string) ($item['candidate_key'] ?? AuditNoteDnaNormalizer::generatedCandidateKey((string) $item['cluster_ref']));
                if ($key === '' || ! isset($groups[$key])) {
                    continue;
                }
                if ($groups[$key]['name'] === '') {
                    $groups[$key]['name'] = (string) ($item['cluster_name_snapshot'] ?? '');
                }
                foreach ($item['dna'] as $row) {
                    $phrase = AuditNoteDnaNormalizer::displayPhrase((string) ($row['phrase'] ?? ''));
                    if ($phrase !== '') {
                        $groups[$key]['dna'][] = $phrase;
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, array{candidate_key: string, name: string, dna: list<string>, task_ids: list<int>, planner_run_ids: list<int>}>  $groups
     */
    private function enrichGroupsFromTaskKeywords(int $siteId, array &$groups): void
    {
        unset($siteId);
        foreach ($groups as $key => $group) {
            if ($group['task_ids'] === []) {
                continue;
            }
            $phrases = SeoProjectTask::query()
                ->whereIn('id', $group['task_ids'])
                ->pluck('keyword')
                ->all();
            foreach ($phrases as $raw) {
                $phrase = AuditNoteDnaNormalizer::displayPhrase((string) $raw);
                if ($phrase !== '') {
                    $groups[$key]['dna'][] = $phrase;
                }
            }
        }
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizePhraseList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (is_string($row)) {
                $phrase = AuditNoteDnaNormalizer::displayPhrase($row);
            } elseif (is_array($row)) {
                $phrase = AuditNoteDnaNormalizer::displayPhrase((string) ($row['phrase'] ?? $row['value'] ?? ''));
            } else {
                continue;
            }
            if ($phrase !== '') {
                $out[] = $phrase;
            }
        }

        return $out;
    }
}
