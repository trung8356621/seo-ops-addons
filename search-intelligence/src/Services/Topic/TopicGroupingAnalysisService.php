<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Contracts\GlobalMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Services\MatchRules\IndustryMatchRuntime;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\Contracts\TopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputFactory;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;

/**
 * Analyze-only Topic grouping orchestration.
 *
 * Builds the same site_recluster input as recluster, calls TopicGroupingProvider,
 * persists a proposal run, and STOPS. Never mutates Topics / memberships / locks / DNA.
 */
final class TopicGroupingAnalysisService
{
    public function __construct(
        private readonly TopicSiteKeywordService $siteKeywords,
        private readonly TopicSeedResolver $seeds,
        private readonly TopicGroupingProvider $grouping,
        private readonly TopicGroupingInputHasher $hasher,
        private readonly ?IndustryMatchRuntime $industryRules = null,
        private readonly ?GlobalMatchRuleProvider $globalRules = null,
    ) {}

    public static function runsTableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_topic_grouping_runs');
    }

    public function analyzeSite(int $siteId): SeoTopicGroupingRun
    {
        if ($siteId <= 0) {
            return $this->failedStub($siteId, 'site_required', 'site_id required');
        }
        if (! TopicReclusterService::tablesReady()) {
            return $this->failedStub($siteId, 'topic_tables_missing', 'Topic Core tables missing');
        }
        if (! self::runsTableReady()) {
            return $this->failedStub($siteId, 'grouping_runs_table_missing', 'seo_topic_grouping_runs missing');
        }

        $provider = TopicGroupingProviderMode::providerKey();
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $siteId,
            'provider' => $provider,
            'input_hash' => '',
            'status' => TopicGroupingRunStatus::QUEUED,
            'started_at' => now(),
        ]);

        TopicReclusterUiState::markAnalyzing($siteId, $provider, (int) $run->id);

        try {
            $this->siteKeywords->ensureForSite($siteId);
            $seedRows = $this->seeds->resolve($siteId);
            $eligible = $this->siteKeywords->loadTopicCandidateKeywords($siteId);
            $locked = $this->loadLockedTopicsAndKeywords($siteId);
            $manualInventory = $this->loadManualInventoryTopics($siteId, $locked['locked_topic_ids']);

            $input = TopicGroupingInputFactory::siteRecluster(
                $siteId,
                $seedRows,
                $eligible,
                $locked['topics'],
                $locked['locked_keyword_ids'],
                $manualInventory,
                $this->industryRules?->rulesForSite($siteId) ?? [],
                $this->globalRules?->globalMatchRules() ?? [],
            );

            $inputHash = $this->hasher->hashFromGroupingInput($input);
            $run->input_hash = $inputHash;
            $run->keyword_count = count($input->candidates);
            $run->status = TopicGroupingRunStatus::ANALYZING;
            $run->save();

            $proposal = $this->grouping->analyze($input);
            $this->persistProposalReady($run, $proposal, $inputHash);

            TopicReclusterUiState::markProposalReady($siteId, $run->fresh() ?? $run, $provider);

            return $run->fresh() ?? $run;
        } catch (SemanticHttpException $e) {
            return $this->markFailed($run, $e->errorCode, $e->getMessage(), $provider);
        } catch (\Throwable $e) {
            return $this->markFailed($run, 'analysis_failed', $e->getMessage(), $provider);
        }
    }

    /**
     * Compare a stored proposal hash against the current site input hash.
     * Does not apply. Used by Prompt 5 / UI freshness checks.
     */
    public function currentInputHash(int $siteId): string
    {
        $seedRows = $this->seeds->resolve($siteId);
        $eligible = $this->siteKeywords->loadTopicCandidateKeywords($siteId);
        $locked = $this->loadLockedTopicsAndKeywords($siteId);
        $manualInventory = $this->loadManualInventoryTopics($siteId, $locked['locked_topic_ids']);
        $input = TopicGroupingInputFactory::siteRecluster(
            $siteId,
            $seedRows,
            $eligible,
            $locked['topics'],
            $locked['locked_keyword_ids'],
            $manualInventory,
            $this->industryRules?->rulesForSite($siteId) ?? [],
            $this->globalRules?->globalMatchRules() ?? [],
        );

        return $this->hasher->hashFromGroupingInput($input);
    }

    public function markStaleIfHashChanged(SeoTopicGroupingRun $run): SeoTopicGroupingRun
    {
        if (! $run->isProposalReady() || $run->input_hash === '') {
            return $run;
        }
        $current = $this->currentInputHash((int) $run->site_id);
        if (! hash_equals($run->input_hash, $current)) {
            $run->status = TopicGroupingRunStatus::STALE;
            $run->save();
        }

        return $run->fresh() ?? $run;
    }

    public function latestForSite(int $siteId): ?SeoTopicGroupingRun
    {
        if ($siteId <= 0 || ! self::runsTableReady()) {
            return null;
        }

        /** @var SeoTopicGroupingRun|null $run */
        $run = SeoTopicGroupingRun::query()
            ->where('site_id', $siteId)
            ->orderByDesc('id')
            ->first();

        return $run;
    }

    private function persistProposalReady(
        SeoTopicGroupingRun $run,
        TopicGroupingProposal $proposal,
        string $inputHash,
    ): void {
        $meta = $proposal->metadata;
        $model = is_array($meta['model'] ?? null) ? $meta['model'] : [];
        $diagnostics = is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : [];

        $payload = [
            'analysis_ref' => $proposal->analysisRef,
            'groups' => array_map(static function ($group): array {
                return [
                    'group_key' => $group->groupKey,
                    'suggested_label' => $group->suggestedLabel,
                    'existing_topic_ref' => $group->existingTopicRef,
                    'metadata' => $group->metadata,
                    'members' => array_map(static function ($member): array {
                        return [
                            'keyword_ref' => $member->keywordRef,
                            'text' => $member->text,
                            'confidence' => $member->confidence,
                            'evidence' => $member->evidence,
                        ];
                    }, $group->members),
                ];
            }, $proposal->groups),
            'unassigned' => array_map(static function ($candidate): array {
                return [
                    'keyword_ref' => $candidate->keywordRef,
                    'text' => $candidate->text,
                ];
            }, $proposal->unassigned),
            'metadata' => $meta,
        ];

        $run->fill([
            'external_analysis_id' => $proposal->analysisRef
                ?? (string) ($meta['external_analysis_id'] ?? null),
            'input_hash' => (string) ($meta['input_hash'] ?? $inputHash),
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'keyword_count' => (int) ($diagnostics['keyword_count'] ?? $run->keyword_count),
            'group_count' => count($proposal->groups),
            'unassigned_count' => count($proposal->unassigned),
            'low_confidence_count' => (int) ($meta['low_confidence_member_count']
                ?? $diagnostics['low_confidence_member_count']
                ?? 0),
            'model' => isset($model['name']) ? (string) $model['name'] : null,
            'model_version' => isset($model['version']) ? (string) $model['version'] : null,
            'algorithm' => (string) ($meta['algorithm'] ?? $diagnostics['algorithm'] ?? ''),
            'proposal_payload' => $payload,
            'diagnostics' => $diagnostics,
            'error_code' => null,
            'error_message' => null,
            'completed_at' => now(),
        ]);
        $run->save();
    }

    private function markFailed(
        SeoTopicGroupingRun $run,
        string $code,
        string $message,
        string $provider,
    ): SeoTopicGroupingRun {
        $run->status = TopicGroupingRunStatus::FAILED;
        $run->error_code = $code;
        $run->error_message = mb_substr($message, 0, 2000);
        $run->completed_at = now();
        $run->save();

        TopicReclusterUiState::markFailed(
            (int) $run->site_id,
            $message,
            [
                'run_id' => $run->id,
                'provider' => $provider,
                'error_code' => $code,
            ],
            $code,
            $provider,
        );

        \Illuminate\Support\Facades\Log::warning('topic_grouping.analyze.failed', [
            'run_id' => (int) $run->id,
            'site_id' => (int) $run->site_id,
            'provider' => $provider,
            'error_code' => $code,
            'error' => mb_substr($message, 0, 500),
            'analysis_id' => (string) ($run->external_analysis_id ?? ''),
            'input_hash' => (string) ($run->input_hash ?? ''),
        ]);

        return $run->fresh() ?? $run;
    }

    private function failedStub(int $siteId, string $code, string $message): SeoTopicGroupingRun
    {
        $run = new SeoTopicGroupingRun([
            'site_id' => $siteId,
            'provider' => TopicGroupingProviderMode::providerKey(),
            'input_hash' => '',
            'status' => TopicGroupingRunStatus::FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        if ($siteId > 0 && self::runsTableReady()) {
            $run->save();
        }
        TopicReclusterUiState::markFailed($siteId, $message, [], $code);

        return $run;
    }

    /**
     * @return array{
     *     locked_topic_ids: array<int, true>,
     *     locked_keyword_ids: array<int, true>,
     *     topics: list<array{topic_id: int, name: string, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>
     * }
     */
    private function loadLockedTopicsAndKeywords(int $siteId): array
    {
        /** @var array<int, true> $lockedTopicIds */
        $lockedTopicIds = [];
        /** @var array<int, true> $lockedKeywordIds */
        $lockedKeywordIds = [];
        /** @var list<array{topic_id: int, name: string, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}> $topics */
        $topics = [];

        $lockedTopics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->get(['id', 'name']);

        foreach ($lockedTopics as $topic) {
            $topicId = (int) $topic->id;
            $lockedTopicIds[$topicId] = true;
            $members = [];
            $rows = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->get(['keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);
            foreach ($rows as $row) {
                $keywordId = (int) $row->keyword_id;
                $lockedKeywordIds[$keywordId] = true;
                $members[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => '',
                    'source' => (string) $row->source,
                    'is_seed' => (bool) $row->is_seed,
                    'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                    'is_locked' => (bool) $row->is_locked,
                ];
            }
            $topics[] = [
                'topic_id' => $topicId,
                'name' => (string) $topic->name,
                'is_locked' => true,
                'members' => $members,
            ];
        }

        $membershipLocks = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->get(['keyword_id']);
        foreach ($membershipLocks as $row) {
            $lockedKeywordIds[(int) $row->keyword_id] = true;
        }

        return [
            'locked_topic_ids' => $lockedTopicIds,
            'locked_keyword_ids' => $lockedKeywordIds,
            'topics' => $topics,
        ];
    }

    /**
     * @param  array<int, true>  $lockedTopicIds
     * @return list<array{topic_id: int, name: string, is_locked: bool, accept_attach: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>
     */
    private function loadManualInventoryTopics(int $siteId, array $lockedTopicIds): array
    {
        $manuals = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('source', TopicSource::MANUAL)
            ->get(['id', 'name', 'is_locked']);
        if ($manuals->isEmpty()) {
            return [];
        }

        /** @var array<int, true> $manualIds */
        $manualIds = [];
        foreach ($manuals as $topic) {
            $topicId = (int) $topic->id;
            if ($topicId > 0 && ! isset($lockedTopicIds[$topicId])) {
                $manualIds[$topicId] = true;
            }
        }
        if ($manualIds === []) {
            return [];
        }

        $memberRows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', array_keys($manualIds))
            ->get(['topic_id', 'keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);

        /** @var array<int, list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>> $membersByTopic */
        $membersByTopic = [];
        foreach ($memberRows as $row) {
            $membersByTopic[(int) $row->topic_id][] = [
                'keyword_id' => (int) $row->keyword_id,
                'phrase' => '',
                'source' => (string) $row->source,
                'is_seed' => (bool) $row->is_seed,
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'is_locked' => (bool) $row->is_locked,
            ];
        }

        $inventory = [];
        foreach ($manuals as $topic) {
            $topicId = (int) $topic->id;
            if (! isset($manualIds[$topicId])) {
                continue;
            }
            $inventory[] = [
                'topic_id' => $topicId,
                'name' => (string) $topic->name,
                'is_locked' => (bool) $topic->is_locked,
                'accept_attach' => false,
                'members' => $membersByTopic[$topicId] ?? [],
            ];
        }

        return $inventory;
    }
}
