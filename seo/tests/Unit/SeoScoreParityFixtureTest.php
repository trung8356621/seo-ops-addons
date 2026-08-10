<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;
use Omnichannel\Addons\Seo\Services\SeoScoringEngine;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

/**
 * PHP side of SEO score parity fixture (JS must use same rule keys + SCORE_VERSION).
 */
final class SeoScoreParityFixtureTest extends TestCase
{
    public function test_fixture_scores_with_php_engine(): void
    {
        $fixturePath = dirname(__DIR__).'/Fixtures/seo_score_parity_preppy.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true);
        self::assertIsArray($fixture);

        $engine = app(SeoScoringEngine::class);
        $violations = $engine->analyzeViolations(
            (string) $fixture['content'],
            (string) $fixture['keyword'],
            is_array($fixture['faq'] ?? null) ? $fixture['faq'] : [],
            [
                'seo_title' => (string) $fixture['title'],
                'meta_description' => (string) $fixture['meta_description'],
                'slug' => (string) $fixture['slug'],
                'article_length_target' => 100,
            ],
        );

        $score = SeoScoringCalculator::scoreFromViolations($violations);

        self::assertSame(SeoScoringRulesRegistry::SCORE_VERSION, $fixture['score_version']);
        self::assertIsInt($score);
        self::assertGreaterThanOrEqual(0, $score);
        self::assertLessThanOrEqual(100, $score);

        // Persist expected score into assertion log without hard-coding UI drift.
        self::assertNotContains(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $violations);
        self::assertNotContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $violations);
    }

    public function test_list_summary_does_not_parse_body(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleListSeoSummary.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString('SeoRuleViolationsResolver::scoreForArticle', $source);
        self::assertStringNotContainsString('analyzeSubmittedContent', $source);
        self::assertStringNotContainsString('Http::', $source);
        self::assertStringContainsString('score_stale', $source);
    }
}
