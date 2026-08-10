<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use App\Services\SeoEngineService;
use Tests\TestCase;

final class SeoEngineServiceTest extends TestCase
{
    public function test_passing_heading_rule_has_no_h2_violation(): void
    {
        $engine = app(SeoEngineService::class);

        $html = '<h2>One</h2><h2>Two</h2><p>Focus keyword sample content here.</p>';
        $result = $engine->analyzeHtml(
            $html,
            'focus keyword',
            [],
            [
                'seo_title' => 'Focus keyword title',
                'meta_description' => 'Focus keyword description',
                'slug' => 'focus-keyword-sample',
            ],
        );

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $result['violations']);
    }

    public function test_length_scoring_uses_article_length_target(): void
    {
        $engine = app(SeoEngineService::class);
        $words900 = implode(' ', array_fill(0, 900, 'word'));
        $words1100 = implode(' ', array_fill(0, 1100, 'word'));

        $belowTarget = $engine->analyzeHtml("<p>{$words900}</p>", 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
            'article_length_target' => 1000,
        ]);
        $meetsTarget = $engine->analyzeHtml("<p>{$words1100}</p>", 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
            'article_length_target' => 1000,
        ]);

        $this->assertContains(SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW, $belowTarget['violations']);
        $this->assertNotContains(SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW, $meetsTarget['violations']);
    }

    public function test_missing_focus_keyword_scores_zero(): void
    {
        $engine = app(SeoEngineService::class);

        $result = $engine->analyzeHtml('<p>Test</p>', '', []);

        $this->assertSame(0, $result['score']);
        $this->assertContains(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $result['violations']);
    }

    public function test_faq_schema_violation_when_missing(): void
    {
        $engine = app(SeoEngineService::class);

        $result = $engine->analyzeHtml(
            '<h2>A</h2><h2>B</h2><p>keyword body</p>',
            'keyword',
            [],
            [
                'seo_title' => 'keyword',
                'meta_description' => 'keyword',
                'slug' => 'keyword',
            ],
        );

        $this->assertContains(SeoScoringRulesRegistry::KEY_FAQ_MISSING, $result['violations']);
        $this->assertArrayNotHasKey('featured_snippet', $result['breakdown'] ?? []);
    }
}
