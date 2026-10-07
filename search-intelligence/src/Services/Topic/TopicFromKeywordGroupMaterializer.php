<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Contracts\GlobalMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\MatchRules\IndustryMatchRuntime;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;

/**
 * Build Topics from persisted Keyword Groups — no semantic regrouping.
 *
 * Groups are read-only. Only Topic-owned rows are mutated via
 * {@see TopicReclusterService::persistResolvedClusters()}.
 */
final class TopicFromKeywordGroupMaterializer
{
    public const ALGORITHM = 'from_keyword_groups';

    public function __construct(
        private readonly TopicReclusterService $recluster,
        private readonly TopicSeedResolver $seeds,
        private readonly TopicMembershipMatcher $matcher,
        private readonly ?IndustryMatchRuntime $industryRules = null,
        private readonly ?GlobalMatchRuleProvider $globalRules = null,
    ) {}

    public function materialize(int $siteId, string $rebuildMode = TopicGroupingRebuildMode::PRESERVE_EXISTING): TopicReclusterResult
    {
        if ($siteId <= 0) {
            return TopicReclusterResult::failed('site_required');
        }
        if (! TopicReclusterService::tablesReady()) {
            return TopicReclusterResult::failed('topic_tables_missing');
        }
        if (! KeywordGroupSchema::tablesReady()) {
            return TopicReclusterResult::failed('keyword_group_tables_missing');
        }

        $rebuildMode = TopicGroupingRebuildMode::normalize($rebuildMode);
        $fullReset = TopicGroupingRebuildMode::isFullReset($rebuildMode);

        $groupBefore = $this->groupFingerprint($siteId);

        $metrics = [
            'site_id' => $siteId,
            'rebuild_mode' => $rebuildMode,
            'source' => self::ALGORITHM,
            'groups_loaded' => 0,
            'topics_before' => SeoTopic::query()->where('site_id', $siteId)->count(),
            'topics_after' => 0,
            'topics_created' => 0,
            'topics_reused' => 0,
            'topics_dissolved' => 0,
            'memberships_written' => 0,
            'dna_rows' => 0,
            'anchors_selected' => 0,
            'groups_with_zero_topics' => 0,
        ];

        try {
            $matcher = $this->matcherWithRules($siteId);
            $seedByKeyword = [];
            foreach ($this->seeds->resolve($siteId) as $seed) {
                $seedByKeyword[(int) $seed['keyword_id']] = $seed;
            }

            $clusters = [];
            $groups = SeoKeywordGroup::query()
                ->where('site_id', $siteId)
                ->orderBy('id')
                ->get();
            $metrics['groups_loaded'] = $groups->count();

            foreach ($groups as $group) {
                $built = $this->buildClustersForGroup($siteId, $group, $seedByKeyword, $matcher, $fullReset);
                $metrics['anchors_selected'] += $built['anchor_count'];
                if ($built['anchor_count'] === 0 && $built['clusters'] === []) {
                    $metrics['groups_with_zero_topics']++;
                }
                foreach ($built['clusters'] as $cluster) {
                    $clusters[] = $cluster;
                }
            }

            $written = DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $clusters, $fullReset): array {
                if ($fullReset) {
                    $this->prepareFullResetTopicStructure($siteId);
                }

                $emptyLocked = [
                    'locked_topic_ids' => [],
                    'preserved_topic_ids' => [],
                    'locked_keyword_ids' => [],
                    'locked_memberships_by_topic' => [],
                    'topics' => [],
                ];

                return $fullReset
                    ? $this->recluster->persistResolvedClusters(
                        $siteId,
                        $clusters,
                        false,
                        $emptyLocked,
                        [],
                        [],
                    )
                    : $this->recluster->persistResolvedClusters($siteId, $clusters, false);
            });

            $metrics['topics_after'] = (int) ($written['topics_after'] ?? 0);
            $metrics['topics_created'] = (int) ($written['topics_created'] ?? 0);
            $metrics['topics_reused'] = (int) ($written['topics_reused'] ?? 0);
            $metrics['topics_dissolved'] = (int) ($written['topics_dissolved'] ?? 0);
            $metrics['memberships_written'] = (int) ($written['memberships_written'] ?? 0);
            $metrics['dna_rows'] = (int) ($written['dna_rows'] ?? 0);

