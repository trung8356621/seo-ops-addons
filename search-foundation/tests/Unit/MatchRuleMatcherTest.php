<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\MatchRules\MatchRuleMatcher;
use PHPUnit\Framework\TestCase;

final class MatchRuleMatcherTest extends TestCase
{
    public function test_unrelated_industries_do_not_leak_and_empty_rules_fail_neutral(): void
    {
        $matcher = new MatchRuleMatcher;
        $backpack = [['canonical' => 'balo', 'aliases' => ['ba lô'], 'match_mode' => 'phrase']];
        $dental = [['canonical' => 'implant', 'aliases' => ['cấy ghép implant'], 'match_mode' => 'phrase']];

        self::assertSame(['balo'], $matcher->matchingEntries($backpack, 'xưởng may ba lô'));
        self::assertSame([], $matcher->matchingEntries($dental, 'xưởng may ba lô'));
        self::assertSame(['implant'], $matcher->matchingEntries($dental, 'dịch vụ cấy ghép implant'));
        self::assertFalse($matcher->matches([], 'balo Oxford'));
    }

    public function test_accent_sensitive_mode_distinguishes_may_from_may_machine(): void
    {
        $matcher = new MatchRuleMatcher;
        $rules = [['canonical' => 'may', 'aliases' => [], 'match_mode' => 'accent_sensitive']];

        self::assertTrue($matcher->matches($rules, 'xưởng may'));
        self::assertFalse($matcher->matches($rules, 'máy tính'));
    }
}
