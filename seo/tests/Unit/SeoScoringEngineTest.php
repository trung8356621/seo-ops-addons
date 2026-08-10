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
}
