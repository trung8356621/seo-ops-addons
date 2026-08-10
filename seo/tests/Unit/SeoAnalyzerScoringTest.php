<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use App\Services\SeoEngineService;
use Tests\TestCase;

final class SeoAnalyzerScoringTest extends TestCase
{
    public function test_engine_heading_rule_requires_two_h2_tags(): void
    {
        $engine = app(SeoEngineService::class);

        $none = $engine->analyzeHtml('<p>Chưa có heading</p>', 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);
        $one = $engine->analyzeHtml('<h2>Một</h2><p>Nội dung</p>', 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);
        $two = $engine->analyzeHtml('<h2>Một</h2><h2>Hai</h2><p>Nội dung</p>', 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $none['violations']);
        $this->assertContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $one['violations']);
        $this->assertNotContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $two['violations']);
    }

    public function test_engine_wiki_trust_external_link_detection(): void
    {
        $engine = app(SeoEngineService::class);

        $trusted = $engine->analyzeHtml(
            '<p>Test</p><a href="https://en.wikipedia.org/wiki/Test">Wiki</a>',
            'keyword',
            [],
            ['seo_title' => 'keyword', 'meta_description' => 'keyword', 'slug' => 'keyword'],
        );
        $regular = $engine->analyzeHtml(
            '<p>Test</p><a href="https://example.com/page">Example</a>',
            'keyword',
            [],
            ['seo_title' => 'keyword', 'meta_description' => 'keyword', 'slug' => 'keyword'],
        );

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_WIKI_TRUST_MISSING, $trusted['violations']);
        $this->assertContains(SeoScoringRulesRegistry::KEY_WIKI_TRUST_MISSING, $regular['violations']);
    }

    public function test_custom_wiki_trust_domain_from_settings(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('wp_options')) {
            $this->markTestSkipped('wp_options table is not available in this test database.');
        }

        app(ArticleEditorHistoryService::class)->saveSettings([
            'wiki_trust_domains' => ['vnexpress.net', '*.gov'],
        ]);

        $this->assertTrue(SeoLinkMapLinkTypeClassifier::isWikiTrustHost('vnexpress.net'));
        $this->assertSame(
            SeoLinkMapType::WikiTrust,
            SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://vnexpress.net/bai-viet'),
        );
    }

    public function test_persist_client_analysis_persists_violations(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('articles')) {
                $this->markTestSkipped('omi_seo_ai articles table is not available in this test database.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('omi_seo_ai connection is not available in this test database.');
        }

        $article = SeoArticle::query()->create([
            'site_id' => 1,
            'title' => 'keyword heading content',
            'slug' => 'keyword',
            'type' => 'post',
            'status' => 'draft',
            'body' => '<h2>keyword one</h2><h2>keyword two</h2><p>keyword content with enough words for scoring path.</p>',
        ]);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => 'keyword'],
        );

        // Client violations are ignored — PHP engine is SoT.
        $result = app(SeoAnalyzerService::class)->persistClientAnalysis(
            $article->fresh(),
            (string) $article->body,
            [
                'violations' => ['h2_missing', 'faq_missing'],
                'extracted_links' => [
                    'internal' => [],
                    'external' => [],
                ],
            ],
        );

        self::assertNotContains('h2_missing', $result['violations']);
        self::assertArrayHasKey('score_version', $result);
        self::assertSame(SeoScoringRulesRegistry::SCORE_VERSION, $result['score_version']);
        self::assertArrayHasKey('content_hash', $result);

        $meta = $article->fresh()?->articleMetas()->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS)->first();
        self::assertNotNull($meta);
        $stored = json_decode((string) $meta->meta_value, true);
        self::assertIsArray($stored);
        self::assertNotContains('h2_missing', $stored);
    }
}
