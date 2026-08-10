<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoScoringRulesRegistryTest extends TestCase
{
    public function test_rules_have_unique_keys(): void
    {
        $keys = array_map(static fn (array $rule): string => $rule['key'], SeoScoringRulesRegistry::rules());
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_deduction_for_known_key(): void
    {
        $this->assertSame(20, SeoScoringRulesRegistry::deductionFor(SeoScoringRulesRegistry::KEY_H2_MISSING));
    }

    public function test_sanitize_violations_filters_unknown_keys(): void
    {
        $sanitized = SeoScoringRulesRegistry::sanitizeViolations([
            'h2_missing',
            'unknown_rule',
            'faq_missing',
        ]);

        $this->assertSame(['h2_missing', 'faq_missing'], $sanitized);
    }
}
