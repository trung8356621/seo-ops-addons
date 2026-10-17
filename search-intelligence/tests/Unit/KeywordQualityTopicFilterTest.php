<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Queue;
use Omnichannel\Addons\SearchIntelligence\Jobs\ScanKeywordQualityJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordQuality\KeywordQualityService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\Contracts\TopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInput;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSiteKeywordService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityCode;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityTopicFilter;
use Tests\TestCase;

final class KeywordQualityTopicFilterTest extends TestCase
{
    private const SITE_A = 41;

    private const SITE_B = 42;

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
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_15_120000_create_seo_keyword_quality_tables.php')->up();
    }

    public function test_scan_summary_manual_hold_and_topic_filter(): void
    {
        $clean = $this->keyword('balo học sinh', self::SITE_A);
        $cta = $this->keyword('mua ngay', self::SITE_A);
        $copy = $this->keyword('balo học sinh', self::SITE_A);
        $other = $this->keyword('mua ngay', self::SITE_B);

        $service = app(KeywordQualityService::class);
        $service->scanPhrases(self::SITE_A, [
            $clean => 'balo học sinh',
            $cta => 'mua ngay',
            $copy => 'balo học sinh',
        ]);
        $service->scanPhrases(self::SITE_B, [$other => 'mua ngay']);

        self::assertSame([], $service->codesFor(self::SITE_A, $clean));
        self::assertContains(KeywordQualityCode::CTA_ANCHOR, $service->codesFor(self::SITE_A, $cta));
        self::assertContains(KeywordQualityCode::DUPLICATE_VARIANT, $service->codesFor(self::SITE_A, $copy));
        self::assertNotContains(KeywordQualityCode::DUPLICATE_VARIANT, $service->codesFor(self::SITE_A, $clean));

        $summary = $service->summary(self::SITE_A);
        self::assertSame(3, $summary['total']);
        self::assertSame(2, $summary['unique_tagged']);
        self::assertSame(0, $summary['unique_excluded']);
        $ctaRow = collect($summary['tags'])->firstWhere('code', KeywordQualityCode::CTA_ANCHOR);
        self::assertSame(1, $ctaRow['count']);
        self::assertSame(33.3, $ctaRow['percent']);
        $dictionary = app(KeywordDictionaryQuery::class);
        self::assertSame(
            $summary['unique_tagged'],
            $dictionary->filteredForSummary(self::SITE_A, null, ['quality_scope' => 'tagged'])->count(),
        );
        $duplicateCount = (int) collect($summary['tags'])->firstWhere('code', KeywordQualityCode::DUPLICATE_VARIANT)['count'];
        self::assertSame(
            $duplicateCount,
            $dictionary->filteredForSummary(self::SITE_A, null, [
                'quality_codes' => [KeywordQualityCode::DUPLICATE_VARIANT],
            ])->count(),
        );
        self::assertSame(
            0,
            $dictionary->filteredForSummary(self::SITE_A, null, [
                'quality_codes' => [KeywordQualityCode::ENCODING_ERROR],
            ])->count(),
        );
        $service->syncManual(self::SITE_A, $cta, [
            KeywordQualityCode::CTA_ANCHOR,
            KeywordQualityCode::MALFORMED_TEXT,
        ]);
        $withTwoTags = $service->summary(self::SITE_A);
        self::assertSame(2, $withTwoTags['unique_tagged']);
        self::assertSame(
            2,
            $dictionary->filteredForSummary(self::SITE_A, null, ['quality_scope' => 'tagged'])->count(),
        );
        $service->restoreAutomatic(self::SITE_A, $cta);

        $this->membership(self::SITE_A, 7, $clean);
        $this->membership(self::SITE_A, 7, $cta);
        $this->membership(self::SITE_B, 8, $other);
        $before = SeoTopicKeyword::query()->count();

        $counts = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_A, [7]);
        self::assertSame(2, $counts[7]);

        $service->setExcluded(self::SITE_A, KeywordQualityCode::CTA_ANCHOR, true);
        $counts = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_A, [7]);
        self::assertSame(1, $counts[7] ?? 0);
        self::assertSame($before, SeoTopicKeyword::query()->count());
        $siteB = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_B, [8]);
        self::assertSame(1, $siteB[8]);

        $candidates = app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A);
        $candidateIds = array_column($candidates, 'keyword_id');
        self::assertNotContains($cta, $candidateIds);
        self::assertContains($clean, $candidateIds);
        $seedRows = [
            ['keyword_id' => $cta, 'phrase' => 'mua ngay'],
            ['keyword_id' => $clean, 'phrase' => 'balo học sinh'],
        ];
        self::assertSame(
            [$clean],
            array_column(app(KeywordQualityTopicFilter::class)->withoutExcluded(self::SITE_A, $seedRows), 'keyword_id'),
        );
        $tagged = $dictionary->filteredForSummary(self::SITE_A, null, ['quality_scope' => 'tagged']);
        self::assertStringContainsString('seo_keyword_quality_tags', $tagged->toSql());
        self::assertSame($summary['unique_tagged'], $tagged->count());

        $service->setExcluded(self::SITE_A, KeywordQualityCode::CTA_ANCHOR, false);
        self::assertCount(2, app(KeywordQualityTopicFilter::class)->withoutExcluded(self::SITE_A, $seedRows));
        $counts = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_A, [7]);
        self::assertSame(2, $counts[7]);
        self::assertContains(
            $cta,
            array_column(app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A), 'keyword_id'),
        );

        $service->syncManual(self::SITE_A, $cta, []);
        $service->scanPhrases(self::SITE_A, [
            $clean => 'balo học sinh',
            $cta => 'mua ngay',
            $copy => 'balo học sinh',
        ]);
        self::assertSame([], $service->codesFor(self::SITE_A, $cta));
        self::assertSame($before, SeoTopicKeyword::query()->count());
    }

    public function test_proper_name_rows_are_removed_without_changing_topic_membership(): void
    {
        $id = $this->keyword('balo học sinh', self::SITE_A);
        $this->membership(self::SITE_A, 7, $id);
        $before = SeoTopicKeyword::query()->count();
        $now = now();
        $db = DB::connection('omi_seo_ai');
        $db->table('seo_keyword_quality_tags')->insert([
            ['site_id' => self::SITE_A, 'keyword_id' => $id, 'code' => 'SUSPECT_PROPER_NAME', 'origin' => 'auto', 'created_at' => $now, 'updated_at' => $now],
            ['site_id' => self::SITE_A, 'keyword_id' => $id, 'code' => KeywordQualityCode::MALFORMED_TEXT, 'origin' => 'manual', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $db->table('seo_keyword_quality_policies')->insert([
            ['site_id' => self::SITE_A, 'code' => 'SUSPECT_PROPER_NAME', 'exclude_from_topic' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['site_id' => self::SITE_A, 'code' => KeywordQualityCode::CTA_ANCHOR, 'exclude_from_topic' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $db->table('seo_keyword_quality_suppressions')->insert([
            ['site_id' => self::SITE_A, 'keyword_id' => $id, 'code' => 'SUSPECT_PROPER_NAME', 'created_at' => $now, 'updated_at' => $now],
            ['site_id' => self::SITE_A, 'keyword_id' => $id, 'code' => KeywordQualityCode::ENCODING_ERROR, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $service = app(KeywordQualityService::class);
        $service->scanPhrases(self::SITE_A, [$id => 'balo học sinh']);
        self::assertSame([KeywordQualityCode::MALFORMED_TEXT], $service->codesFor(self::SITE_A, $id));
        self::assertSame($before, SeoTopicKeyword::query()->count());

        $db->table('seo_keyword_quality_tags')->insert([
            'site_id' => self::SITE_A,
            'keyword_id' => $id,
            'code' => 'SUSPECT_PROPER_NAME',
            'origin' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_15_163000_remove_suspect_proper_name_quality_rows.php')->up();

        self::assertSame(0, $db->table('seo_keyword_quality_tags')->where('code', 'SUSPECT_PROPER_NAME')->count());
        self::assertSame(1, $db->table('seo_keyword_quality_tags')->where('code', KeywordQualityCode::MALFORMED_TEXT)->count());
        self::assertSame(0, $db->table('seo_keyword_quality_policies')->where('code', 'SUSPECT_PROPER_NAME')->count());
        self::assertSame(0, (int) $db->table('seo_keyword_quality_policies')->where('code', KeywordQualityCode::CTA_ANCHOR)->value('exclude_from_topic'));
        self::assertSame(0, $db->table('seo_keyword_quality_suppressions')->where('code', 'SUSPECT_PROPER_NAME')->count());
        self::assertSame(1, $db->table('seo_keyword_quality_suppressions')->where('code', KeywordQualityCode::ENCODING_ERROR)->count());
        self::assertSame($before, SeoTopicKeyword::query()->count());
    }

    public function test_note_scan_queued_does_not_change_tags_or_topic_rows(): void
    {
        $id = $this->keyword('balo học sinh', self::SITE_A);
        $this->membership(self::SITE_A, 7, $id);
        $before = SeoTopicKeyword::query()->count();
        $now = now();
        DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')->insert([
            'site_id' => self::SITE_A,
            'keyword_id' => $id,
            'code' => KeywordQualityCode::CTA_ANCHOR,
            'origin' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(KeywordQualityService::class)->noteScanQueued(self::SITE_A);

        $scan = DB::connection('omi_seo_ai')->table('seo_keyword_quality_scans')->where('site_id', self::SITE_A)->first();
        self::assertSame('queued', (string) $scan->status);
        self::assertSame(1, DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')->count());
        self::assertSame($before, SeoTopicKeyword::query()->count());
        self::assertSame(0, DB::connection('omi_seo_ai')->table('seo_keyword_quality_scans')->where('site_id', self::SITE_B)->count());
    }

    public function test_scan_progress_stays_active_until_terminal_and_unlocks_when_stale(): void
    {
        $service = app(KeywordQualityService::class);
        $service->noteScanQueued(self::SITE_A);
        $queued = $service->scanProgress(self::SITE_A);
        self::assertSame('queued', $queued['status']);
        self::assertTrue($queued['active']);
        self::assertFalse($queued['stale']);
        self::assertSame(0, $queued['processed']);

        $id = $this->keyword('balo học sinh', self::SITE_A);
        $service->scanPhrases(self::SITE_A, [$id => 'balo học sinh']);
        $done = $service->scanProgress(self::SITE_A);
        self::assertSame('done', $done['status']);
        self::assertFalse($done['active']);
        self::assertSame(1, $done['processed']);
        self::assertSame(1, $done['total']);
        self::assertFalse($service->summary(self::SITE_A)['scan_stale']);

        DB::connection('omi_seo_ai')->table('seo_keyword_quality_scans')->where('site_id', self::SITE_A)->update([
            'status' => 'running',
            'total' => 10,
            'processed' => 4,
            'last_error' => null,
            'updated_at' => now()->subSeconds(30),
        ]);
        $running = $service->scanProgress(self::SITE_A);
        self::assertTrue($running['active']);
        self::assertSame(4, $running['processed']);
        self::assertSame(10, $running['total']);

        DB::connection('omi_seo_ai')->table('seo_keyword_quality_scans')->where('site_id', self::SITE_A)->update([
            'updated_at' => now()->subSeconds(KeywordQualityService::SCAN_STALE_SECONDS + 5),
        ]);
        $stale = $service->scanProgress(self::SITE_A);
        self::assertSame('running', $stale['status']);
        self::assertTrue($stale['stale']);
        self::assertFalse($stale['active']);
        self::assertTrue($service->summary(self::SITE_A)['scan_stale']);

        DB::connection('omi_seo_ai')->table('seo_keyword_quality_scans')->where('site_id', self::SITE_A)->update([
            'status' => 'failed',
            'last_error' => 'boom',
            'updated_at' => now(),
        ]);
        $failed = $service->scanProgress(self::SITE_A);
        self::assertSame('failed', $failed['status']);
        self::assertFalse($failed['active']);
        self::assertFalse($failed['stale']);
        self::assertSame('boom', $failed['error']);
    }

    public function test_stale_unique_lock_can_be_released_for_retry(): void
    {
        Queue::fake();
        $job = new ScanKeywordQualityJob(self::SITE_A);
        $lock = app(UniqueLock::class);
        self::assertTrue($lock->acquire($job));
        self::assertFalse($lock->acquire($job));

        ScanKeywordQualityJob::releaseLocks(self::SITE_A);
        ScanKeywordQualityJob::dispatch(self::SITE_A);

        Queue::assertPushed(ScanKeywordQualityJob::class, 1);
        ScanKeywordQualityJob::dispatch(self::SITE_A);
        Queue::assertPushed(ScanKeywordQualityJob::class, 1);
        self::assertFalse($lock->acquire(new ScanKeywordQualityJob(self::SITE_A)));
    }

    public function test_policy_gates_manual_tags_reads_and_recluster_without_deleting_rows(): void
    {
        $clean = $this->keyword('balo học sinh', self::SITE_A);
        $manual = $this->keyword('câu này quá dài để là keyword', self::SITE_A);
        $multi = $this->keyword('mua ngay hôm nay', self::SITE_A);
        $kept = $this->keyword('túi xách', self::SITE_B);
        $service = app(KeywordQualityService::class);
        $service->syncManual(self::SITE_A, $manual, [KeywordQualityCode::SENTENCE_LIKE]);
        $service->syncManual(self::SITE_A, $multi, [
            KeywordQualityCode::SENTENCE_LIKE,
            KeywordQualityCode::CTA_ANCHOR,
        ]);
        $service->setExcluded(self::SITE_A, KeywordQualityCode::CTA_ANCHOR, false);
        $service->setExcluded(self::SITE_A, KeywordQualityCode::SENTENCE_LIKE, false);

        self::assertContains($manual, array_column(
            app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A),
            'keyword_id',
        ));

        $service->setExcluded(self::SITE_A, KeywordQualityCode::CTA_ANCHOR, true);
        $excluded = app(KeywordQualityTopicFilter::class)->excludedIdSet(self::SITE_A);
        self::assertArrayHasKey($multi, $excluded);
        self::assertArrayNotHasKey($manual, $excluded);
        self::assertArrayNotHasKey($clean, $excluded);

        $service->setExcluded(self::SITE_A, KeywordQualityCode::SENTENCE_LIKE, true);
        $this->membership(self::SITE_A, 7, $manual);
        $this->membership(self::SITE_B, 8, $kept);
        $topicRows = SeoTopicKeyword::query()->count();
        $keywordRows = Keyword::query()->count();
        $articleRows = DB::connection('omi_seo_ai')->table('articles')->count();

        self::assertSame([], app(TopicMembershipQuery::class)->topicIdsByKeywordId(self::SITE_A, [$manual]));
        self::assertNull(app(TopicMembershipQuery::class)->topicForKeyword(self::SITE_A, $manual));
        self::assertSame(8, app(TopicMembershipQuery::class)->topicIdsByKeywordId(self::SITE_B, [$kept])[$kept]);
        self::assertNotContains($manual, array_column(
            app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A),
            'keyword_id',
        ));
        self::assertSame(0, app(KeywordDictionaryQuery::class)->filtered(self::SITE_A, null, [
            'topic_assignment' => 'assigned',
        ])->whereKey($manual)->count());

        $service->scanPhrases(self::SITE_A, [
            $clean => 'balo học sinh',
            $manual => 'câu này quá dài để là keyword',
            $multi => 'mua ngay hôm nay',
        ]);
        self::assertSame(1, (int) DB::connection('omi_seo_ai')->table('seo_keyword_quality_policies')
            ->where('site_id', self::SITE_A)
            ->where('code', KeywordQualityCode::SENTENCE_LIKE)
            ->value('exclude_from_topic'));
        self::assertSame(1, (int) DB::connection('omi_seo_ai')->table('seo_keyword_quality_policies')
            ->where('site_id', self::SITE_A)
            ->where('code', KeywordQualityCode::CTA_ANCHOR)
            ->value('exclude_from_topic'));

        $schema = Schema::connection('omi_seo_ai');
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source')->default('auto');
            $table->string('status')->default('active');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
        $schema->create('seo_site_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('keyword_id');
            $table->boolean('is_seo_keyword')->default(true);
        });
        DB::connection('omi_seo_ai')->table('seo_topics')->insert([
            ['id' => 7, 'site_id' => self::SITE_A, 'name' => 'Balo', 'source' => 'auto', 'status' => 'active', 'is_locked' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'site_id' => self::SITE_A, 'name' => 'Khác', 'source' => 'auto', 'status' => 'active', 'is_locked' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->app->bind(TopicGroupingProvider::class, static fn (): TopicGroupingProvider => new class implements TopicGroupingProvider {
            public function analyze(TopicGroupingInput $input): TopicGroupingProposal
            {
                throw new \RuntimeException('unused');
            }
        });
        app(TopicReclusterService::class)->persistResolvedClusters(self::SITE_A, [[
            'name' => 'Khác',
            'topic_id' => 9,
            'is_locked' => false,
            'members' => [
                ['keyword_id' => $clean, 'phrase' => 'balo học sinh', 'source' => 'recluster', 'is_seed' => false, 'confidence' => 0.8, 'is_locked' => false],
                ['keyword_id' => $manual, 'phrase' => 'câu này quá dài để là keyword', 'source' => 'recluster', 'is_seed' => false, 'confidence' => 0.8, 'is_locked' => false],
                ['keyword_id' => $multi, 'phrase' => 'mua ngay hôm nay', 'source' => 'recluster', 'is_seed' => false, 'confidence' => 0.8, 'is_locked' => false],
            ],
        ]], false, [
            'locked_topic_ids' => [],
            'preserved_topic_ids' => [],
            'locked_keyword_ids' => [],
            'locked_memberships_by_topic' => [],
            'topics' => [],
        ], [], []);

        self::assertSame(7, (int) SeoTopicKeyword::query()->where('keyword_id', $manual)->value('topic_id'));
        self::assertNull(SeoTopicKeyword::query()->where('keyword_id', $multi)->value('topic_id'));
        self::assertSame(9, (int) SeoTopicKeyword::query()->where('keyword_id', $clean)->value('topic_id'));
        self::assertSame($topicRows + 1, SeoTopicKeyword::query()->count());
        self::assertSame($keywordRows, Keyword::query()->count());
        self::assertSame($articleRows, DB::connection('omi_seo_ai')->table('articles')->count());
        self::assertSame(2, DB::connection('omi_seo_ai')->table('seo_topics')->count());

        $service->setExcluded(self::SITE_A, KeywordQualityCode::SENTENCE_LIKE, false);
        $service->setExcluded(self::SITE_A, KeywordQualityCode::CTA_ANCHOR, false);
        self::assertContains($manual, array_column(
            app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A),
            'keyword_id',
        ));
        self::assertSame(7, app(TopicMembershipQuery::class)->topicIdsByKeywordId(self::SITE_A, [$manual])[$manual]);
    }

    private function keyword(string $phrase, int $siteId): int
    {
        $id = (int) Keyword::query()->create([
            'phrase' => $phrase,
            'type' => 'normal',
            'review_status' => 'active',
        ])->id;
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => 1000 + $id,
            'site_id' => $siteId,
            'title' => 'Article '.$id,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $id,
            'meta_key' => KeywordMetaKey::siteMainArticleId($siteId),
            'meta_value' => (string) (1000 + $id),
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $id,
            'source_article_id' => 1000 + $id,
            'status' => 'active',
        ]);

        return $id;
    }

    private function membership(int $siteId, int $topicId, int $keywordId): void
    {
        SeoTopicKeyword::query()->create([
            'site_id' => $siteId,
            'topic_id' => $topicId,
            'keyword_id' => $keywordId,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);
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
            $table->string('language')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        $schema->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->string('status')->nullable();
        });
        $schema->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source')->default('auto');
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->float('confidence')->nullable();
            $table->timestamps();
        });
    }
}
