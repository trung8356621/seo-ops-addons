<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDiscoveredIdentityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2B: specificity attach, discovery (≥3), collapse, identity, display names.
 */
final class TopicPhase2BAttachDiscoverIdentityTest extends TestCase
{
    private TopicPhraseResolver $phrases;

    private TopicClusterEngine $engine;

    private TopicDiscoveredIdentityResolver $discoveredIdentity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);
        $this->engine = new TopicClusterEngine(
            new TopicMembershipMatcher($this->phrases),
            new KeywordNormalizer,
            $this->phrases,
        );
        $this->discoveredIdentity = new TopicDiscoveredIdentityResolver($this->phrases);
    }

    public function test_specific_topic_beats_broader_on_direct_attach(): void
    {
        $seeds = [
            $this->seed(1, 'Xưởng May Cặp'),
            $this->seed(2, 'Xưởng May Cặp Laptop'),
        ];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Xưởng May Cặp', 'is_seo_keyword' => true],
            ['keyword_id' => 2, 'phrase' => 'Xưởng May Cặp Laptop', 'is_seo_keyword' => true],
            ['keyword_id' => 10, 'phrase' => 'Xưởng may cặp laptop da thật cao cấp', 'is_seo_keyword' => true],
        ];

        $topics = $this->engine->cluster($seeds, $eligible);
        $laptop = $this->topicByNameContains($topics, 'Laptop');
        $memberIds = array_column($laptop['members'], 'keyword_id');

        self::assertContains(10, $memberIds);
        $broad = $this->topicByNameContains($topics, 'Cặp', exclude: 'Laptop');
        self::assertNotContains(10, array_column($broad['members'], 'keyword_id'));
    }

    public function test_direct_matcher_beats_core_fallback(): void
    {
        $seeds = [
            $this->seed(1, 'Balo thời trang'),
            $this->seed(2, 'Xưởng may balo quà tặng'),
        ];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Balo thời trang', 'is_seo_keyword' => true],
            ['keyword_id' => 2, 'phrase' => 'Xưởng may balo quà tặng', 'is_seo_keyword' => true],
            // Direct-contains "Balo thời trang" — must not core-fallback elsewhere.
            ['keyword_id' => 11, 'phrase' => 'Balo thời trang canvas đen', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);
        $fashion = $this->topicByNameContains($topics, 'thời trang');
        self::assertContains(11, array_column($fashion['members'], 'keyword_id'));
        self::assertSame(1, $metrics['members_attached_direct']);
        self::assertSame(0, $metrics['members_attached_core_fallback']);
    }

    public function test_unique_core_fallback_attaches_and_ambiguous_stays_remainder(): void
    {
        $seeds = [
            $this->seed(1, 'Xưởng may balo quà tặng'),
            $this->seed(2, 'Xưởng may balo anh ngữ'),
            $this->seed(3, 'Xưởng may balo anh văn'),
        ];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Xưởng may balo quà tặng', 'is_seo_keyword' => true],
            ['keyword_id' => 2, 'phrase' => 'Xưởng may balo anh ngữ', 'is_seo_keyword' => true],
            ['keyword_id' => 3, 'phrase' => 'Xưởng may balo anh văn', 'is_seo_keyword' => true],
            // Unique product core → quà tặng
            ['keyword_id' => 20, 'phrase' => 'balo quà tặng doanh nghiệp', 'is_seo_keyword' => true],
            // Unique → anh ngữ
            ['keyword_id' => 21, 'phrase' => 'May balo anh ngữ bằng vải nylon', 'is_seo_keyword' => true],
            // Ambiguous shared "balo" only product cores that both match? use phrase matching both anh topics badly
            // "balo anh" alone won't match 2-token product cores of either fully if cores are balo+anh+ngu vs balo+anh+van
            ['keyword_id' => 22, 'phrase' => 'cách chọn balo', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);

        $quaTang = $this->topicByNameContains($topics, 'quà tặng');
        self::assertContains(20, array_column($quaTang['members'], 'keyword_id'));

        $anhNgu = $this->topicByNameContains($topics, 'anh ngữ');
        self::assertContains(21, array_column($anhNgu['members'], 'keyword_id'));

        $allMemberIds = [];
        foreach ($topics as $topic) {
            foreach ($topic['members'] as $member) {
                $allMemberIds[] = $member['keyword_id'];
            }
        }
        self::assertNotContains(22, $allMemberIds);
        self::assertGreaterThanOrEqual(1, $metrics['members_attached_core_fallback']);
    }

    public function test_two_token_product_core_does_not_core_fallback_tui_xach(): void
    {
        $seeds = [$this->seed(1, 'xưởng may túi xách')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'xưởng may túi xách', 'is_seo_keyword' => true],
            ['keyword_id' => 40, 'phrase' => 'kích thước túi xách', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);
        $allMemberIds = [];
        foreach ($topics as $topic) {
            foreach ($topic['members'] as $member) {
                $allMemberIds[] = $member['keyword_id'];
            }
        }
        self::assertNotContains(40, $allMemberIds);
        self::assertSame(0, $metrics['members_attached_core_fallback']);
    }

    public function test_two_token_product_core_does_not_core_fallback_balo_laptop(): void
    {
        $seeds = [$this->seed(1, 'Xưởng May Balo Laptop')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Xưởng May Balo Laptop', 'is_seo_keyword' => true],
            // Contains balo+laptop but not the full Topic phrase → no direct; 2-token core → no fallback.
            ['keyword_id' => 41, 'phrase' => 'balo laptop chống sốc 15 inch', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);
        $allMemberIds = [];
        foreach ($topics as $topic) {
            foreach ($topic['members'] as $member) {
                $allMemberIds[] = $member['keyword_id'];
            }
        }
        self::assertNotContains(41, $allMemberIds);
        self::assertSame(0, $metrics['members_attached_core_fallback']);
    }

    public function test_raw_may_machine_word_rejects_core_fallback(): void
    {
        $seeds = [$this->seed(1, 'Xưởng May Balo Du Lịch')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Xưởng May Balo Du Lịch', 'is_seo_keyword' => true],
            ['keyword_id' => 50, 'phrase' => 'Máy cắt vải balo du lịch', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);
        $allMemberIds = [];
        foreach ($topics as $topic) {
            foreach ($topic['members'] as $member) {
                $allMemberIds[] = $member['keyword_id'];
            }
        }
        self::assertNotContains(50, $allMemberIds);
        self::assertSame(0, $metrics['members_attached_core_fallback']);
    }

    public function test_ascii_may_sewing_phrase_still_allows_three_token_fallback(): void
    {
        $seeds = [$this->seed(1, 'Xưởng may balo quà tặng')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Xưởng may balo quà tặng', 'is_seo_keyword' => true],
            ['keyword_id' => 51, 'phrase' => 'may balo quà tặng vải canvas', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);
        $quaTang = $this->topicByNameContains($topics, 'quà tặng');
        self::assertContains(51, array_column($quaTang['members'], 'keyword_id'));
        self::assertSame(1, $metrics['members_attached_core_fallback']);
    }

    public function test_three_related_remainder_creates_one_discovered_topic(): void
    {
        $seeds = [$this->seed(1, 'Túi đựng mỹ phẩm')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Túi đựng mỹ phẩm', 'is_seo_keyword' => true],
            ['keyword_id' => 30, 'phrase' => 'Balo thời trang', 'is_seo_keyword' => true],
            ['keyword_id' => 31, 'phrase' => 'Balo thời trang nam', 'is_seo_keyword' => true],
            ['keyword_id' => 32, 'phrase' => 'Balo thời trang nữ da', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);

        $discovered = array_values(array_filter(
            $topics,
            fn (array $t): bool => ! $this->clusterHasSeed($t),
        ));
        self::assertCount(1, $discovered);
        self::assertCount(3, $discovered[0]['members']);
        foreach ($discovered[0]['members'] as $member) {
            self::assertFalse($member['is_seed']);
        }
        self::assertSame(1, $metrics['discovered_proposals_after_collapse']);
    }

    public function test_two_related_remainder_does_not_create_discovered_topic(): void
    {
        $seeds = [$this->seed(1, 'Túi đựng mỹ phẩm')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Túi đựng mỹ phẩm', 'is_seo_keyword' => true],
            ['keyword_id' => 40, 'phrase' => 'Balo thời trang', 'is_seo_keyword' => true],
            ['keyword_id' => 41, 'phrase' => 'Balo thời trang nam', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);
        $discovered = array_values(array_filter($topics, fn (array $t): bool => ! $this->clusterHasSeed($t)));
        self::assertCount(0, $discovered);
        self::assertGreaterThanOrEqual(1, $metrics['discovered_groups_pruned_below_threshold']);
    }

    public function test_discovered_display_name_is_real_member_phrase_not_truncated_core(): void
    {
        $seeds = [$this->seed(1, 'Túi đựng mỹ phẩm')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Túi đựng mỹ phẩm', 'is_seo_keyword' => true],
            ['keyword_id' => 50, 'phrase' => 'May balo học sinh', 'is_seo_keyword' => true],
            ['keyword_id' => 51, 'phrase' => 'May balo học sinh giá rẻ', 'is_seo_keyword' => true],
            ['keyword_id' => 52, 'phrase' => 'May balo học sinh chống gù', 'is_seo_keyword' => true],
        ];

        $topics = $this->engine->cluster($seeds, $eligible);
        $discovered = array_values(array_filter($topics, fn (array $t): bool => ! $this->clusterHasSeed($t)));
        self::assertNotEmpty($discovered);
        $name = $discovered[0]['name'];
        self::assertNotSame('May balo học', $name);
        $memberPhrases = array_column($discovered[0]['members'], 'phrase');
        self::assertContains($name, $memberPhrases);
    }

    public function test_same_core_discovered_proposals_collapse_before_persist_shape(): void
    {
        $seeds = [$this->seed(1, 'Túi đựng mỹ phẩm')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Túi đựng mỹ phẩm', 'is_seo_keyword' => true],
            ['keyword_id' => 60, 'phrase' => 'May balo dây rút', 'is_seo_keyword' => true],
            ['keyword_id' => 61, 'phrase' => 'May balo dây rút giá rẻ', 'is_seo_keyword' => true],
            ['keyword_id' => 62, 'phrase' => 'May balo dây rút theo yêu cầu', 'is_seo_keyword' => true],
        ];

        $metrics = [];
        $topics = $this->engine->cluster($seeds, $eligible, metrics: $metrics);
        $discovered = array_values(array_filter($topics, fn (array $t): bool => ! $this->clusterHasSeed($t)));
        self::assertCount(1, $discovered);
        self::assertSame(1, $metrics['discovered_proposals_after_collapse']);
    }

    public function test_discovered_does_not_collapse_into_default_seed_topic(): void
    {
        $seeds = [
            $this->seed(1, 'Xưởng may balo dây rút'),
            $this->seed(2, 'Túi đựng mỹ phẩm'),
        ];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Xưởng may balo dây rút', 'is_seo_keyword' => true],
            ['keyword_id' => 2, 'phrase' => 'Túi đựng mỹ phẩm', 'is_seo_keyword' => true],
            ['keyword_id' => 70, 'phrase' => 'Cặp sách da bò', 'is_seo_keyword' => true],
            ['keyword_id' => 71, 'phrase' => 'Cặp sách da bò công sở', 'is_seo_keyword' => true],
            ['keyword_id' => 72, 'phrase' => 'Cặp sách da bò nam', 'is_seo_keyword' => true],
        ];

        $topics = $this->engine->cluster($seeds, $eligible);
        $seedTopic = $this->topicByNameContains($topics, 'dây rút');
        $discovered = array_values(array_filter($topics, fn (array $t): bool => ! $this->clusterHasSeed($t)));
        self::assertCount(1, $discovered);
        self::assertNotSame($seedTopic['name'], $discovered[0]['name']);
        self::assertCount(1, array_filter($seedTopic['members'], static fn (array $m): bool => $m['is_seed']));
    }

    public function test_identity_first_create_then_identical_recluster_reuses(): void
    {
        $proposal = $this->discoveredProposal('Balo thời trang', [100, 101, 102]);
        $applied = $this->discoveredIdentity->apply([$proposal], []);
        self::assertNull($applied['clusters'][0]['topic_id']);
        self::assertSame(1, $applied['metrics']['discovered_topics_created']);

        $inventory = [[
            'topic_id' => 1137,
            'name' => 'Balo thời trang',
            'member_keyword_ids' => [100, 101, 102],
            'member_count' => 3,
            'is_locked' => false,
        ]];
        $second = $this->discoveredIdentity->apply([$proposal], $inventory);
        self::assertSame(1137, $second['clusters'][0]['topic_id']);
        self::assertSame(1, $second['metrics']['discovered_topics_reused']);
        self::assertSame(0, $second['metrics']['discovered_topics_created']);
    }

    public function test_identity_overlap_threshold_rejects_single_shared_member(): void
    {
        $proposal = $this->discoveredProposal('Balo thời trang', [100, 101, 102, 103, 104]);
        $inventory = [[
            'topic_id' => 50,
            'name' => 'Balo thời trang cũ',
            'member_keyword_ids' => [100, 200, 201, 202, 203],
            'member_count' => 5,
            'is_locked' => false,
        ]];
        // intersection=1; threshold = max(2, ceil(5/2))=3 → no reuse
        $applied = $this->discoveredIdentity->apply([$proposal], $inventory);
        self::assertNull($applied['clusters'][0]['topic_id']);
    }

    public function test_identity_threshold_qualified_overlap_reuses(): void
    {
        $proposal = $this->discoveredProposal('Balo thời trang', [100, 101, 102]);
        $inventory = [[
            'topic_id' => 50,
            'name' => 'Other name',
            'member_keyword_ids' => [100, 101, 199],
            'member_count' => 3,
            'is_locked' => false,
        ]];
        // intersection=2; threshold=max(2,ceil(3/2))=2 → reuse
        $applied = $this->discoveredIdentity->apply([$proposal], $inventory);
        self::assertSame(50, $applied['clusters'][0]['topic_id']);
    }

    public function test_identity_split_largest_overlap_keeps_id(): void
    {
        $inventory = [[
            'topic_id' => 99,
            'name' => 'Old',
            'member_keyword_ids' => range(1, 20),
            'member_count' => 20,
            'is_locked' => false,
        ]];
        $a = $this->discoveredProposal('Group A', range(1, 12));
        $b = $this->discoveredProposal('Group B', range(13, 20));
        $applied = $this->discoveredIdentity->apply([$a, $b], $inventory);
        self::assertSame(99, $applied['clusters'][0]['topic_id']);
        self::assertNull($applied['clusters'][1]['topic_id']);
    }

    public function test_identity_merge_survivor_lowest_id_on_tie_break(): void
    {
        $proposal = $this->discoveredProposal('Merged', [1, 2, 3, 4, 5, 6]);
        $inventory = [
            [
                'topic_id' => 20,
                'name' => 'A',
                'member_keyword_ids' => [1, 2, 3],
                'member_count' => 3,
                'is_locked' => false,
            ],
            [
                'topic_id' => 10,
                'name' => 'B',
                'member_keyword_ids' => [4, 5, 6],
                'member_count' => 3,
                'is_locked' => false,
            ],
        ];
        // Both intersection=3, same ratio, same prior_count → lowest topic_id=10
        $applied = $this->discoveredIdentity->apply([$proposal], $inventory);
        self::assertSame(10, $applied['clusters'][0]['topic_id']);
    }

    public function test_identity_never_reuses_manual_or_default_by_name_fallback(): void
    {
        // Inventory for discovered resolver is discovered-only by contract; passing a
        // "manual-looking" row still only matches discovered inventory snapshot.
        // Seed resolver path must keep seed topics separate:
        $seedClusters = [[
            'name' => 'Xưởng may balo thời trang',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [[
                'keyword_id' => 1,
                'phrase' => 'Xưởng may balo thời trang',
                'source' => TopicKeywordSource::PRODUCT_CAT,
                'is_seed' => true,
                'confidence' => 1.0,
                'is_locked' => false,
            ]],
        ]];
        $seedApplied = (new TopicSeedIdentityResolver)->apply($seedClusters, [1 => 417]);
        self::assertSame(417, $seedApplied[0]['topic_id']);

        $discovered = $this->discoveredProposal('Xưởng may balo thời trang', [9, 10, 11]);
        // Name-equal prior discovered only — not 417
        $applied = $this->discoveredIdentity->apply([$discovered], [[
            'topic_id' => 900,
            'name' => 'Xưởng may balo thời trang',
            'member_keyword_ids' => [50, 51, 52],
            'member_count' => 3,
            'is_locked' => false,
        ]]);
        self::assertSame(900, $applied['clusters'][0]['topic_id']);
        self::assertNotSame(417, $applied['clusters'][0]['topic_id']);
    }

    public function test_locked_prior_discovered_not_in_identity_candidates(): void
    {
        $proposal = $this->discoveredProposal('Balo thời trang', [100, 101, 102]);
        $applied = $this->discoveredIdentity->apply([$proposal], [[
            'topic_id' => 77,
            'name' => 'Balo thời trang',
            'member_keyword_ids' => [100, 101, 102],
            'member_count' => 3,
            'is_locked' => true,
        ]]);
        self::assertNull($applied['clusters'][0]['topic_id']);
    }

    public function test_no_fake_seed_on_discovered_members(): void
    {
        $seeds = [$this->seed(1, 'Túi đựng mỹ phẩm')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Túi đựng mỹ phẩm', 'is_seo_keyword' => true],
            ['keyword_id' => 80, 'phrase' => 'Balo thời trang', 'is_seo_keyword' => true],
            ['keyword_id' => 81, 'phrase' => 'Balo thời trang đẹp', 'is_seo_keyword' => true],
            ['keyword_id' => 82, 'phrase' => 'Balo thời trang nữ', 'is_seo_keyword' => true],
        ];
        $topics = $this->engine->cluster($seeds, $eligible);
        $discoveredSeen = false;
        foreach ($topics as $topic) {
            if ($this->clusterHasSeed($topic)) {
                continue;
            }
            $discoveredSeen = true;
            foreach ($topic['members'] as $member) {
                self::assertFalse($member['is_seed']);
            }
        }
        self::assertTrue($discoveredSeen);
    }

    public function test_engine_plus_identity_idempotent_shape(): void
    {
        $seeds = [$this->seed(1, 'Túi đựng mỹ phẩm')];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Túi đựng mỹ phẩm', 'is_seo_keyword' => true],
            ['keyword_id' => 90, 'phrase' => 'Balo thời trang', 'is_seo_keyword' => true],
            ['keyword_id' => 91, 'phrase' => 'Balo thời trang nam', 'is_seo_keyword' => true],
            ['keyword_id' => 92, 'phrase' => 'Balo thời trang nữ', 'is_seo_keyword' => true],
        ];

        $run1 = $this->engine->cluster($seeds, $eligible);
        $discovered1 = array_values(array_filter($run1, fn (array $t): bool => ! $this->clusterHasSeed($t)));
        self::assertCount(1, $discovered1);

        $inventory = [[
            'topic_id' => 555,
            'name' => $discovered1[0]['name'],
            'member_keyword_ids' => array_column($discovered1[0]['members'], 'keyword_id'),
            'member_count' => count($discovered1[0]['members']),
            'is_locked' => false,
        ]];

        $run2 = $this->engine->cluster($seeds, $eligible);
        $applied = $this->discoveredIdentity->apply($run2, $inventory);
        $discovered2 = null;
        foreach ($applied['clusters'] as $cluster) {
            if (! $this->clusterHasSeed($cluster)) {
                $discovered2 = $cluster;
                break;
            }
        }
        self::assertNotNull($discovered2);
        self::assertSame(555, $discovered2['topic_id']);
        self::assertSame(0, $applied['metrics']['discovered_topics_created']);
        self::assertSame(1, $applied['metrics']['discovered_topics_reused']);
    }

    public function test_overlap_threshold_formula(): void
    {
        $resolver = $this->discoveredIdentity;
        self::assertSame(2, $resolver->overlapThreshold(3, 3));
        self::assertSame(2, $resolver->overlapThreshold(5, 4));
        self::assertSame(6, $resolver->overlapThreshold(12, 20));
        self::assertSame(25, $resolver->overlapThreshold(50, 55));
    }

    /**
     * @return array{keyword_id: int, phrase: string, source: string, is_seed: true, confidence: float|null}
     */
    private function seed(int $keywordId, string $phrase): array
    {
        return [
            'keyword_id' => $keywordId,
            'phrase' => $phrase,
            'source' => TopicKeywordSource::LINK_LIST,
            'is_seed' => true,
            'confidence' => 1.0,
        ];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}
     */
    private function discoveredProposal(string $name, array $keywordIds): array
    {
        $members = [];
        foreach ($keywordIds as $keywordId) {
            $members[] = [
                'keyword_id' => $keywordId,
                'phrase' => $name.' '.$keywordId,
                'source' => TopicKeywordSource::RECLUSTER,
                'is_seed' => false,
                'confidence' => 0.75,
                'is_locked' => false,
            ];
        }

        return [
            'name' => $name,
            'topic_id' => null,
            'is_locked' => false,
            'members' => $members,
        ];
    }

    /**
     * @param  list<array{name: string, members: list<array{keyword_id: int}>}>  $topics
     * @return array{name: string, members: list<array{keyword_id: int}>}
     */
    private function topicByNameContains(array $topics, string $needle, string $exclude = ''): array
    {
        foreach ($topics as $topic) {
            if (! str_contains(mb_strtolower($topic['name']), mb_strtolower($needle))) {
                continue;
            }
            if ($exclude !== '' && str_contains(mb_strtolower($topic['name']), mb_strtolower($exclude))) {
                continue;
            }

            return $topic;
        }
        self::fail('Topic containing "'.$needle.'" not found');
    }

    /**
     * @param  array{members: list<array{is_seed: bool}>}  $cluster
     */
    private function clusterHasSeed(array $cluster): bool
    {
        foreach ($cluster['members'] as $member) {
            if ($member['is_seed'] ?? false) {
                return true;
            }
        }

        return false;
    }
}
