<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoScoringCalculatorTest extends TestCase
{
    public function test_missing_focus_keyword_scores_zero(): void
    {
        $score = SeoScoringCalculator::scoreFromViolations([
            SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
            SeoScoringRulesRegistry::KEY_H2_MISSING,
        ]);

        $this->assertSame(0, $score);
    }

    public function test_score_floor_is_zero(): void
    {
        $violations = SeoScoringRulesRegistry::knownKeys();
        $score = SeoScoringCalculator::scoreFromViolations($violations);

        $this->assertSame(0, $score);
    }

    public function test_no_violations_scores_100(): void
    {
        $this->assertSame(100, SeoScoringCalculator::scoreFromViolations([]));
    }
}
