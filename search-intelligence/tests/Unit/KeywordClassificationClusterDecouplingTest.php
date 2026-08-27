<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class KeywordClassificationClusterDecouplingTest extends TestCase
{
    private const SITE_ID = 10;

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
        $this->ensureCoreTables();
    }

    public function test_new_classification_row_keeps_cluster_key_null_after_classify(): void
    {
        $keyword = $this->seedKeyword('túi canvas giá rẻ');

        $this->service()->classifyBatch(999, 10, false, true);

        $clusterKey = SeoKeywordClassification::query()->whereKey($keyword->id)->value('cluster_key');
        self::assertNull($clusterKey);
    }

    public function test_existing_cluster_key_is_preserved_on_reclassify(): void
    {
        $keyword = $this->seedKeyword('túi vải canvas');
        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => 'túi vải canvas',
            'folded_text' => 'tui vai canvas',
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'commercial',
            'cluster_key' => 'manual_cluster_key',
            'is_seo_keyword' => true,
            'classified_at' => now()->subDay(),
            'is_dirty' => true,
        ]);

        $this->service()->classifyBatch(999, 10, false, true);

        self::assertSame('manual_cluster_key', (string) SeoKeywordClassification::query()->whereKey($keyword->id)->value('cluster_key'));
    }

    public function test_dissolved_null_cluster_key_stays_null_after_reclassify(): void
    {
        $keyword = $this->seedKeyword('túi dây rút');
        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => 'túi dây rút',
            'folded_text' => 'tui day rut',
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'commercial',
            'cluster_key' => null,
            'is_seo_keyword' => true,
            'classified_at' => now()->subDay(),
            'is_dirty' => true,
        ]);

        $this->service()->classifyBatch(999, 10, false, true);

        self::assertNull(SeoKeywordClassification::query()->whereKey($keyword->id)->value('cluster_key'));
    }

    private function service(): KeywordClassificationService
    {
        return app(KeywordClassificationService::class);
    }

    private function seedKeyword(string $phrase): Keyword
    {
        $norm = app(KeywordNormalizer::class)->normalize($phrase);
        $articleId = $this->createArticle(self::SITE_ID, 'Article '.$phrase);
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ]);
        $this->createLinkMap((int) $keyword->id, $articleId, $articleId);

        return $keyword;
    }

    private function createArticle(int $siteId, string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLinkMap(int $keywordId, int $sourceArticleId, ?int $targetArticleId): int
    {
        return (int) DB::connection('omi_seo_ai')->table('seo_link_maps')->insertGetId([
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
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
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
            $table->string('raw_text')->nullable();
            $table->string('normalized_text')->nullable();
            $table->string('folded_text')->nullable();
            $table->string('phrase_kind')->nullable();
            $table->string('seo_intent')->nullable();
            $table->string('cluster_key')->nullable()->index();
            $table->boolean('is_seo_keyword')->nullable();
            $table->boolean('is_ambiguous')->nullable();
            $table->boolean('is_dirty')->nullable();
            $table->string('input_hash')->nullable();
            $table->string('classification_hash')->nullable();
            $table->unsignedBigInteger('canonical_keyword_id')->nullable();
            $table->boolean('is_anchor_candidate')->nullable();
            $table->integer('anchor_priority')->nullable();
            $table->float('classification_confidence')->nullable();
            $table->float('keyword_score')->nullable();
            $table->json('segments')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });

    }

    private function ensureCoreTables(): void
    {
        if (! Schema::hasTable('sites')) {
            Schema::create('sites', function (Blueprint $table): void {
                $table->id();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('article_meta')) {
            Schema::connection('omi_seo_ai')->create('article_meta', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id')->index();
                $table->string('meta_key');
                $table->text('meta_value')->nullable();
            });
        }
    }
}
