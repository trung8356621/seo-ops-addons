<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoMcpTopicGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterIndexMcpPreviewSummary;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\CreateManualTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupCoverageBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\McpTopicGroupService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;
use ReflectionMethod;
use Tests\TestCase;

final class QuickCreateAndMcpGroupTest extends TestCase
{
    private const SITE_ID = 88;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
    }

    public function test_quick_create_reuses_rename_targeted_resolver_and_attaches_matches(): void
    {
        $this->seedEligibleKeyword('Balo anh văn quốc tế');
        $this->seedEligibleKeyword('Balo anh văn trung tâm ABC');
        $this->seedEligibleKeyword('Balo anh văn cho học sinh');
        $this->seedEligibleKeyword('May balo học sinh');

        $createSource = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/CreateManualTopicClusterService.php');
        self::assertStringContainsString('reevaluateMembershipForCanonical', $createSource);
        self::assertStringNotContainsString('ReclusterTopicClustersService', $createSource);
        self::assertStringNotContainsString('ReclusterTopicClustersJob', $createSource);

        $created = app(CreateManualTopicClusterService::class)->create(self::SITE_ID, 'Balo anh văn');

        self::assertGreaterThanOrEqual(2, (int) $created['keyword_count']);
        self::assertSame('manual', $created['canonical_source']);
        self::assertSame('active', $created['state']);
        self::assertGreaterThanOrEqual(2, (int) $created['attached']);

        $attachedPhrases = Keyword::query()
            ->whereIn('id', SeoKeywordClassification::query()
                ->where('cluster_key', $created['cluster_key'])
                ->pluck('keyword_id'))
            ->pluck('phrase')
            ->all();
        self::assertContains('Balo anh văn quốc tế', $attachedPhrases);
        self::assertNotContains('May balo học sinh', $attachedPhrases);

        $mayId = (int) Keyword::query()->where('phrase', 'May balo học sinh')->value('id');
        self::assertTrue(
            SeoKeywordClassification::query()->where('keyword_id', $mayId)->value('cluster_key') === null
            || SeoKeywordClassification::query()->where('keyword_id', $mayId)->value('cluster_key') === '',
        );
    }

    public function test_quick_create_preserves_empty_manual_when_no_match(): void
    {
        $this->seedEligibleKeyword('túi canvas thường');

        $created = app(CreateManualTopicClusterService::class)->create(self::SITE_ID, 'May túi canvas đặc biệt');

        self::assertSame(0, (int) $created['keyword_count']);
        self::assertSame('planned', $created['state']);
        self::assertSame('manual', $created['canonical_source']);
        self::assertTrue(
            SeoTopicClusterMeta::query()
                ->where('site_id', self::SITE_ID)
                ->where('cluster_key', $created['cluster_key'])
                ->exists(),
        );
    }

    public function test_rename_and_quick_create_share_same_resolver_method(): void
    {
        $method = new ReflectionMethod(UpdateClusterCanonicalService::class, 'reevaluateMembershipForCanonical');
        self::assertTrue($method->isPublic());

        $create = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/CreateManualTopicClusterService.php');
        $rename = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/UpdateClusterCanonicalService.php');
        self::assertStringContainsString('reevaluateMembershipForCanonical', $create);
        self::assertStringContainsString('reevaluateMembershipForCanonical', $rename);
        self::assertStringContainsString('function reconcileMembership', $rename);
        self::assertSame(2, substr_count($rename, '$this->reevaluateMembershipForCanonical('));
    }

    public function test_mask_inline_rename_does_not_mutate_clusters(): void
    {
        $this->seedClusterWithArticles('ck_a', 'Balo anh văn', 2);
        $this->seedClusterWithArticles('ck_b', 'Balo ngoại ngữ', 2);
        $group = app(McpTopicGroupService::class)->syncGroup(
            self::SITE_ID,
            ['ck_a', 'ck_b'],
            'Balo trung tâm ngoại ngữ',
            true,
        );

        $updated = app(McpTopicGroupService::class)->updateMaskName(
            self::SITE_ID,
            $group['group_ref'],
            'Balo ngoại ngữ',
        );
        self::assertSame('Balo ngoại ngữ', $updated['mask_name']);
        self::assertTrue($updated['mask_name_manual']);

        $profile = app(SiteMcpClusterTopicalProfileBuilder::class)->build(self::SITE_ID);
        self::assertSame(['Balo ngoại ngữ'], array_column($profile['topics'], 'name'));

        self::assertSame(
            'Balo anh văn',
            SeoTopicClusterMeta::query()->where('cluster_key', 'ck_a')->value('canonical_phrase'),
        );
        self::assertSame(
            'Balo ngoại ngữ',
            SeoTopicClusterMeta::query()->where('cluster_key', 'ck_b')->value('canonical_phrase'),
        );
        self::assertSame(
            ['ck_a', 'ck_b'],
            app(McpTopicGroupService::class)->groupsForSite(self::SITE_ID)[0]['member_keys'],
        );
    }

    public function test_mcp_group_does_not_need_primary_and_keeps_peer_members(): void
    {
        $a = $this->seedClusterWithArticles('ck_ngu', 'Balo anh ngữ', 3);
        $b = $this->seedClusterWithArticles('ck_van', 'Balo anh văn', 2);

        $group = app(McpTopicGroupService::class)->syncGroup(
            self::SITE_ID,
            ['ck_van', 'ck_ngu'],
            'Balo ngoại ngữ',
            true,
        );

        self::assertArrayNotHasKey('primary_cluster_key', $group);
        self::assertSame('Balo ngoại ngữ', $group['mask_name']);
        self::assertContains('ck_van', $group['member_keys']);
        self::assertContains('ck_ngu', $group['member_keys']);
        self::assertSame('ck_ngu', SeoKeywordClassification::query()->where('keyword_id', $a[0])->value('cluster_key'));
        self::assertSame('ck_van', SeoKeywordClassification::query()->where('keyword_id', $b[0])->value('cluster_key'));
        self::assertTrue(SeoMcpTopicGroup::query()->where('group_ref', $group['group_ref'])->exists());
    }

    public function test_mask_name_auto_suggest_and_user_override(): void
    {
        $svc = app(McpTopicGroupService::class);
        $suggested = $svc->suggestMaskName([
            'Balo trung tâm ngoại ngữ',
            'Balo anh văn',
            'Balo ngoại ngữ',
            'Balo anh ngữ',
        ]);
        self::assertNotSame('', $suggested);

        $this->seedClusterWithArticles('ck_a', 'Balo anh văn', 2);
        $this->seedClusterWithArticles('ck_b', 'Balo ngoại ngữ', 2);
        $group = $svc->syncGroup(self::SITE_ID, ['ck_a', 'ck_b'], 'Tên custom user', true);
        self::assertSame('Tên custom user', $group['mask_name']);
        self::assertTrue($group['mask_name_manual']);
    }

    public function test_site_mcp_uses_mask_name_once(): void
    {
        $this->seedClusterWithArticles('ck_ngu', 'Balo anh ngữ', 3);
        $this->seedClusterWithArticles('ck_van', 'Balo anh văn', 2);

        app(McpTopicGroupService::class)->syncGroup(
            self::SITE_ID,
            ['ck_van', 'ck_ngu'],
            'Balo ngoại ngữ',
            true,
        );

        $profile = $this->builder()->build(self::SITE_ID);
        $names = array_column($profile['topics'], 'name');
        $refs = array_column($profile['topics'], 'cluster_ref');

        self::assertCount(1, $profile['topics']);
        self::assertSame(['Balo ngoại ngữ'], $names);
        self::assertNotContains('ck_van', $refs);
        self::assertNotContains('ck_ngu', $refs);
        self::assertSame(5, (int) $profile['topics'][0]['article_count']);
        self::assertSame(100.0, (float) $profile['topics'][0]['weight']);
    }

    public function test_mcp_group_distinct_articles_with_overlap(): void
    {
        $a1 = $this->createPublishedArticle('A1');
        $shared = $this->createPublishedArticle('Shared');
        $b1 = $this->createPublishedArticle('B1');

        $kwA1 = $this->seedEligibleKeyword('Cluster A one');
        $kwA2 = $this->seedEligibleKeyword('Cluster A two');
        $kwB1 = $this->seedEligibleKeyword('Cluster B one');
        $kwB2 = $this->seedEligibleKeyword('Cluster B two');
        SeoKeywordClassification::query()->whereIn('keyword_id', [$kwA1, $kwA2])->update(['cluster_key' => 'ck_a']);
        SeoKeywordClassification::query()->whereIn('keyword_id', [$kwB1, $kwB2])->update(['cluster_key' => 'ck_b']);
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => 'ck_a'],
            ['canonical_phrase' => 'Cluster A', 'normalized_canonical' => 'cluster a', 'confidence' => 'high', 'needs_review' => false, 'canonical_source' => 'auto'],
        );
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => 'ck_b'],
            ['canonical_phrase' => 'Cluster B', 'normalized_canonical' => 'cluster b', 'confidence' => 'high', 'needs_review' => false, 'canonical_source' => 'auto'],
        );

        $this->createLinkMap($kwA1, $a1, $a1);
        $this->createLinkMap($kwA2, $shared, $shared);
        $this->createLinkMap($kwB1, $shared, $shared);
        $this->createLinkMap($kwB2, $b1, $b1);

        app(McpTopicGroupService::class)->syncGroup(self::SITE_ID, ['ck_a', 'ck_b'], 'Cluster AB', true);
        $profile = app(SiteMcpClusterTopicalProfileBuilder::class)->build(self::SITE_ID);

        self::assertSame(3, (int) $profile['topics'][0]['article_count']);
        self::assertSame(3, (int) $profile['total_published_articles']);
    }

    public function test_mcp_group_skips_excluded_members_from_aggregate(): void
    {
        $this->seedClusterWithArticles('ck_a', 'Cluster A', 3);
        $this->seedClusterWithArticles('ck_b', 'Cluster B', 2);
        app(McpTopicGroupService::class)->syncGroup(self::SITE_ID, ['ck_a', 'ck_b'], 'Cluster AB', true);

        SeoTopicClusterMeta::query()->where('cluster_key', 'ck_b')->update(['mcp_excluded' => true]);
        $profile = app(SiteMcpClusterTopicalProfileBuilder::class)->build(self::SITE_ID);
        self::assertSame(3, (int) $profile['topics'][0]['article_count']);
        self::assertSame(100.0, (float) $profile['topics'][0]['weight']);

        SeoTopicClusterMeta::query()->where('cluster_key', 'ck_b')->update(['mcp_excluded' => false, 'seo_excluded' => true]);
        $profile2 = app(SiteMcpClusterTopicalProfileBuilder::class)->build(self::SITE_ID);
        self::assertSame(3, (int) $profile2['topics'][0]['article_count']);
    }

    public function test_ungroup_and_one_member_auto_dissolves(): void
    {
        $this->seedClusterWithArticles('ck_a', 'Cluster A', 3);
        $this->seedClusterWithArticles('ck_b', 'Cluster B', 2);
        $this->seedClusterWithArticles('ck_c', 'Cluster C', 2);
        app(McpTopicGroupService::class)->syncGroup(self::SITE_ID, ['ck_a', 'ck_b', 'ck_c'], 'ABC', true);

        app(McpTopicGroupService::class)->ungroup(self::SITE_ID, 'ck_c');
        $groups = app(McpTopicGroupService::class)->groupsForSite(self::SITE_ID);
        self::assertCount(1, $groups);
        self::assertSame('ABC', $groups[0]['mask_name']);

        app(McpTopicGroupService::class)->ungroup(self::SITE_ID, 'ck_b');
        self::assertSame([], app(McpTopicGroupService::class)->groupsForSite(self::SITE_ID));

        $refs = array_column(app(SiteMcpClusterTopicalProfileBuilder::class)->build(self::SITE_ID)['topics'], 'cluster_ref');
        self::assertContains('ck_a', $refs);
        self::assertContains('ck_b', $refs);
        self::assertContains('ck_c', $refs);
    }

    public function test_remove_member_does_not_silently_change_mask(): void
    {
        $this->seedClusterWithArticles('ck_a', 'Cluster A', 2);
        $this->seedClusterWithArticles('ck_b', 'Cluster B', 2);
        $this->seedClusterWithArticles('ck_c', 'Cluster C', 2);
        $group = app(McpTopicGroupService::class)->syncGroup(
            self::SITE_ID,
            ['ck_a', 'ck_b', 'ck_c'],
            'Mask giữ nguyên',
            true,
        );

        app(McpTopicGroupService::class)->ungroup(self::SITE_ID, 'ck_c');
        $after = app(McpTopicGroupService::class)->groupsForSite(self::SITE_ID);
        self::assertCount(1, $after);
        self::assertSame('Mask giữ nguyên', $after[0]['mask_name']);
        self::assertSame($group['group_ref'], $after[0]['group_ref']);
    }

    public function test_mcp_preview_tokens_and_counters_use_mask_projection(): void
    {
        $this->seedClusterWithArticles('ck_a', 'Cluster A', 40);
        $this->seedClusterWithArticles('ck_b', 'Cluster B', 30);
        $this->seedClusterWithArticles('ck_c', 'Cluster C', 30);

        $beforeProfile = app(SiteMcpClusterTopicalProfileBuilder::class)->build(self::SITE_ID);
        $before = app(ClusterIndexMcpPreviewSummary::class)->summarize(self::SITE_ID);

        app(McpTopicGroupService::class)->syncGroup(self::SITE_ID, ['ck_a', 'ck_b'], 'Mask AB', true);

        $afterProfile = app(SiteMcpClusterTopicalProfileBuilder::class)->build(self::SITE_ID);
        $after = app(ClusterIndexMcpPreviewSummary::class)->summarize(self::SITE_ID);
        $afterNames = array_column($afterProfile['topics'], 'name');

        self::assertCount(3, $beforeProfile['topics']);
        self::assertCount(2, $afterProfile['topics']);
        self::assertContains('Mask AB', $afterNames);
        self::assertContains('Cluster C', $afterNames);
        self::assertNotContains('Cluster A', $afterNames);
        self::assertNotContains('Cluster B', $afterNames);
        self::assertSame(3, (int) $before['total_topics']);
        self::assertSame(2, (int) $after['total_topics']);
        self::assertSame(2, (int) $after['cluster_count']);
        self::assertGreaterThan(0, (int) $after['estimated_tokens']);
    }

    public function test_grouped_index_sorts_by_mask_and_search_finds_member(): void
    {
        $this->seedClusterWithArticles('ck_trung', 'Balo trung tâm ngoại ngữ', 2);
        $this->seedClusterWithArticles('ck_van', 'Balo anh văn', 2);
        $this->seedClusterWithArticles('ck_ngoai', 'Balo ngoại ngữ', 2);
        $this->seedClusterWithArticles('ck_ngu', 'Balo anh ngữ', 2);
        $this->seedClusterWithArticles('ck_zzz', 'Zebra balo', 2);

        app(McpTopicGroupService::class)->syncGroup(
            self::SITE_ID,
            ['ck_trung', 'ck_van', 'ck_ngoai', 'ck_ngu'],
            'Balo ngoại ngữ',
            true,
        );

        $query = app(KeywordClusterQuery::class);
        $synthetic = [
            $this->syntheticClusterRow('ck_trung', 'Balo trung tâm ngoại ngữ', 2),
            $this->syntheticClusterRow('ck_van', 'Balo anh văn', 2),
            $this->syntheticClusterRow('ck_ngoai', 'Balo ngoại ngữ', 2),
            $this->syntheticClusterRow('ck_ngu', 'Balo anh ngữ', 2),
            $this->syntheticClusterRow('ck_zzz', 'Zebra balo', 2),
        ];

        $method = new ReflectionMethod(KeywordClusterQuery::class, 'collapseToMcpProjection');
        $method->setAccessible(true);
        /** @var list<array<string, mixed>> $collapsed */
        $collapsed = $method->invoke($query, self::SITE_ID, $synthetic, '', false, '');

        $labels = array_column($collapsed, 'label');
        self::assertContains('Balo ngoại ngữ', $labels);
        self::assertNotContains('Balo anh văn', $labels);
        self::assertContains('Zebra balo', $labels);
        $groupRow = null;
        foreach ($collapsed as $row) {
            if (($row['label'] ?? '') === 'Balo ngoại ngữ') {
                $groupRow = $row;
                break;
            }
        }
        self::assertNotNull($groupRow);
        self::assertTrue((bool) ($groupRow['is_mcp_group'] ?? false));
        self::assertSame(4, (int) ($groupRow['mcp_member_count'] ?? 0));

        usort($collapsed, static fn (array $a, array $b): int => KeywordClusterQuery::compareClusterRows($a, $b, 'name_asc'));
        $sortedLabels = array_column($collapsed, 'label');
        self::assertLessThan(
            array_search('Zebra balo', $sortedLabels, true),
            array_search('Balo ngoại ngữ', $sortedLabels, true),
        );

        /** @var list<array<string, mixed>> $searchHits */
        $searchHits = $method->invoke($query, self::SITE_ID, $synthetic, 'anh văn', false, '');
        self::assertCount(1, $searchHits);
        self::assertSame('Balo ngoại ngữ', $searchHits[0]['label']);

        // Raw SEO membership still peer-tagged by mask (no primary role).
        $map = app(McpTopicGroupService::class)->membershipMapForSite(self::SITE_ID);
        self::assertSame('Balo ngoại ngữ', $map['ck_van']['mask_name']);
        self::assertSame($map['ck_van']['group_ref'], $map['ck_ngu']['group_ref']);
        self::assertArrayNotHasKey('role', $map['ck_van']);
        self::assertArrayNotHasKey('primary_cluster_key', $map['ck_van']);
    }

    /**
     * @return array<string, mixed>
     */
    private function syntheticClusterRow(string $key, string $label, int $articles): array
    {
        return [
            'cluster_key' => $key,
            'label' => $label,
            'keyword_count' => $articles,
            'article_count' => $articles,
            'internal_link_count' => $articles,
            'intent' => 'commercial',
            'coverage' => 'medium',
            'topical_share' => 10.0,
            'canonical_source' => 'auto',
            'state' => 'active',
            'mcp_excluded' => false,
            'seo_excluded' => false,
            'mcp_group' => null,
            'is_mcp_group' => false,
            'mcp_member_count' => 0,
            'mcp_members' => [],
            'groups' => [],
        ];
    }

    public function test_internal_link_ids_distinct_across_group_members(): void
    {
        $this->seedClusterWithArticles('ck_a', 'Cluster A', 1);
        $this->seedClusterWithArticles('ck_b', 'Cluster B', 1);
        $kwA = (int) SeoKeywordClassification::query()->where('cluster_key', 'ck_a')->value('keyword_id');
        $kwB = (int) SeoKeywordClassification::query()->where('cluster_key', 'ck_b')->value('keyword_id');
        $art = $this->createPublishedArticle('link target');
        $this->createLinkMap($kwA, $art, $art);
        $this->createLinkMap($kwB, $art, $art);

        $ids = app(McpTopicGroupService::class)->distinctInternalLinkIdsForClusters(self::SITE_ID, ['ck_a', 'ck_b']);
        self::assertGreaterThanOrEqual(2, count($ids));
        self::assertSame(count($ids), count(array_unique($ids)));
    }

    private function builder(): SiteMcpClusterTopicalProfileBuilder
    {
        return new SiteMcpClusterTopicalProfileBuilder(
            app(KeywordClusterQuery::class),
            app(KeywordGroupCoverageBuilder::class),
            app()->bound(KeywordDnaService::class) ? app(KeywordDnaService::class) : null,
        );
    }

    private function seedEligibleKeyword(string $phrase): int
    {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ]);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => (int) $keyword->id,
            'meta_key' => 'site.'.self::SITE_ID.'.owned',
            'meta_value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => mb_strtolower($phrase, 'UTF-8'),
            'folded_text' => mb_strtolower($phrase, 'UTF-8'),
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'commercial',
            'cluster_key' => null,
            'is_seo_keyword' => true,
            'classified_at' => now(),
        ]);

        return (int) $keyword->id;
    }

    /**
     * @return list<int>
     */
    private function seedClusterWithArticles(string $key, string $label, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $phrase = $label.' '.$i.' '.uniqid('', true);
            $kwId = $this->seedEligibleKeyword($phrase);
            SeoKeywordClassification::query()->where('keyword_id', $kwId)->update(['cluster_key' => $key]);
            $articleId = $this->createPublishedArticle($phrase);
            $this->createLinkMap($kwId, $articleId, $articleId);
            $ids[] = $kwId;
        }
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => $key],
            [
                'canonical_phrase' => $label,
                'normalized_canonical' => mb_strtolower($label, 'UTF-8'),
                'confidence' => 'high',
                'needs_review' => false,
                'canonical_source' => 'auto',
                'mcp_excluded' => false,
                'seo_excluded' => false,
            ],
        );

        return $ids;
    }

    private function createPublishedArticle(string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => self::SITE_ID,
            'title' => $title,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLinkMap(int $keywordId, int $sourceArticleId, ?int $targetArticleId): void
    {
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $keywordId,
            'source_article_id' => $sourceArticleId,
            'target_article_id' => $targetArticleId,
            'anchor_text' => 'anchor',
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('source_article_id')->index();
            $table->unsignedBigInteger('target_article_id')->nullable()->index();
            $table->text('anchor_text');
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('seo_keyword_classifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('keyword_id')->primary();
            $table->string('normalized_text')->nullable();
            $table->string('folded_text')->nullable();
            $table->string('phrase_kind')->nullable();
            $table->string('seo_intent')->nullable();
            $table->string('cluster_key')->nullable()->index();
            $table->boolean('is_seo_keyword')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key', 120);
            $table->string('canonical_phrase');
            $table->string('normalized_canonical');
            $table->string('confidence')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('canonical_source')->nullable();
            $table->boolean('mcp_excluded')->default(false);
            $table->boolean('seo_excluded')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'cluster_key']);
        });
        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key', 120);
            $table->string('alias_phrase');
            $table->string('normalized_alias');
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('seo_mcp_topic_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('group_ref', 120);
            $table->string('mask_name', 255);
            $table->boolean('mask_name_manual')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'group_ref']);
        });
        Schema::connection('omi_seo_ai')->create('seo_mcp_topic_group_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('group_ref', 120)->index();
            $table->string('cluster_key', 120);
            $table->timestamps();
            $table->unique(['site_id', 'cluster_key']);
        });
        Schema::connection('omi_seo_ai')->create('seo_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('cluster_key', 120)->nullable();
            $table->string('value')->nullable();
            $table->string('normalized_value')->nullable();
            $table->string('facet_type')->nullable();
            $table->string('confidence')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });
    }
}
