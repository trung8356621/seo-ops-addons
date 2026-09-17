<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;

/**
 * Recluster(site_id) — site-isolated Topic rebuild from Link List + product_cat seeds.
 *
 * Does not migrate legacy cluster-key / DNA. Does not force Focus⇒Topic.
 * Respects topic + membership locks.
 */
final class TopicReclusterService
{
    public function __construct(
        private readonly TopicSiteKeywordService $siteKeywords,
        private readonly TopicSeedResolver $seeds,
        private readonly TopicClusterEngine $engine,
        private readonly TopicDnaService $dna,
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
            'topics_before' => 0,
            'topics_after' => 0,
            'topics_locked_preserved' => 0,
            'memberships_written' => 0,
            'memberships_locked_preserved' => 0,
            'dna_rows' => 0,
            'topics_dissolved' => 0,
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

            $eligible = $this->siteKeywords->loadEligibleSeoKeywords($siteId);
            $locked = $this->loadLockedState($siteId);
            $metrics['topics_before'] = SeoTopic::query()->where('site_id', $siteId)->count();
            $metrics['topics_locked_preserved'] = count($locked['topics']);
            $metrics['memberships_locked_preserved'] = count($locked['keyword_ids']);

            $clusters = $this->engine->cluster(
                $seedRows,
                $eligible,
                $locked['topics'],
                $locked['keyword_ids'],
            );

            $written = DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $clusters, $locked, &$metrics): array {
                return $this->persistClusters($siteId, $clusters, $locked);
            });

            $metrics['topics_after'] = $written['topics_after'];
            $metrics['memberships_written'] = $written['memberships_written'];
            $metrics['dna_rows'] = $written['dna_rows'];
            $metrics['topics_dissolved'] = $written['topics_dissolved'];

            return TopicReclusterResult::ok($metrics);
        } catch (\Throwable $e) {
            return TopicReclusterResult::failed($e->getMessage(), $metrics);
        }
    }

    /**
     * @return array{
     *     topics: list<array{topic_id: int, name: string, keyword_ids: list<int>, is_locked: bool}>,
     *     keyword_ids: array<int, true>
     * }
     */
    private function loadLockedState(int $siteId): array
    {
        $lockedTopics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->get(['id', 'name', 'is_locked']);

        $topics = [];
        /** @var array<int, true> $lockedKeywordIds */
        $lockedKeywordIds = [];

        foreach ($lockedTopics as $topic) {
            $memberIds = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', (int) $topic->id)
                ->pluck('keyword_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            foreach ($memberIds as $keywordId) {
                $lockedKeywordIds[$keywordId] = true;
            }
            $topics[] = [
                'topic_id' => (int) $topic->id,
                'name' => (string) $topic->name,
                'keyword_ids' => $memberIds,
                'is_locked' => true,
            ];
        }

        $manualLocked = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->pluck('keyword_id');
        foreach ($manualLocked as $keywordId) {
            $lockedKeywordIds[(int) $keywordId] = true;
        }

        return ['topics' => $topics, 'keyword_ids' => $lockedKeywordIds];
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  array{topics: list<array{topic_id: int, name: string, keyword_ids: list<int>, is_locked: bool}>, keyword_ids: array<int, true>}  $locked
     * @return array{topics_after: int, memberships_written: int, dna_rows: int, topics_dissolved: int}
     */
    private function persistClusters(int $siteId, array $clusters, array $locked): array
    {
        $preserveTopicIds = [];
        foreach ($locked['topics'] as $t) {
            $preserveTopicIds[$t['topic_id']] = true;
        }

        // Wipe unlocked topic DNA + memberships + topics for this site only.
        $dissolveIds = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('is_locked', false)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($dissolveIds !== []) {
            SeoTopicKeywordDna::query()
                ->where('site_id', $siteId)
                ->whereIn('topic_id', $dissolveIds)
                ->delete();
            SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('topic_id', $dissolveIds)
                ->where('is_locked', false)
                ->delete();
            // Remove unlocked memberships that pointed at dissolve targets even if somehow locked=false only.
            SeoTopic::query()
                ->where('site_id', $siteId)
                ->whereIn('id', $dissolveIds)
                ->delete();
        }

        // Drop unlocked memberships that are not on preserved locked topics (site-scoped).
        SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', false)
            ->when(
                $preserveTopicIds !== [],
                static fn ($q) => $q->whereNotIn('topic_id', array_keys($preserveTopicIds)),
                static fn ($q) => $q,
            )
            ->delete();

        $membershipsWritten = 0;
        $dnaRows = 0;
        $topicIdsTouched = [];

        foreach ($clusters as $cluster) {
            $topic = null;
            if ($cluster['topic_id'] !== null && isset($preserveTopicIds[$cluster['topic_id']])) {
                $topic = SeoTopic::query()
                    ->where('site_id', $siteId)
                    ->where('id', $cluster['topic_id'])
                    ->first();
            }

            if (! $topic instanceof SeoTopic) {
                $topic = SeoTopic::query()->create([
                    'site_id' => $siteId,
                    'name' => $cluster['name'],
                    'status' => TopicStatus::ACTIVE,
                    'is_locked' => (bool) $cluster['is_locked'],
                ]);
            }

            $topicId = (int) $topic->id;
            $topicIdsTouched[$topicId] = true;
            $memberKeywordIds = [];

            foreach ($cluster['members'] as $member) {
                $keywordId = (int) $member['keyword_id'];
                if ($keywordId <= 0) {
                    continue;
                }
                // Never steal a locked membership belonging to another topic.
                if (isset($locked['keyword_ids'][$keywordId]) && $cluster['topic_id'] === null) {
                    $existing = SeoTopicKeyword::query()
                        ->where('site_id', $siteId)
                        ->where('keyword_id', $keywordId)
                        ->where('is_locked', true)
                        ->first();
                    if ($existing instanceof SeoTopicKeyword && (int) $existing->topic_id !== $topicId) {
                        continue;
                    }
                }

                SeoTopicKeyword::query()->updateOrCreate(
                    [
                        'site_id' => $siteId,
                        'keyword_id' => $keywordId,
                    ],
                    [
                        'topic_id' => $topicId,
                        'source' => $member['source'],
                        'is_seed' => (bool) $member['is_seed'],
                        'is_locked' => (bool) $member['is_locked'],
                        'confidence' => $member['confidence'],
                    ],
                );
                $membershipsWritten++;
                $memberKeywordIds[] = $keywordId;
            }

            $dnaRows += $this->dna->rebuildForTopic($siteId, $topicId, (string) $topic->name, $memberKeywordIds);
        }

        return [
            'topics_after' => SeoTopic::query()->where('site_id', $siteId)->count(),
            'memberships_written' => $membershipsWritten,
            'dna_rows' => $dnaRows,
            'topics_dissolved' => count($dissolveIds),
        ];
    }
}
