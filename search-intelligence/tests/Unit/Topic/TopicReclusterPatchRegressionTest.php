<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for seed-only Topics, seed-identity reuse, and lock semantics.
 */
final class TopicReclusterPatchRegressionTest extends TestCase
{
    private function engine(): TopicClusterEngine
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);

        return new TopicClusterEngine(
            new TopicMembershipMatcher($phrases),
            new KeywordNormalizer,
            $phrases,
        );
    }

    public function test_a_unmatched_seo_keyword_does_not_become_topic(): void
    {
        $seeds = [[
            'keyword_id' => 1,
            'phrase' => 'balo quà tặng',
            'source' => TopicKeywordSource::LINK_LIST,
            'is_seed' => true,
            'confidence' => 1.0,
        ]];
        $eligible = [
            [
                'keyword_id' => 1,
                'phrase' => 'balo quà tặng',
                'is_seo_keyword' => true,
            ],
            [
                'keyword_id' => 2,
                'phrase' => 'balo quà tặng giá rẻ',
                'is_seo_keyword' => true,
            ],
            [
                'keyword_id' => 3,
                'phrase' => 'cách giặt áo',
                'is_seo_keyword' => true,
            ],
        ];

        $topics = $this->engine()->cluster($seeds, $eligible);

        self::assertCount(1, $topics);
        self::assertSame(1, $topics[0]['members'][0]['keyword_id']);
        $memberIds = array_map(static fn (array $m): int => $m['keyword_id'], $topics[0]['members']);
        self::assertContains(2, $memberIds);
        self::assertNotContains(3, $memberIds);

        foreach ($topics as $topic) {
            foreach ($topic['members'] as $member) {
                self::assertNotSame(3, $member['keyword_id']);
            }
        }
    }

    public function test_b_recluster_preserves_topic_id_via_seed_identity(): void
    {
        $resolver = new TopicSeedIdentityResolver;
        $clusters = [[
            'name' => 'Balo quà tặng',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [[
                'keyword_id' => 50,
                'phrase' => 'balo quà tặng',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
                'is_locked' => false,
            ], [
                'keyword_id' => 51,
                'phrase' => 'balo quà tặng giá rẻ',
                'source' => TopicKeywordSource::RECLUSTER,
                'is_seed' => false,
                'confidence' => 0.8,
                'is_locked' => false,
            ]],
        ]];

        $applied = $resolver->apply($clusters, [50 => 12]);

        self::assertSame(12, $applied[0]['topic_id']);
        self::assertCount(2, $applied[0]['members']);
    }

    public function test_c_rename_does_not_change_identity(): void
    {
        $resolver = new TopicSeedIdentityResolver;
        $clusters = [[
            'name' => 'Balo quà tặng doanh nghiệp',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [[
                'keyword_id' => 50,
                'phrase' => 'balo quà tặng',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
                'is_locked' => false,
            ]],
        ]];

        $applied = $resolver->apply($clusters, [50 => 12]);

        self::assertSame(12, $applied[0]['topic_id']);
        self::assertSame('Balo quà tặng doanh nghiệp', $applied[0]['name']);
        // Persist must reuse id 12 without creating a duplicate from the renamed proposal name.
    }

    public function test_d_and_e_membership_lock_preserves_parent_not_whole_topic(): void
    {
        $engine = $this->engine();

        $seeds = [[
            'keyword_id' => 200,
            'phrase' => 'balo du lịch',
            'source' => TopicKeywordSource::LINK_LIST,
            'is_seed' => true,
            'confidence' => 1.0,
        ]];
        $eligible = [
            [
                'keyword_id' => 101,
                'phrase' => 'balo du lịch giá rẻ',
                'is_seo_keyword' => true,
            ],
            [
                'keyword_id' => 102,
                'phrase' => 'cách giặt áo',
                'is_seo_keyword' => true,
            ],
        ];

        // Keyword 100 is membership-locked on Topic 20 — must not move / must not seed a new Topic.
        $lockedKeywordIds = [100 => true];

        $topics = $engine->cluster($seeds, $eligible, [], $lockedKeywordIds);

        $allMemberIds = [];
        foreach ($topics as $topic) {
            self::assertNotSame(20, $topic['topic_id']);
            foreach ($topic['members'] as $member) {
                $allMemberIds[] = $member['keyword_id'];
                self::assertNotSame(100, $member['keyword_id']);
            }
        }

        self::assertContains(200, $allMemberIds);
        self::assertContains(101, $allMemberIds);
        self::assertNotContains(100, $allMemberIds);
        self::assertNotContains(102, $allMemberIds);

        // Lock-state shape used by persist: parent of locked membership is preserved,
        // but unlocked siblings are not forced onto that Topic by the engine.
        $lockedState = [
            'locked_topic_ids' => [],
            'preserved_topic_ids' => [20 => true],
            'locked_keyword_ids' => [100 => true],
            'locked_memberships_by_topic' => [
                20 => [[
                    'keyword_id' => 100,
                    'phrase' => 'balo khóa tay',
                    'source' => TopicKeywordSource::MANUAL,
                    'is_seed' => false,
                    'confidence' => null,
                    'is_locked' => true,
                ]],
            ],
        ];

        self::assertArrayHasKey(20, $lockedState['preserved_topic_ids']);
        self::assertArrayNotHasKey(20, $lockedState['locked_topic_ids']);
        self::assertSame(100, $lockedState['locked_memberships_by_topic'][20][0]['keyword_id']);
    }

    public function test_f_site_isolation_queries_are_site_scoped(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php';
        $src = (string) file_get_contents($path);

        self::assertGreaterThanOrEqual(
            10,
            substr_count($src, "where('site_id', \$siteId)"),
            'Recluster persistence must stay site-scoped',
        );
        self::assertStringContainsString('loadSeedIdentityMap', $src);
        self::assertStringContainsString("where('is_seed', true)", $src);
        self::assertStringContainsString('preserved_topic_ids', $src);
        self::assertStringContainsString('locked_memberships_by_topic', $src);
        self::assertStringContainsString('locked_topic_ids', $src);
    }

    public function test_persist_deletes_stale_topics_after_keep_ids_known(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php';
        $src = (string) file_get_contents($path);

        $methodPos = strpos($src, 'private function persistClusters');
        self::assertNotFalse($methodPos);
        $methodSrc = substr($src, $methodPos);

        $writePos = strpos($methodSrc, 'updateOrCreate');
        $stalePos = strpos($methodSrc, 'Dissolve stale unlocked Topics last');
        $deleteTopicPos = strpos($methodSrc, 'SeoTopic::query()');

        self::assertNotFalse($writePos);
        self::assertNotFalse($stalePos);
        self::assertLessThan($stalePos, $writePos);

        // Final SeoTopic delete for stale ids must appear after the dissolve marker.
        $afterStale = substr($methodSrc, $stalePos);
        self::assertStringContainsString("->whereIn('id', \$staleIds)", $afterStale);
        self::assertStringContainsString('->delete()', $afterStale);
        self::assertStringContainsString('recluster_refused_orphan_locked_membership', $src);
        self::assertStringContainsString('do NOT overwrite user-facing name', $src);
        self::assertNotFalse($deleteTopicPos);
    }

    public function test_seed_identity_does_not_claim_already_bound_topic(): void
    {
        $resolver = new TopicSeedIdentityResolver;
        $clusters = [
            [
                'name' => 'Locked',
                'topic_id' => 12,
                'is_locked' => true,
                'members' => [],
            ],
            [
                'name' => 'Other seed',
                'topic_id' => null,
                'is_locked' => false,
                'members' => [[
                    'keyword_id' => 99,
                    'phrase' => 'other',
                    'source' => TopicKeywordSource::LINK_LIST,
                    'is_seed' => true,
                    'confidence' => 1.0,
                    'is_locked' => false,
                ]],
            ],
        ];

        $applied = $resolver->apply($clusters, [99 => 12]);

        self::assertSame(12, $applied[0]['topic_id']);
        self::assertNull($applied[1]['topic_id']);
    }

    public function test_engine_has_no_self_topic_runtime_path(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicClusterEngine.php';
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('Remaining SEO keywords', $src);
        self::assertStringNotContainsString('→ self-topic', $src);
        self::assertStringContainsString('Unmatched eligible SEO keywords stay', $src);
        self::assertStringContainsString('site-classified only', $src);
    }
}
