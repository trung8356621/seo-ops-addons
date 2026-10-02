<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Contracts\GlobalMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Services\MatchRules\IndustryMatchRuntime;
use Omnichannel\Addons\SearchFoundation\Services\MatchRules\MatchRuleMatcher;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use PHPUnit\Framework\TestCase;

final class KeywordIndustryMatchRuntimeTest extends TestCase
{
    public function test_classifier_uses_site_profile_without_cross_site_or_fallback_terms(): void
    {
        $provider = new class implements IndustryMatchRuleProvider
        {
            public function rulesForSite(int $siteId): array
            {
                return match ($siteId) {
                    10 => ['products' => [['canonical' => 'backpack', 'aliases' => [], 'match_mode' => 'phrase']]],
                    20 => ['services' => [['canonical' => 'dental brace', 'aliases' => [], 'match_mode' => 'phrase']]],
                    default => [],
                };
            }

            public function rulesForKey(?string $industryContextKey): array { return []; }

            public function provenanceForKey(?string $industryContextKey): ?array { return null; }
        };
        $globals = new class implements GlobalMatchRuleProvider
        {
            public function globalMatchRules(): array { return []; }
        };
        $classifier = new KeywordRuleClassifier($globals, new IndustryMatchRuntime($provider, new MatchRuleMatcher));

        $backpack = $classifier->classify('backpack travel', 'backpack travel', ['site_id' => 10, 'skip_segments' => true]);
        $dental = $classifier->classify('backpack travel', 'backpack travel', ['site_id' => 20, 'skip_segments' => true]);
        $none = $classifier->classify('backpack travel', 'backpack travel', ['site_id' => 0, 'skip_segments' => true]);

        self::assertGreaterThan($dental['keyword_score'], $backpack['keyword_score']);
        self::assertSame($dental['keyword_score'], $none['keyword_score']);
    }

    public function test_accent_sensitive_rule_does_not_fold_distinct_words(): void
    {
        $matcher = new MatchRuleMatcher;
        $entries = [['canonical' => 'may', 'aliases' => [], 'match_mode' => 'accent_sensitive']];

        self::assertTrue($matcher->matches($entries, 'may factory'));
        self::assertFalse($matcher->matches($entries, 'mÃ¡y factory'));
    }
}
