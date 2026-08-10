<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;
use Omnichannel\Addons\Seo\Support\SeoScoringRuleMessageResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoScoringSettingsServiceTest extends TestCase
{
    public function test_effective_rules_merge_overrides(): void
    {
        $service = SeoScoringSettingsService::withOverrides([
            SeoScoringRulesRegistry::KEY_H2_MISSING => [
                'enabled' => false,
                'deduction' => 5,
            ],
        ]);

        $rules = $service->effectiveRules();
        $h2 = collect($rules)->firstWhere('key', SeoScoringRulesRegistry::KEY_H2_MISSING);

        $this->assertNotNull($h2);
        $this->assertFalse($h2['enabled']);
        $this->assertSame(5, $h2['deduction']);
    }

    public function test_disabled_rule_has_zero_deduction(): void
    {
        $service = SeoScoringSettingsService::withOverrides([
            SeoScoringRulesRegistry::KEY_H2_MISSING => [
                'enabled' => false,
                'deduction' => 20,
            ],
        ]);

        $this->assertSame(0, $service->deductionFor(SeoScoringRulesRegistry::KEY_H2_MISSING));
    }
}
