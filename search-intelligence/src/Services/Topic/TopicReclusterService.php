<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;

/**
 * Recluster(site_id) — site-isolated Topic rebuild from Link List + product_cat seeds.
 *
 * Manual Topics (seo_topics.source=manual) survive by topic_id without keyword seeds.
 * Does not migrate legacy cluster-key / DNA. Does not force Focus⇒Topic.
 * Preserves topic_id via seed membership identity. Respects topic + membership locks.
 */
final class TopicReclusterService
{
    public function __construct(
        private readonly TopicSiteKeywordService $siteKeywords,
        private readonly TopicSeedResolver $seeds,
        private readonly TopicClusterEngine $engine,
        private readonly TopicDnaService $dna,
        private readonly TopicMembershipReconcileService $reconcile,
        private readonly TopicSeedIdentityResolver $identity = new TopicSeedIdentityResolver,
    ) {}

    public static function tablesReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_topics')
            && $schema->hasTable('seo_topic_keywords')
            && $schema->hasTable('seo_site_keywords');
    }

    public function recluster(int $siteId): TopicReclusterResult
    {
        if ($siteId <= 0) {
            return TopicReclusterResult::failed('site_required');
        }
        if (! self::tablesReady()) {
            return TopicReclusterResult::failed('topic_tables_missing');
        }

        $metrics = [
            'site_id' => $siteId,
            'classifications_ensured' => 0,
            'seo_keywords' => 0,
            'seeds_link_list' => 0,
            'seeds_product_cat' => 0,
            'seeds_manual' => 0,
            'topics_before' => 0,
            'topics_after' => 0,
            'topics_locked_preserved' => 0,
            'topics_membership_lock_preserved' => 0,
            'memberships_written' => 0,
            'memberships_locked_preserved' => 0,
            'dna_rows' => 0,
            'topics_dissolved' => 0,
            'topics_reused' => 0,
            'topics_created' => 0,
        ];

        try {
            $classified = $this->siteKeywords->ensureForSite($siteId);
            $metrics['classifications_ensured'] = $classified['ensured'];
            $metrics['seo_keywords'] = $classified['seo_keywords'];

            $seedRows = $this->seeds->resolve($siteId);
            foreach ($seedRows as $seed) {
                if ($seed['source'] === 'link_list') {
                    $metrics['seeds_link_list']++;
                } elseif ($seed['source'] === 'product_cat') {
                    $metrics['seeds_product_cat']++;
                }
            }

            $manualTopicIds = SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('source', TopicSource::MANUAL)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
            $metrics['seeds_manual'] = count($manualTopicIds);

            $eligible = $this->siteKeywords->loadTopicCandidateKeywords($siteId);
            $locked = $this->loadLockedState($siteId);
            $seedIdentity = $this->loadSeedIdentityMap($siteId);
            $metrics['topics_before'] = SeoTopic::query()->where('site_id', $siteId)->count();
            $metrics['topics_locked_preserved'] = count($locked['locked_topic_ids']);
            $metrics['topics_membership_lock_preserved'] = count($locked['preserved_topic_ids'])
                - count($locked['locked_topic_ids']);
            $metrics['memberships_locked_preserved'] = count($locked['locked_keyword_ids']);

            $clusters = $this->engine->cluster(
                $seedRows,
                $eligible,
                $locked['topics'],
                $locked['locked_keyword_ids'],
            );
            $clusters = $this->identity->apply($clusters, $seedIdentity);

            $written = DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $clusters, $locked, $manualTopicIds, &$metrics): array {
                return $this->persistClusters($siteId, $clusters, $locked, $manualTopicIds);
            });

            foreach ($manualTopicIds as $manualTopicId) {
                $this->reconcile->reconcile($siteId, $manualTopicId);
            }

            $metrics['topics_after'] = $written['topics_after'];
            $metrics['memberships_written'] = $written['memberships_written'];
            $metrics['dna_rows'] = $written['dna_rows'];
            $metrics['topics_dissolved'] = $written['topics_dissolved'];
            $metrics['topics_reused'] = $written['topics_reused'];
            $metrics['topics_created'] = $written['topics_created'];

            return TopicReclusterResult::ok($metrics);
        } catch (\Throwable $e) {
            return TopicReclusterResult::failed($e->getMessage(), $metrics);
        }
    }

    /**
     * Snapshot seed membership anchors: keyword_id → topic_id (site-scoped).
     *
     * @return array<int, int>
     */
    private function loadSeedIdentityMap(int $siteId): array
    {
        /** @var array<int, int> $map */
        $map = [];
        $rows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seed', true)
            ->get(['keyword_id', 'topic_id']);

        foreach ($rows as $row) {
            $keywordId = (int) $row->keyword_id;
            $topicId = (int) $row->topic_id;
            if ($keywordId > 0 && $topicId > 0) {
                $map[$keywordId] = $topicId;
            }
        }

        return $map;
    }

    /**
     * @return array{
     *     locked_topic_ids: array<int, true>,
     *     preserved_topic_ids: array<int, true>,
     *     locked_keyword_ids: array<int, true>,
     *     locked_memberships_by_topic: array<int, list<array{
     *         keyword_id: int,
     *         source: string,
     *         is_seed: bool,
     *         confidence: float|null,
     *         is_locked: bool,
     *         phrase: string
     *     }>>,
     *     topics: list<array{
     *         topic_id: int,
     *         name: string,
     *         is_locked: bool,
     *         members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     *     }>
     * }
     */
    private function loadLockedState(int $siteId): array
    {
        /** @var array<int, true> $lockedTopicIds */
        $lockedTopicIds = [];
        /** @var array<int, true> $preservedTopicIds */
        $preservedTopicIds = [];
        /** @var array<int, true> $lockedKeywordIds */
        $lockedKeywordIds = [];
        /** @var array<int, list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>> $lockedMembershipsByTopic */
        $lockedMembershipsByTopic = [];
        /** @var list<array{topic_id: int, name: string, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}> $topicsForEngine */
        $topicsForEngine = [];

        $lockedTopics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->get(['id', 'name']);

        foreach ($lockedTopics as $topic) {
            $topicId = (int) $topic->id;
            $lockedTopicIds[$topicId] = true;
            $preservedTopicIds[$topicId] = true;

            $members = [];
            $rows = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->get(['keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);

            foreach ($rows as $row) {
                $keywordId = (int) $row->keyword_id;
                $lockedKeywordIds[$keywordId] = true;
                $member = [
                    'keyword_id' => $keywordId,
                    'phrase' => '',
                    'source' => (string) $row->source,
                    'is_seed' => (bool) $row->is_seed,
                    'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                    'is_locked' => (bool) $row->is_locked,
                ];
                $members[] = $member;
            }

            $topicsForEngine[] = [
                'topic_id' => $topicId,
                'name' => (string) $topic->name,
                'is_locked' => true,
                'members' => $members,
            ];
        }

        $membershipLocks = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->get(['topic_id', 'keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);

        foreach ($membershipLocks as $row) {
            $topicId = (int) $row->topic_id;
            $keywordId = (int) $row->keyword_id;
            $lockedKeywordIds[$keywordId] = true;
            $preservedTopicIds[$topicId] = true;

            if (isset($lockedTopicIds[$topicId])) {
                // Already fully covered by topic lock.
                continue;
            }

            $lockedMembershipsByTopic[$topicId][] = [
                'keyword_id' => $keywordId,
                'phrase' => '',
                'source' => (string) $row->source,
                'is_seed' => (bool) $row->is_seed,
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'is_locked' => true,
            ];
        }

        return [
            'locked_topic_ids' => $lockedTopicIds,
            'preserved_topic_ids' => $preservedTopicIds,
            'locked_keyword_ids' => $lockedKeywordIds,
            'locked_memberships_by_topic' => $lockedMembershipsByTopic,
            'topics' => $topicsForEngine,
        ];
    }

    /**
     * Persist proposals without deleting first. Stale unlocked Topics dissolve last.
     *
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  array{
     *     locked_topic_ids: array<int, true>,
     *     preserved_topic_ids: array<int, true>,
     *     locked_keyword_ids: array<int, true>,
     *     locked_memberships_by_topic: array<int, list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>>,
     *     topics: list<array{topic_id: int, name: string, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>
     * }  $locked
     * @param  list<int>  $manualTopicIds
     * @return array{
     *     topics_after: int,
     *     memberships_written: int,
     *     dna_rows: int,
     *     topics_dissolved: int,
     *     topics_reused: int,
     *     topics_created: int
     * }
     */
    private function persistClusters(int $siteId, array $clusters, array $locked, array $manualTopicIds = []): array
    {
        /** @var array<int, true> $keepTopicIds */
        $keepTopicIds = $locked['preserved_topic_ids'];
        foreach ($manualTopicIds as $manualTopicId) {
            $keepTopicIds[(int) $manualTopicId] = true;
        }
        /** @var array<int, true> $claimedTopicIds */
        $claimedTopicIds = [];
        /** @var array<int, array{topic_id: int, source: string, is_seed: bool, confidence: float|null, is_locked: bool}> $desiredByKeyword */
        $desiredByKeyword = [];
        /** @var array<int, true> $dnaTopicIds */
        $dnaTopicIds = [];

        $membershipsWritten = 0;
        $topicsReused = 0;
        $topicsCreated = 0;

        // Membership-lock-only parents: lock rows stay on their topic (not whole-topic lock).
        foreach ($locked['locked_memberships_by_topic'] as $topicId => $members) {
            $topicId = (int) $topicId;
            $keepTopicIds[$topicId] = true;
            foreach ($members as $member) {
                $keywordId = (int) $member['keyword_id'];
                $desiredByKeyword[$keywordId] = [
                    'topic_id' => $topicId,
                    'source' => (string) $member['source'],
                    'is_seed' => (bool) $member['is_seed'],
                    'confidence' => $member['confidence'],
                    'is_locked' => true,
                ];
            }
        }

        foreach ($clusters as $cluster) {
            $resolved = $this->resolveTopicRow($siteId, $cluster, $keepTopicIds, $claimedTopicIds);
            $topic = $resolved['topic'];
            $topicId = (int) $topic->id;
            $keepTopicIds[$topicId] = true;
            $claimedTopicIds[$topicId] = true;
            $dnaTopicIds[$topicId] = true;

            if ($resolved['created']) {
                $topicsCreated++;
            } else {
                $topicsReused++;
            }

            $isFullyTopicLocked = isset($locked['locked_topic_ids'][$topicId]);

            if ($isFullyTopicLocked) {
                // Topic lock: preserve every current membership; allow net-new unlocked matches.
                $existing = SeoTopicKeyword::query()
                    ->where('site_id', $siteId)
                    ->where('topic_id', $topicId)
                    ->get(['keyword_id', 'source', 'is_seed', 'confidence', 'is_locked']);
                foreach ($existing as $row) {
                    $keywordId = (int) $row->keyword_id;
                    $desiredByKeyword[$keywordId] = [
                        'topic_id' => $topicId,
                        'source' => (string) $row->source,
                        'is_seed' => (bool) $row->is_seed,
                        'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                        'is_locked' => (bool) $row->is_locked,
                    ];
                }
            }

            foreach ($cluster['members'] as $member) {
                $keywordId = (int) $member['keyword_id'];
                if ($keywordId <= 0) {
                    continue;
                }

                // Never steal a locked membership belonging to another topic.
                if (isset($locked['locked_keyword_ids'][$keywordId])) {
                    $ownerTopicId = $desiredByKeyword[$keywordId]['topic_id'] ?? null;
                    if ($ownerTopicId === null) {
                        $owner = SeoTopicKeyword::query()
                            ->where('site_id', $siteId)
                            ->where('keyword_id', $keywordId)
                            ->where('is_locked', true)
                            ->first();
                        $ownerTopicId = $owner instanceof SeoTopicKeyword ? (int) $owner->topic_id : null;
                    }
                    if ($ownerTopicId !== null && $ownerTopicId !== $topicId) {
                        continue;
                    }
                }

                if ($isFullyTopicLocked && isset($desiredByKeyword[$keywordId])) {
                    continue;
                }

                $desiredByKeyword[$keywordId] = [
                    'topic_id' => $topicId,
                    'source' => (string) $member['source'],
                    'is_seed' => (bool) $member['is_seed'],
                    'confidence' => $member['confidence'],
                    'is_locked' => (bool) $member['is_locked'],
                ];
            }
        }

        // Ensure membership-lock parent Topics still exist even if no proposal reused them.
        foreach (array_keys($locked['locked_memberships_by_topic']) as $topicId) {
            $topicId = (int) $topicId;
            $exists = SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('id', $topicId)
                ->exists();
            if (! $exists) {
                // Should not happen: we never deleted yet. Defensive no-op.
                continue;
            }
            $keepTopicIds[$topicId] = true;
            $dnaTopicIds[$topicId] = true;
        }

        foreach ($desiredByKeyword as $keywordId => $payload) {
            SeoTopicKeyword::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'keyword_id' => $keywordId,
                ],
                [
                    'topic_id' => $payload['topic_id'],
                    'source' => $payload['source'],
                    'is_seed' => $payload['is_seed'],
                    'is_locked' => $payload['is_locked'],
                    'confidence' => $payload['confidence'],
                ],
            );
            $membershipsWritten++;
        }

        // Drop unlocked memberships that are not part of the desired set (unmatched SEO → no row).
        SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', false)
            ->when(
                $desiredByKeyword !== [],
                static fn ($q) => $q->whereNotIn('keyword_id', array_keys($desiredByKeyword)),
                static fn ($q) => $q,
            )
            ->delete();

        // Rebuild DNA for every touched / preserved Topic (site-scoped).
        $dnaRows = 0;
        foreach (array_keys($dnaTopicIds) as $topicId) {
            $topic = SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('id', $topicId)
                ->first();
            if (! $topic instanceof SeoTopic) {
                continue;
            }
            $memberKeywordIds = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->pluck('keyword_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $dnaRows += $this->dna->rebuildForTopic($siteId, $topicId, (string) $topic->name, $memberKeywordIds);
        }

        // Dissolve stale unlocked Topics last — keepTopicIds already known.
        $staleIds = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('is_locked', false)
            ->when(
                $keepTopicIds !== [],
                static fn ($q) => $q->whereNotIn('id', array_keys($keepTopicIds)),
                static fn ($q) => $q,
            )
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($staleIds !== []) {
            SeoTopicKeywordDna::query()
                ->where('site_id', $siteId)
                ->whereIn('topic_id', $staleIds)
                ->delete();
            // Only unlocked memberships should remain; never orphan locked rows.
            SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('topic_id', $staleIds)
                ->where('is_locked', false)
                ->delete();

            $orphanLocked = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('topic_id', $staleIds)
                ->where('is_locked', true)
                ->exists();
            if ($orphanLocked) {
                throw new \RuntimeException('recluster_refused_orphan_locked_membership');
            }

            SeoTopic::query()
                ->where('site_id', $siteId)
                ->whereIn('id', $staleIds)
                ->delete();
        }

        return [
            'topics_after' => SeoTopic::query()->where('site_id', $siteId)->count(),
            'memberships_written' => $membershipsWritten,
            'dna_rows' => $dnaRows,
            'topics_dissolved' => count($staleIds),
            'topics_reused' => $topicsReused,
            'topics_created' => $topicsCreated,
        ];
    }

    /**
     * @param  array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}  $cluster
     * @param  array<int, true>  $keepTopicIds
     * @param  array<int, true>  $claimedTopicIds
     * @return array{topic: SeoTopic, created: bool}
     */
    private function resolveTopicRow(
        int $siteId,
        array $cluster,
        array &$keepTopicIds,
        array $claimedTopicIds,
    ): array {
        $topicId = $cluster['topic_id'];
        $topic = null;

        if ($topicId !== null && ! isset($claimedTopicIds[$topicId])) {
            $topic = SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('id', $topicId)
                ->first();
        }

        if ($topic instanceof SeoTopic) {
            // Reuse identity: keep id + created_at; do NOT overwrite user-facing name.
            $keepTopicIds[(int) $topic->id] = true;

            return ['topic' => $topic, 'created' => false];
        }

        $topic = SeoTopic::query()->create([
            'site_id' => $siteId,
            'name' => $cluster['name'],
            'source' => TopicSource::AUTO,
            'status' => TopicStatus::ACTIVE,
            'is_locked' => (bool) $cluster['is_locked'],
        ]);
        $keepTopicIds[(int) $topic->id] = true;

        return ['topic' => $topic, 'created' => true];
    }
}
