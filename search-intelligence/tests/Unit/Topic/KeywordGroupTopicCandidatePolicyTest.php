<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupManualService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupTopicCandidatePolicy;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromGroupSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromKeywordGroupMaterializer;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class KeywordGroupTopicCandidatePolicyTest extends TestCase
{
    private const SITE = 91;

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
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_07_200000_add_topic_candidate_override_to_seo_keyword_group_keywords.php')->up();
    }

    public function test_policy_truth_table(): void
    {
        self::assertFalse(KeywordGroupTopicCandidatePolicy::effectiveCandidate(false, null));
        self::assertTrue(KeywordGroupTopicCandidatePolicy::effectiveCandidate(true, null));
        self::assertTrue(KeywordGroupTopicCandidatePolicy::effectiveCandidate(false, true));
        self::assertFalse(KeywordGroupTopicCandidatePolicy::effectiveCandidate(true, false));
    }

    public function test_migration_backfill_false_to_override_false_true_stays_null(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->drop('seo_keyword_group_keywords');
        $schema->create('seo_keyword_group_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source')->default('manual');
            $table->float('similarity_score')->nullable();
            $table->boolean('is_topic_candidate')->default(true);
            $table->timestamps();
        });

        DB::connection('omi_seo_ai')->table('seo_keyword_group_keywords')->insert([
            [
                'site_id' => self::SITE,
                'group_id' => 1,
                'keyword_id' => 1,
                'source' => 'manual',
                'is_topic_candidate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => self::SITE,
                'group_id' => 1,
                'keyword_id' => 2,
                'source' => 'manual',
                'is_topic_candidate' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        (require dirname(__DIR__, 3).'/database/migrations/2026_10_07_200000_add_topic_candidate_override_to_seo_keyword_group_keywords.php')->up();

        $rows = DB::connection('omi_seo_ai')->table('seo_keyword_group_keywords')
            ->orderBy('keyword_id')
            ->get(['keyword_id', 'topic_candidate_override']);
        self::assertNull($rows[0]->topic_candidate_override);
        self::assertSame(0, (int) $rows[1]->topic_candidate_override);
    }

    public function test_materializer_focus_and_override_anchor_rules(): void
    {
        $withFocus = $this->kw('balo học sinh');
        // Phrase compatible with Focus anchor so matcher can attach as supporting member.
        $noFocus = $this->kw('mẫu balo học sinh đẹp');
        $forced = $this->kw('ép topic keyword');
        $blocked = $this->kw('blocked despite focus');
        $this->bindFocus($withFocus, 501);
        $this->bindFocus($blocked, 502);

        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Family',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $noFocus,
            'is_locked' => false,
        ]);
        $this->member($group->id, $withFocus, null);
        $this->member($group->id, $noFocus, null);
        $this->member($group->id, $forced, true);
        $this->member($group->id, $blocked, false);

        $seedByKeyword = [
            $withFocus => [
                'keyword_id' => $withFocus,
                'phrase' => 'balo học sinh',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ],
            $forced => [
                'keyword_id' => $forced,
                'phrase' => 'ép topic keyword',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ],
            $blocked => [
                'keyword_id' => $blocked,
                'phrase' => 'blocked despite focus',
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
        $built = $build->invoke($materializer, self::SITE, $group, $seedByKeyword, $matcher, true);

        $anchorIds = [];
        foreach ($built['clusters'] as $cluster) {
            $anchorIds[] = (int) ($cluster['members'][0]['keyword_id'] ?? 0);
        }
        self::assertContains($withFocus, $anchorIds);
        self::assertContains($forced, $anchorIds);
        self::assertNotContains($noFocus, $anchorIds);
        self::assertNotContains($blocked, $anchorIds);

        // No-focus keyword may still attach as supporting member.
        $allMembers = [];
        foreach ($built['clusters'] as $cluster) {
            foreach ($cluster['members'] as $member) {
                $allMembers[] = (int) $member['keyword_id'];
            }
        }
        self::assertContains($noFocus, $allMembers);
        self::assertTrue(SeoKeywordGroupKeyword::query()
            ->where('group_id', $group->id)
            ->where('keyword_id', $noFocus)
            ->exists());
    }

    public function test_manual_locked_topic_preserved_without_focus(): void
    {
        $anchor = $this->kw('manual topic phrase');
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Manual family',
            'source' => KeywordGroupSource::MANUAL,
            'representative_keyword_id' => $anchor,
            'is_locked' => false,
        ]);
        $this->member($group->id, $anchor, null);

        $topic = SeoTopic::query()->create([
            'site_id' => self::SITE,
            'name' => 'Manual topic phrase',
            'source' => TopicSource::MANUAL,
            'status' => 'active',
            'is_locked' => false,
            'keyword_group_id' => $group->id,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => self::SITE,
            'topic_id' => $topic->id,
            'keyword_id' => $anchor,
            'source' => TopicKeywordSource::MANUAL,
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);

        $matcher = new TopicMembershipMatcher(
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );
        $materializer = $this->materializerStub();
        $build = new \ReflectionMethod($materializer, 'buildClustersForGroup');
        $build->setAccessible(true);
        $built = $build->invoke($materializer, self::SITE, $group, [], $matcher, false);

        self::assertSame(1, $built['anchor_count']);
        self::assertSame((int) $topic->id, (int) $built['clusters'][0]['topic_id']);
    }

    public function test_override_service_and_read_model_chip_fields(): void
    {
        $kid = $this->kw('sản phẩm túi xách');
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Túi',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        $this->member($group->id, $kid, null);

        $read = app(KeywordGroupReadModel::class)->groupMembers(self::SITE, (int) $group->id);
        self::assertFalse($read['members'][0]['has_focus_article']);
        self::assertNull($read['members'][0]['topic_candidate_override']);
        self::assertFalse($read['members'][0]['effective_topic_candidate']);

        $manual = app(KeywordGroupManualService::class);
        $manual->setTopicCandidateOverride(self::SITE, (int) $group->id, $kid, true);
        $read = app(KeywordGroupReadModel::class)->groupMembers(self::SITE, (int) $group->id);
        self::assertTrue($read['members'][0]['topic_candidate_override']);
        self::assertTrue($read['members'][0]['effective_topic_candidate']);

        $manual->setTopicCandidateOverride(self::SITE, (int) $group->id, $kid, null);
        $row = SeoKeywordGroupKeyword::query()->where('keyword_id', $kid)->first();
        self::assertNull($row?->topicCandidateOverride());
    }

    public function test_snapshot_effective_counts(): void
    {
        $focusKid = $this->kw('có focus');
        $noFocus = $this->kw('chưa focus');
        $blocked = $this->kw('bị chặn');
        $this->bindFocus($focusKid, 601);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Snap',
            'source' => KeywordGroupSource::SEMANTIC,
            'is_locked' => false,
        ]);
        $this->member($group->id, $focusKid, null);
        $this->member($group->id, $noFocus, null);
        $this->member($group->id, $blocked, false);

        $snap = TopicFromGroupSnapshot::forSite(self::SITE);
        self::assertSame(1, $snap['group_count']);
        self::assertSame(1, $snap['topic_candidate_count']);
        self::assertSame(1, $snap['topic_no_focus_count']);
        self::assertSame(1, $snap['topic_blocked_count']);
    }

    public function test_ui_blade_contracts(): void
    {
        $groupsBlade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-groups.blade.php'
        );
        $topicBlade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        $css = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo/resources/css/keyword-workspace.css'
        );

        self::assertStringContainsString('keyword-group-member-chip--no-focus', $groupsBlade);
        self::assertStringContainsString('keyword-group-member-chip--force-allow', $groupsBlade);
        self::assertStringContainsString("setTopicCandidateOverride({{ \$groupId }}, {{ \$memberKey }}, 'allow')", $groupsBlade);
        self::assertStringContainsString('+Topic', $groupsBlade);
        self::assertStringContainsString('keyword-group-member-chip--no-focus', $css);
        self::assertStringContainsString('keyword-group-member-chip--force-allow', $css);

        self::assertStringNotContainsString(
            'startTopicRebuildFromGroups(fullReset); rebuildModalOpen = false',
            $topicBlade,
        );
        self::assertStringContainsString('submitting', $topicBlade);
        self::assertStringContainsString('x-bind:disabled="submitting"', $topicBlade);
        self::assertStringContainsString('topic_rebuild_starting', $topicBlade);
        self::assertStringContainsString('topic_rebuild_mode_full_reset', $topicBlade);
        self::assertStringContainsString('(rebuildModalOpen || submitting)', $topicBlade);
    }

    private function member(int $groupId, int $keywordId, ?bool $override): void
    {
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $groupId,
            'keyword_id' => $keywordId,
            'source' => KeywordGroupSource::SEMANTIC,
            'is_topic_candidate' => KeywordGroupTopicCandidatePolicy::legacyIsTopicCandidate($override),
            'topic_candidate_override' => $override,
        ]);
    }

    private function bindFocus(int $keywordId, int $articleId): void
    {
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $articleId,
            'site_id' => self::SITE,
            'title' => 'Article '.$articleId,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keywordId,
            'meta_key' => KeywordMetaKey::siteMainArticleId(self::SITE),
            'meta_value' => (string) $articleId,
        ]);
    }

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
        return (int) Keyword::query()->create([
            'phrase' => $phrase,
            'type' => 'normal',
            'review_status' => 'active',
        ])->id;
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
        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
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
