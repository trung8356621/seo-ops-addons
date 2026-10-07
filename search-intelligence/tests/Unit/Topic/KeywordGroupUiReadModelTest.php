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
