<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use InvalidArgumentException;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupManualService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupReadModel;
use Tests\TestCase;

final class KeywordGroupUiReadModelTest extends TestCase
{
    private const SITE = 9;

    private const OTHER_SITE = 10;

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
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php';
        $migration->up();
    }

    public function test_unassigned_count_and_search_respect_assignment_site_and_language(): void
    {
        $viArticle = $this->createArticle(self::SITE, 'vi', 'VI');
        $enArticle = $this->createArticle(self::SITE, 'en', 'EN');
        $otherArticle = $this->createArticle(self::OTHER_SITE, 'vi', 'Other');

        $assigned = $this->createInventoryKeyword('balo học sinh', $viArticle);
        $unassignedVi = $this->createInventoryKeyword('cặp học sinh', $viArticle);
        $unassignedVi2 = $this->createInventoryKeyword('xưởng may balo', $viArticle);
        $enOnly = $this->createInventoryKeyword('school bag', $enArticle);
        $otherSite = $this->createInventoryKeyword('balo khác site', $otherArticle);

        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $assigned,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => $assigned,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);

        $reader = app(KeywordGroupReadModel::class);
        self::assertSame(3, $reader->unassignedCount(self::SITE, null));
        self::assertSame(2, $reader->unassignedCount(self::SITE, ['vi']));
        self::assertSame(1, $reader->unassignedCount(self::SITE, ['en']));

        $hits = $reader->searchUnassigned(self::SITE, 'học', ['vi']);
        $ids = array_column($hits, 'keyword_id');
        self::assertContains($unassignedVi, $ids);
        self::assertNotContains($assigned, $ids);
        self::assertNotContains($enOnly, $ids);
        self::assertNotContains($otherSite, $ids);

        $page = $reader->paginateGroups(self::SITE, 1, 20);
        self::assertArrayHasKey('members', $page->items()[0]);
        self::assertCount(1, $page->items()[0]['members']);
        self::assertSame(1, (int) $page->items()[0]['member_count']);

        $viPage = $reader->paginateGroups(self::SITE, 1, 20, ['vi']);
        self::assertCount(1, $viPage->items());
        self::assertSame(1, (int) $viPage->items()[0]['member_count']);
        self::assertSame([], $reader->paginateGroups(self::SITE, 1, 20, ['en'])->items());
        self::assertSame(1, $reader->groupCount(self::SITE, ['vi']));
        self::assertSame(0, $reader->groupCount(self::SITE, ['en']));

        $byName = $reader->paginateGroups(self::SITE, 1, 20, ['vi'], 'vi', true, 'balo');
        self::assertCount(1, $byName->items());
        self::assertSame((int) $group->id, (int) $byName->items()[0]['id']);
        self::assertSame([], $reader->paginateGroups(self::SITE, 1, 20, ['vi'], 'vi', true, 'không khớp')->items());

        // Search must change the visible page set (not only fire a Livewire noop).
        $other = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'túi xách da',
            'source' => KeywordGroupSource::MANUAL,
            'representative_keyword_id' => null,
            'is_locked' => false,
        ]);
        $unfilteredFirst = (int) ($reader->paginateGroups(self::SITE, 1, 20, ['vi'], 'vi', true)->items()[0]['id'] ?? 0);
        $filteredFirst = (int) ($byName->items()[0]['id'] ?? 0);
        self::assertNotSame((int) $other->id, $filteredFirst);
        self::assertContains($unfilteredFirst, [(int) $group->id, (int) $other->id]);
        self::assertSame((int) $group->id, $filteredFirst);

        app(KeywordGroupManualService::class)->assignKeyword(self::SITE, $unassignedVi, (int) $group->id);
        self::assertSame(1, $reader->unassignedCount(self::SITE, ['vi']));
        self::assertSame([], $reader->searchUnassigned(self::SITE, 'cặp', ['vi']));

        app(KeywordGroupManualService::class)->assignKeyword(self::SITE, $unassignedVi, null);
        self::assertSame(2, $reader->unassignedCount(self::SITE, ['vi']));
        self::assertSame($unassignedVi, $reader->searchUnassigned(self::SITE, 'cặp', ['vi'])[0]['keyword_id'] ?? 0);

        $chunk = $reader->groupMembers(self::SITE, (int) $group->id, 50, 0);
        self::assertSame(1, $chunk['total']);
        self::assertSame($assigned, $chunk['members'][0]['keyword_id']);
        self::assertGreaterThan(0, $unassignedVi2);
    }

    public function test_semantic_groups_stay_on_their_analysis_language_when_keywords_overlap(): void
    {
        $runs = require dirname(__DIR__, 3).'/database/migrations/2026_10_17_120000_create_seo_keyword_group_analysis_runs.php';
        $runs->up();

        $viArticle = $this->createArticle(self::SITE, 'vi', 'VI');
        $enArticle = $this->createArticle(self::SITE, 'en', 'EN');
        $shared = $this->createInventoryKeyword('balo học sinh', $viArticle);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $shared,
            'source_article_id' => $enArticle,
            'target_article_id' => $enArticle,
            'anchor_text' => 'balo học sinh',
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enOnly = $this->createInventoryKeyword('school bag', $enArticle);
        $viOnly = $this->createInventoryKeyword('cặp học sinh', $viArticle);

        $semantic = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => $shared,
            'input_hash' => 'vi-hash',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $semantic->id,
            'keyword_id' => $shared,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        $manual = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Tay',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => true,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $manual->id,
            'keyword_id' => $viOnly,
            'source' => KeywordGroupSource::MANUAL,
        ]);
        SeoKeywordGroupAnalysisRun::query()->create([
            'site_id' => self::SITE,
            'run_id' => 'vi-run',
            'language' => 'vi',
            'dataset_revision' => 1,
            'dataset_hash' => 'vi-hash',
            'fingerprint' => 'fp',
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'phase' => 'finalized',
            'group_count' => 1,
            'member_count' => 1,
        ]);

        $reader = app(KeywordGroupReadModel::class);
        self::assertSame([], $reader->paginateGroups(self::SITE, 1, 20, ['en'], 'en', false)->items());
        self::assertSame(0, $reader->groupCount(self::SITE, ['en'], 'en', false));
        self::assertSame(0, $reader->groupMembers(self::SITE, (int) $semantic->id, 50, 0, ['en'], 'en', false)['total']);
        self::assertSame(2, $reader->unassignedCount(self::SITE, ['en'], 'en', false));
        self::assertSame($enOnly, $reader->searchUnassigned(self::SITE, 'school', ['en'], 20, 'en', false)[0]['keyword_id'] ?? 0);

        $viPage = $reader->paginateGroups(self::SITE, 1, 20, ['vi'], 'vi', true);
        $byName = [];
        foreach ($viPage->items() as $row) {
            $byName[$row['name']] = $row;
        }
        self::assertArrayHasKey('Balo', $byName);
        self::assertArrayHasKey('Tay', $byName);
        self::assertSame('balo học sinh', $byName['Balo']['representative_phrase']);
        self::assertSame(1, (int) $byName['Balo']['member_count']);
        self::assertSame($shared, $byName['Balo']['members'][0]['keyword_id']);
        self::assertSame(2, $reader->groupCount(self::SITE, ['vi'], 'vi', true));

        self::assertSame(KeywordGroupSource::SEMANTIC, SeoKeywordGroup::query()->whereKey($semantic->id)->value('source'));
        self::assertSame('vi-hash', SeoKeywordGroup::query()->whereKey($semantic->id)->value('input_hash'));
        self::assertSame($shared, (int) SeoKeywordGroupKeyword::query()->where('group_id', $semantic->id)->value('keyword_id'));
        self::assertSame(KeywordGroupSource::MANUAL, SeoKeywordGroup::query()->whereKey($manual->id)->value('source'));
        self::assertTrue((bool) SeoKeywordGroup::query()->whereKey($manual->id)->value('is_locked'));
        self::assertSame($viOnly, (int) SeoKeywordGroupKeyword::query()->where('group_id', $manual->id)->value('keyword_id'));

        try {
            app(KeywordGroupManualService::class)->assignKeyword(
                self::SITE,
                $shared,
                (int) $manual->id,
                'en',
                ['en'],
                false,
            );
            self::fail('English assignment must not move a Vietnamese semantic member.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('keyword_group_language_mismatch', $e->getMessage());
        }
        self::assertSame((int) $semantic->id, (int) SeoKeywordGroupKeyword::query()->where('keyword_id', $shared)->value('group_id'));
    }

    public function test_member_show_more_limit(): void
    {
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Big',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        for ($i = 1; $i <= 55; $i++) {
            SeoKeywordGroupKeyword::query()->create([
                'site_id' => self::SITE,
                'group_id' => $group->id,
                'keyword_id' => $i,
                'source' => KeywordGroupSource::MANUAL,
            ]);
            DB::connection('omi_seo_ai')->table('keywords')->insert([
                'id' => $i,
                'phrase' => 'keyword '.$i,
                'type' => Keyword::TYPE_NORMAL,
                'review_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $first = app(KeywordGroupReadModel::class)->groupMembers(self::SITE, (int) $group->id, 50, 0);
        self::assertCount(50, $first['members']);
        self::assertTrue($first['has_more']);
        self::assertSame(55, $first['total']);
        $next = app(KeywordGroupReadModel::class)->groupMembers(self::SITE, (int) $group->id, 50, 50);
        self::assertCount(5, $next['members']);
        self::assertFalse($next['has_more']);
    }

    private function createInventoryKeyword(string $phrase, int $sourceArticleId): int
    {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => 'active',
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => (int) $keyword->id,
            'source_article_id' => $sourceArticleId,
            'target_article_id' => $sourceArticleId,
            'anchor_text' => $phrase,
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $keyword->id;
    }

    private function createArticle(int $siteId, string $language, string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
            'title' => $title,
            'language' => $language,
            'created_at' => now(),
            'updated_at' => now(),
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
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        $schema->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('source_article_id')->index();
            $table->unsignedBigInteger('target_article_id')->nullable()->index();
            $table->text('anchor_text');
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->timestamps();
        });
    }
}
