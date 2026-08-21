<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Tests\TestCase;

final class KeywordClusterHubSemanticsTest extends TestCase
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
    }

    public function test_hub_unclustered_matches_proposal_candidates(): void
    {
        $this->seedKeyword('unclassified one');
        $this->seedKeyword('unclassified two');
        $this->seedKeyword('noise phrase', isSeo: false, phraseKind: 'noise');
        $this->seedKeyword('descriptive only', isSeo: false, phraseKind: 'descriptive_phrase');
        $this->seedKeyword('clustered seo', clusterKey: 'cluster_a', isSeo: true);
        $this->seedKeyword('clustered seo two', clusterKey: 'cluster_a', isSeo: true);
        $this->seedKeyword('candidate one', isSeo: true);
        $this->seedKeyword('candidate two', isSeo: true);
        $this->seedKeyword('candidate three', isSeo: true);
        $this->seedKeyword('candidate four', isSeo: true);

        $summary = app(KeywordClusterQuery::class)->summary(self::SITE_ID);
        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_ID, 'balanced');

        self::assertSame(10, $summary['total_keywords']);
        self::assertSame(8, $summary['classified_keywords']);
        self::assertSame(2, $summary['non_seo_keywords']);
        self::assertSame(2, $summary['clustered']);
        self::assertSame(4, $summary['unclustered']);
        self::assertSame(2, $summary['unclassified_keywords']);
        self::assertSame(6, $summary['seo_eligible_keywords']);
        self::assertSame(4, $summary['unclustered']);
        self::assertSame(4, $preview->candidateCount);
        self::assertSame($summary['unclustered'], $preview->candidateCount);
    }

    public function test_metrics_do_not_double_count(): void
    {
        $this->seedKeyword('seo clustered', clusterKey: 'x', isSeo: true);
        $this->seedKeyword('seo open', isSeo: true);
        $this->seedKeyword('non seo', isSeo: false, phraseKind: 'sentence');

        $metrics = app(KeywordClusterEligibility::class)->summaryMetrics(self::SITE_ID);

        self::assertSame(3, $metrics['classified_keywords']);
        self::assertSame(2, $metrics['seo_eligible_keywords']);
        self::assertSame(1, $metrics['non_seo_keywords']);
        self::assertSame(1, $metrics['clustered']);
        self::assertSame(1, $metrics['unclustered']);
        self::assertSame($metrics['seo_eligible_keywords'], $metrics['clustered'] + $metrics['unclustered']);
        self::assertSame($metrics['classified_keywords'], $metrics['seo_eligible_keywords'] + $metrics['non_seo_keywords']);
    }

    public function test_cta_phrases_are_not_seo_candidates(): void
    {
        $classifier = new KeywordRuleClassifier();
        foreach ([
            'Nhận tư vấn miễn phí',
            'Liên hệ ngay',
            'Đăng ký nhận báo giá',
            'Xem thêm',
        ] as $phrase) {
            $result = $classifier->classify($phrase, mb_strtolower($phrase, 'UTF-8'));
            self::assertFalse($result['is_seo_keyword'], $phrase);
        }
    }

    public function test_commercial_seo_phrases_remain_eligible(): void
    {
        $classifier = new KeywordRuleClassifier();
        foreach ([
            'báo giá túi canvas',
            'giá túi canvas',
            'xưởng may túi canvas',
            'mua túi canvas',
        ] as $phrase) {
            $result = $classifier->classify($phrase, mb_strtolower($phrase, 'UTF-8'));
            self::assertTrue($result['is_seo_keyword'], $phrase);
        }
    }

    public function test_anchor_text_product_phrase_can_remain_seo(): void
    {
        $result = (new KeywordRuleClassifier())->classify(
            'túi vải canvas',
            'túi vải canvas',
            [
                'source_kind' => KeywordSourceNormalizer::ANCHOR_TEXT,
                'occurrence_count' => 8,
                'source_post_count' => 4,
            ],
        );

        self::assertTrue($result['is_seo_keyword']);
    }

    private function seedKeyword(
        string $phrase,
        ?string $clusterKey = null,
        bool $isSeo = true,
        string $phraseKind = 'keyword_phrase',
    ): Keyword {
        $articleId = $this->createArticle(self::SITE_ID, 'Article '.$phrase);
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ]);
        $this->createLinkMap((int) $keyword->id, $articleId, $articleId);

        if (str_starts_with($phrase, 'unclassified')) {
            return $keyword;
        }

        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => mb_strtolower($phrase, 'UTF-8'),
            'folded_text' => mb_strtolower($phrase, 'UTF-8'),
            'phrase_kind' => $phraseKind,
            'seo_intent' => 'commercial',
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => $isSeo,
            'classified_at' => now(),
        ]);

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
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title')->nullable();
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
    }
}