            $groupAfter = $this->groupFingerprint($siteId);
            if ($groupBefore !== $groupAfter) {
                return TopicReclusterResult::failed('keyword_groups_mutated', $metrics);
            }

            return TopicReclusterResult::ok($metrics);
        } catch (\Throwable $e) {
            return TopicReclusterResult::failed($e->getMessage(), $metrics);
        }
    }

    /**
     * @param  array<int, array{keyword_id: int, phrase: string, source: string, is_seed: true, confidence: float|null}>  $seedByKeyword
     * @return array{anchor_count: int, clusters: list<array<string, mixed>>}
     */
    private function buildClustersForGroup(
        int $siteId,
        SeoKeywordGroup $group,
        array $seedByKeyword,
        TopicMembershipMatcher $matcher,
        bool $fullReset,
    ): array {
        $groupId = (int) $group->id;
        $memberships = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->where('group_id', $groupId)
            ->orderBy('keyword_id')
            ->get();

        if ($memberships->isEmpty()) {
            return ['anchor_count' => 0, 'clusters' => []];
        }

        $keywordIds = $memberships->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
        $phrases = Keyword::query()->whereIn('id', $keywordIds)->pluck('phrase', 'id')->all();

        /** @var array<int, array{keyword_id: int, phrase: string, is_topic_candidate: bool}> $members */
        $members = [];
        /** @var array<int, true> $candidateSet */
        $candidateSet = [];
        foreach ($memberships as $row) {
            $kid = (int) $row->keyword_id;
            $isCandidate = KeywordGroupSchema::topicCandidateReady()
                ? (bool) ($row->is_topic_candidate ?? true)
                : true;
            $phrase = (string) ($phrases[$kid] ?? '');
            $members[$kid] = [
                'keyword_id' => $kid,
                'phrase' => $phrase,
                'is_topic_candidate' => $isCandidate,
            ];
            if ($isCandidate) {
                $candidateSet[$kid] = true;
            }
        }

        /** @var array<int, array{topic_id: int|null, name: string, is_locked: bool, anchor_keyword_id: int|null, source: string}> $anchors */
        $anchors = [];
        /** @var array<int, true> $claimedAnchorKeywords */
        $claimedAnchorKeywords = [];
        /** @var array<int, true> $claimedTopicIds */
        $claimedTopicIds = [];

        $groupTopics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('keyword_group_id', $groupId)
            ->orderBy('id')
            ->get(['id', 'name', 'source', 'is_locked']);

        // A — protected / manual / locked Topic anchors in this Group
        foreach ($groupTopics as $topic) {
            $topicId = (int) $topic->id;
            $isManual = (string) $topic->source === TopicSource::MANUAL;
            $isLocked = (bool) $topic->is_locked;
            if ($fullReset) {
                continue;
            }
            if (! $isManual && ! $isLocked) {
                continue;
            }
            $anchors[] = [
                'topic_id' => $topicId,
                'name' => (string) $topic->name,
                'is_locked' => $isLocked,
                'anchor_keyword_id' => $this->primarySeedKeywordId($siteId, $topicId),
                'source' => $isManual ? TopicSource::MANUAL : TopicSource::AUTO,
            ];
            $claimedTopicIds[$topicId] = true;
            $seedKid = $this->primarySeedKeywordId($siteId, $topicId);
            if ($seedKid !== null) {
                $claimedAnchorKeywords[$seedKid] = true;
            }
        }

        // B — Topic seed evidence ∩ group candidates
        foreach ($seedByKeyword as $kid => $seed) {
            if (! isset($candidateSet[$kid]) || isset($claimedAnchorKeywords[$kid])) {
                continue;
            }
            $reuseId = $this->existingTopicIdForSeed($siteId, $groupId, $kid, $claimedTopicIds);
            $name = TopicNaming::canonicalName((string) $seed['phrase']) ?: (string) $seed['phrase'];
            $anchors[] = [
                'topic_id' => $reuseId,
                'name' => $name,
                'is_locked' => false,
                'anchor_keyword_id' => $kid,
                'source' => TopicSource::AUTO,
            ];
            $claimedAnchorKeywords[$kid] = true;
            if ($reuseId !== null) {
                $claimedTopicIds[$reuseId] = true;
            }
        }

        // C — existing reusable auto Topic identities already linked via keyword_group_id
        if (! $fullReset) {
            foreach ($groupTopics as $topic) {
                $topicId = (int) $topic->id;
                if (isset($claimedTopicIds[$topicId])) {
                    continue;
                }
                if ((string) $topic->source === TopicSource::MANUAL || (bool) $topic->is_locked) {
                    continue;
                }
                $seedKid = $this->primarySeedKeywordId($siteId, $topicId);
                if ($seedKid !== null && ! isset($candidateSet[$seedKid])) {
                    // Seed no longer a candidate — still reuse identity if group link exists,
                    // but do not treat blocked keyword as a new anchor identity.
                    $seedKid = null;
                }
                if ($seedKid !== null && isset($claimedAnchorKeywords[$seedKid])) {
                    continue;
                }
                $anchors[] = [
                    'topic_id' => $topicId,
                    'name' => (string) $topic->name,
                    'is_locked' => false,
                    'anchor_keyword_id' => $seedKid,
                    'source' => TopicSource::AUTO,
                ];
                $claimedTopicIds[$topicId] = true;
                if ($seedKid !== null) {
                    $claimedAnchorKeywords[$seedKid] = true;
                }
            }
        }

        // D — fallback when Group has candidates but no anchor
        if ($anchors === [] && $candidateSet !== []) {
            $repId = (int) ($group->representative_keyword_id ?? 0);
            $fallbackId = ($repId > 0 && isset($candidateSet[$repId]))
                ? $repId
                : (int) array_key_first($candidateSet);
            if ($fallbackId > 0 && isset($members[$fallbackId])) {
                $phrase = $members[$fallbackId]['phrase'];
                $name = TopicNaming::canonicalName($phrase) ?: $phrase;
                $anchors[] = [
                    'topic_id' => null,
                    'name' => $name !== '' ? $name : (string) $group->name,
                    'is_locked' => false,
                    'anchor_keyword_id' => $fallbackId,
                    'source' => TopicSource::AUTO,
                ];
                $claimedAnchorKeywords[$fallbackId] = true;
            }
        }

        if ($anchors === []) {
            return ['anchor_count' => 0, 'clusters' => []];
        }

        /** @var list<array{name: string, topic_id: int|null, is_locked: bool, keyword_group_id: int, group_key: string, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}> $topics */
        $topics = [];
        /** @var array<int, true> $assigned */
        $assigned = [];

        foreach ($anchors as $index => $anchor) {
            $membersOut = [];
            $anchorKid = $anchor['anchor_keyword_id'];
            if ($anchorKid !== null && isset($members[$anchorKid])) {
                $seedMeta = $seedByKeyword[$anchorKid] ?? null;
                $membersOut[] = [
                    'keyword_id' => $anchorKid,
                    'phrase' => $members[$anchorKid]['phrase'],
                    'source' => $seedMeta['source'] ?? TopicKeywordSource::RECLUSTER,
                    'is_seed' => true,
                    'confidence' => $seedMeta['confidence'] ?? 1.0,
                    'is_locked' => false,
                ];
                $assigned[$anchorKid] = true;
            }

            // Preserve current locked/manual memberships for protected anchors
            if ($anchor['topic_id'] !== null && ($anchor['is_locked'] || $anchor['source'] === TopicSource::MANUAL) && ! $fullReset) {
                $existing = SeoTopicKeyword::query()
                    ->where('site_id', $siteId)
                    ->where('topic_id', $anchor['topic_id'])
                    ->get(['keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);
                foreach ($existing as $row) {
                    $kid = (int) $row->keyword_id;
                    if (! isset($members[$kid]) || isset($assigned[$kid])) {
                        continue;
                    }
                    $assigned[$kid] = true;
                    $membersOut[] = [
                        'keyword_id' => $kid,
                        'phrase' => $members[$kid]['phrase'],
                        'source' => (string) $row->source,
                        'is_seed' => (bool) $row->is_seed,
                        'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                        'is_locked' => (bool) $row->is_locked,
                    ];
                }
            }

            $topics[] = [
                'name' => $anchor['name'],
                'topic_id' => $anchor['topic_id'],
                'is_locked' => $anchor['is_locked'],
                'keyword_group_id' => $groupId,
                'group_key' => 'kg:'.$groupId.':'.$index,
                'members' => $membersOut,
            ];
        }

        // Assign remaining group members (candidates + blocked) via matcher — Group pool only.
        foreach ($members as $kid => $member) {
            if (isset($assigned[$kid])) {
                continue;
            }
            $phrase = $member['phrase'];
            if ($phrase === '') {
                continue;
            }
            $best = $this->pickBestTopicIndex($phrase, $topics, $matcher);
            if ($best === null) {
                // Single-anchor Group: still only attach when compatible (matcher).
                continue;
            }
            $topics[$best]['members'][] = [
                'keyword_id' => $kid,
                'phrase' => $phrase,
                'source' => TopicKeywordSource::RECLUSTER,
                'is_seed' => false,
                'confidence' => 0.8,
                'is_locked' => false,
            ];
            $assigned[$kid] = true;
        }

        return [
            'anchor_count' => count($anchors),
            'clusters' => $topics,
        ];
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<mixed>}>  $topics
     */
    private function pickBestTopicIndex(string $phrase, array $topics, TopicMembershipMatcher $matcher): ?int
    {
        /** @var list<int> $matches */
        $matches = [];
        foreach ($topics as $index => $topic) {
            if ($matcher->matches($phrase, (string) $topic['name'])) {
                $matches[] = $index;
            }
        }
        if ($matches === []) {
            return null;
        }
        if (count($matches) === 1) {
            return $matches[0];
        }

        // Prefer more specific (longer) topic name; stable by index on ties.
        usort($matches, static function (int $a, int $b) use ($topics): int {
            $lenA = mb_strlen((string) $topics[$a]['name']);
            $lenB = mb_strlen((string) $topics[$b]['name']);
            if ($lenA !== $lenB) {
                return $lenB <=> $lenA;
            }

            return $a <=> $b;
        });

        return $matches[0];
    }

    private function primarySeedKeywordId(int $siteId, int $topicId): ?int
    {
        $kid = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->where('is_seed', true)
            ->orderBy('keyword_id')
            ->value('keyword_id');

        return $kid !== null ? (int) $kid : null;
    }

    /**
     * @param  array<int, true>  $claimedTopicIds
     */
    private function existingTopicIdForSeed(int $siteId, int $groupId, int $keywordId, array $claimedTopicIds): ?int
    {
        $row = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->where('is_seed', true)
            ->first(['topic_id']);
        if ($row === null) {
            return null;
        }
        $topicId = (int) $row->topic_id;
        if ($topicId <= 0 || isset($claimedTopicIds[$topicId])) {
            return null;
        }
        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first(['id', 'keyword_group_id', 'source', 'is_locked']);
        if (! $topic instanceof SeoTopic) {
            return null;
        }
        $linkedGroup = (int) ($topic->keyword_group_id ?? 0);
        if ($linkedGroup > 0 && $linkedGroup !== $groupId) {
            return null;
        }

        return $topicId;
    }

    private function matcherWithRules(int $siteId): TopicMembershipMatcher
    {
        $industry = $this->industryRules?->rulesForSite($siteId) ?? [];
        $global = $this->globalRules?->globalMatchRules() ?? [];
        if ($industry === [] && $global === []) {
            return $this->matcher;
        }

        return $this->matcher->withRules($industry, $global);
    }

    private function prepareFullResetTopicStructure(int $siteId): void
    {
        SeoTopic::query()
            ->where('site_id', $siteId)
            ->update([
                'is_locked' => false,
                'source' => TopicSource::AUTO,
            ]);

        SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->update(['is_locked' => false]);
    }

    /**
     * @return array{groups: int, memberships: int, candidates_false: int, names_hash: string}
     */
    private function groupFingerprint(int $siteId): array
    {
        $conn = DB::connection('omi_seo_ai');
        $groups = (int) $conn->table('seo_keyword_groups')->where('site_id', $siteId)->count();
        $memberships = (int) $conn->table('seo_keyword_group_keywords')->where('site_id', $siteId)->count();
        $blocked = KeywordGroupSchema::topicCandidateReady()
            ? (int) $conn->table('seo_keyword_group_keywords')
                ->where('site_id', $siteId)
                ->where('is_topic_candidate', false)
                ->count()
            : 0;
        $names = $conn->table('seo_keyword_groups')
            ->where('site_id', $siteId)
            ->orderBy('id')
            ->pluck('name')
            ->implode('|');

        return [
            'groups' => $groups,
            'memberships' => $memberships,
            'candidates_false' => $blocked,
            'names_hash' => sha1($names),
        ];
    }
}
