<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Jobs\RebuildTopicsFromKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromGroupSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromKeywordGroupMaterializer;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class TopicFromKeywordGroupsRebuildTest extends TestCase
{
    private const SITE = 77;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php')->up();
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_07_190000_add_is_topic_candidate_to_seo_keyword_group_keywords.php')->up();
    }

    public function test_ui_contract_alpine_open_and_new_job_dispatch(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        $concern = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );

        self::assertStringContainsString('Tạo lại chủ đề', (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/lang/vi/filament.php'
        ));
        self::assertStringContainsString("topic_recluster_action' => 'Tạo lại chủ đề'", (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/lang/vi/filament.php'
        ));

        // Idle configure opens client-side — toolbar must not use wire:click openReclusterModal.
        self::assertStringContainsString('rebuildModalOpen = true', $blade);
        self::assertGreaterThanOrEqual(2, substr_count($blade, 'x-on:click="rebuildModalOpen = true; fullReset = false"'));
        self::assertStringNotContainsString('wire:click="startTopicAnalysis"', $blade);
        self::assertStringContainsString('$wire.startTopicRebuildFromGroups(fullReset)', $blade);
        self::assertStringNotContainsString('nhóm lại toàn bộ keyword', $blade);
        self::assertStringContainsString('topic_rebuild_bullet_no_regroup', $blade);
        self::assertStringContainsString('topicFromGroupSnapshot', $concern);
        self::assertStringContainsString('RebuildTopicsFromKeywordGroupsJob::dispatch', $concern);
        self::assertStringNotContainsString('ReclusterSiteTopicsJob::dispatch(', $concern);
        self::assertStringNotContainsString('TopicGroupingProviderMode::SEMANTIC_HTTP', $concern);
        self::assertStringContainsString('startTopicRebuildFromGroups', $concern);

        // Only progress banner may use openReclusterModal (not configure toolbar).
        self::assertSame(1, substr_count($blade, 'wire:click="openReclusterModal"'));
    }

    public function test_job_unique_seo_queue_no_semantic_provider(): void
    {
        $job = new RebuildTopicsFromKeywordGroupsJob(4, TopicGroupingRebuildMode::FULL_RESET);
        self::assertSame(4, $job->siteId);
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $job->rebuildMode);
        self::assertSame('seo', $job->queue);
        self::assertSame('topic-from-keyword-groups:4', $job->uniqueId());

        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Jobs/RebuildTopicsFromKeywordGroupsJob.php'
        );
        self::assertStringContainsString('TopicFromKeywordGroupMaterializer', $src);
        self::assertStringNotContainsString('TopicGroupingAnalysisService', $src);
        self::assertStringNotContainsString('analyzeSite', $src);
        self::assertStringNotContainsString('/v1/topic/analyses', $src);
        self::assertStringNotContainsString('/v1/keyword-groups/analyses', $src);
    }

    public function test_materializer_source_never_calls_semantic_grouping(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicFromKeywordGroupMaterializer.php'
        );
        self::assertStringNotContainsString('TopicGroupingAnalysisService', $src);
        self::assertStringNotContainsString('SemanticHttpTopicGroupingProvider', $src);
        self::assertStringNotContainsString('/v1/topic/analyses', $src);
        self::assertStringNotContainsString('/v1/keyword-groups/analyses', $src);
        self::assertStringContainsString('persistResolvedClusters', $src);
        self::assertStringContainsString('is_topic_candidate', $src);
        self::assertStringContainsString('keyword_group_id', $src);
    }

    public function test_snapshot_counts_are_aggregates_only(): void
    {
        $a = $this->kw('balo học sinh');
        $b = $this->kw('xưởng may balo học sinh');
        $c = $this->kw('blocked brand keyword');
        $g = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo học sinh',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $a,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $g->id,
            'keyword_id' => $a,
            'source' => KeywordGroupSource::SEMANTIC,
            'is_topic_candidate' => true,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $g->id,
            'keyword_id' => $b,
            'source' => KeywordGroupSource::SEMANTIC,
            'is_topic_candidate' => true,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $g->id,
            'keyword_id' => $c,
            'source' => KeywordGroupSource::SEMANTIC,
            'is_topic_candidate' => false,
        ]);

        $snap = TopicFromGroupSnapshot::forSite(self::SITE);
        self::assertSame(1, $snap['group_count']);
        self::assertSame(2, $snap['topic_candidate_count']);
        self::assertSame(1, $snap['topic_blocked_count']);
    }

    public function test_group_boundary_and_topic_candidate_selection(): void
    {
        $seedA = $this->kw('balo học sinh');
        $memberA = $this->kw('mẫu balo học sinh đẹp');
        $blockedA = $this->kw('May Balo quà tặng FRESENIUS');
        $seedB = $this->kw('túi xách nữ');

        $groupA = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo học sinh',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $seedA,
            'is_locked' => false,
        ]);
        $groupB = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Túi xách nữ',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $seedB,
            'is_locked' => false,
        ]);

        foreach (
            [
                [$groupA->id, $seedA, true],
                [$groupA->id, $memberA, true],
                [$groupA->id, $blockedA, false],
                [$groupB->id, $seedB, true],
            ] as [$gid, $kid, $cand]
        ) {
            SeoKeywordGroupKeyword::query()->create([
                'site_id' => self::SITE,
                'group_id' => $gid,
                'keyword_id' => $kid,
                'source' => KeywordGroupSource::SEMANTIC,
                'is_topic_candidate' => $cand,
            ]);
        }

        $groupBefore = [
            'groups' => SeoKeywordGroup::query()->where('site_id', self::SITE)->count(),
            'memberships' => SeoKeywordGroupKeyword::query()->where('site_id', self::SITE)->count(),
            'blocked' => SeoKeywordGroupKeyword::query()
                ->where('site_id', self::SITE)
                ->where('is_topic_candidate', false)
                ->count(),
        ];

        $seedByKeyword = [
            $seedA => [
                'keyword_id' => $seedA,
                'phrase' => 'balo học sinh',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ],
            $seedB => [
                'keyword_id' => $seedB,
                'phrase' => 'túi xách nữ',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ],
        ];
        $matcher = new TopicMembershipMatcher(
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );
        $materializer = $this->materializerStub();
        $build = new \ReflectionMethod($materializer, 'buildClustersForGroup');
        $build->setAccessible(true);

        $builtA = $build->invoke($materializer, self::SITE, $groupA, $seedByKeyword, $matcher, true);
        $builtB = $build->invoke($materializer, self::SITE, $groupB, $seedByKeyword, $matcher, true);

        self::assertSame($groupBefore['groups'], SeoKeywordGroup::query()->where('site_id', self::SITE)->count());
        self::assertSame($groupBefore['memberships'], SeoKeywordGroupKeyword::query()->where('site_id', self::SITE)->count());
        self::assertSame($groupBefore['blocked'], SeoKeywordGroupKeyword::query()
            ->where('site_id', self::SITE)
            ->where('is_topic_candidate', false)
            ->count());
        self::assertFalse((bool) SeoKeywordGroupKeyword::query()
            ->where('keyword_id', $blockedA)
            ->value('is_topic_candidate'));

        self::assertGreaterThanOrEqual(1, $builtA['anchor_count']);
        self::assertGreaterThanOrEqual(1, $builtB['anchor_count']);

        foreach (array_merge($builtA['clusters'], $builtB['clusters']) as $cluster) {
            self::assertArrayHasKey('keyword_group_id', $cluster);
            self::assertContains((int) $cluster['keyword_group_id'], [(int) $groupA->id, (int) $groupB->id]);
            foreach ($cluster['members'] as $member) {
                $kid = (int) $member['keyword_id'];
                $memberGroup = (int) SeoKeywordGroupKeyword::query()
                    ->where('site_id', self::SITE)
                    ->where('keyword_id', $kid)
                    ->value('group_id');
                self::assertSame((int) $cluster['keyword_group_id'], $memberGroup);
                if ($kid === $blockedA) {
                    self::assertFalse((bool) $member['is_seed']);
                }
            }
            $anchorKid = (int) ($cluster['members'][0]['keyword_id'] ?? 0);
            self::assertNotSame($blockedA, $anchorKid);
        }

        self::assertTrue(SeoKeywordGroupKeyword::query()
            ->where('group_id', $groupA->id)
            ->where('keyword_id', $blockedA)
            ->exists());
    }

    public function test_multiple_seed_anchors_produce_multiple_topics_in_one_group(): void
    {
        $seed1 = $this->kw('balo học sinh');
        $seed2 = $this->kw('xưởng may balo học sinh');
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo học sinh family',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $seed1,
            'is_locked' => false,
        ]);
        foreach ([$seed1, $seed2] as $kid) {
            SeoKeywordGroupKeyword::query()->create([
                'site_id' => self::SITE,
                'group_id' => $group->id,
                'keyword_id' => $kid,
                'source' => KeywordGroupSource::SEMANTIC,
                'is_topic_candidate' => true,
            ]);
        }

        $seedByKeyword = [
            $seed1 => [
                'keyword_id' => $seed1,
                'phrase' => 'balo học sinh',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ],
            $seed2 => [
                'keyword_id' => $seed2,
                'phrase' => 'xưởng may balo học sinh',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ],
        ];
        $matcher = new TopicMembershipMatcher(
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );
        $materializer = $this->materializerStub();
        $build = new \ReflectionMethod($materializer, 'buildClustersForGroup');
        $build->setAccessible(true);
        $built = $build->invoke($materializer, self::SITE, $group, $seedByKeyword, $matcher, false);

        self::assertSame(2, $built['anchor_count']);
        self::assertCount(2, $built['clusters']);
        foreach ($built['clusters'] as $cluster) {
            self::assertSame((int) $group->id, (int) $cluster['keyword_group_id']);
        }
    }

    public function test_group_with_only_blocked_candidates_produces_zero_topics(): void
    {
        $blocked = $this->kw('brand only phrase');
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Blocked family',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $blocked,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => $blocked,
            'source' => KeywordGroupSource::SEMANTIC,
            'is_topic_candidate' => false,
        ]);

        $matcher = new TopicMembershipMatcher(
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );
        $materializer = $this->materializerStub();
        $build = new \ReflectionMethod($materializer, 'buildClustersForGroup');
        $build->setAccessible(true);
        $built = $build->invoke($materializer, self::SITE, $group, [], $matcher, false);

        self::assertSame(0, $built['anchor_count']);
        self::assertSame([], $built['clusters']);
    }

    /**
     * Minimal materializer — recluster/seeds unused by reflected buildClustersForGroup.
     */
    private function materializerStub(): TopicFromKeywordGroupMaterializer
    {
        $recluster = (new \ReflectionClass(TopicReclusterService::class))->newInstanceWithoutConstructor();
        $seeds = (new \ReflectionClass(TopicSeedResolver::class))->newInstanceWithoutConstructor();
        $matcher = new TopicMembershipMatcher(
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );

        return new TopicFromKeywordGroupMaterializer($recluster, $seeds, $matcher);
    }

    private function kw(string $phrase): int
    {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => 'normal',
            'review_status' => 'active',
        ]);

        return (int) $keyword->id;
    }

    private function ensureTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->string('review_status')->default('active');
            $table->timestamps();
        });
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source')->default('auto');
            $table->string('status')->default('active');
            $table->boolean('is_locked')->default(false);
            $table->unsignedBigInteger('keyword_group_id')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source')->nullable();
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->float('confidence')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_topic_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->timestamps();
        });
    }
}
