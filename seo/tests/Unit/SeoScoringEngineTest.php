<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoScoringEngine;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoScoringEngineTest extends TestCase
{
    public function test_h2_missing_violation(): void
    {
        $engine = app(SeoScoringEngine::class);

        $violations = $engine->analyzeViolations(
            '<p>Chưa có heading</p>',
            'keyword',
            [],
            [
                'seo_title' => 'keyword',
                'meta_description' => 'keyword',
                'slug' => 'keyword',
            ],
        );

        $this->assertContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $violations);
    }

    public function test_two_h2_tags_pass_heading_rule(): void
    {
        $engine = app(SeoScoringEngine::class);

        $violations = $engine->analyzeViolations(
            '<h2>Một</h2><h2>Hai</h2><p>Nội dung đủ dài</p>',
            'keyword',
            [['question' => 'Q?', 'answer' => 'A.']],
            [
                'seo_title' => 'keyword',
                'meta_description' => 'keyword',
                'slug' => 'keyword',
                'article_length_target' => 5,
            ],
        );

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $violations);
    }

    public function test_missing_focus_keyword_violation(): void
    {
        $engine = app(SeoScoringEngine::class);

        $violations = $engine->analyzeViolations('<p>Test</p>', '', []);

        $this->assertSame([SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD], $violations);
    }

    public function test_rough_faq_passes_on_matching_heading_without_pairs(): void
    {
        $violations = app(SeoScoringEngine::class)->analyzeViolations(
            '<h2>Câu hỏi thường gặp</h2><h2>Nội dung</h2><p>keyword</p>',
            'keyword',
            [],
            ['seo_title' => 'keyword', 'meta_description' => 'keyword', 'slug' => 'keyword', 'article_length_target' => 1],
        );

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_FAQ_MISSING, $violations);
    }

    public function test_rough_faq_passes_from_canonical_rows_and_fails_without_rows_or_matching_heading(): void
    {
        $engine = app(SeoScoringEngine::class);
        $context = ['seo_title' => 'keyword', 'meta_description' => 'keyword', 'slug' => 'keyword', 'article_length_target' => 1];
        $html = '<h2>Giới thiệu</h2><h2>Nội dung</h2><p>keyword</p>';

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_FAQ_MISSING, $engine->analyzeViolations(
            $html, 'keyword', [['question' => 'Q?', 'answer' => 'A']], $context,
        ));
        $this->assertContains(SeoScoringRulesRegistry::KEY_FAQ_MISSING, $engine->analyzeViolations(
            $html, 'keyword', [], $context,
        ));
    }

    public function test_rough_featured_snippet_is_binary_table_presence(): void
    {
        $engine = app(SeoScoringEngine::class);
        $context = ['seo_title' => 'keyword', 'meta_description' => 'keyword', 'slug' => 'keyword', 'article_length_target' => 1];
        $base = '<h2>Câu hỏi thường gặp</h2><h2>Nội dung</h2><p>keyword</p>';
        $withTable = $engine->analyzeViolations($base.'<table><tr><td>A</td></tr></table>', 'keyword', [], $context);
        $withoutTable = $engine->analyzeViolations($base, 'keyword', [], $context);

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING, $withTable);
        $this->assertNotContains(SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_GOOD, $withTable);
        $this->assertNotContains(SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT, $withTable);
        $this->assertContains(SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING, $withoutTable);
    }
}
