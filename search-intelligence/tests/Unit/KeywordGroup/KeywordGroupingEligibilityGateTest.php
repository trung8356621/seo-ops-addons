<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupingEligibilityCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupingEligibilityGate;
use PHPUnit\Framework\TestCase;

final class KeywordGroupingEligibilityGateTest extends TestCase
{
    private KeywordGroupingEligibilityGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new KeywordGroupingEligibilityGate;
    }

    public function test_structural_exclusions_and_eligible_queries(): void
    {
        $cases = [
            'Zalo: 0909983833' => ['eligible' => false, 'flags' => ['contact_like', 'phone_like'], 'reasons' => ['contact_like']],
            '0909983833' => ['eligible' => false, 'flags' => ['contact_like', 'phone_like'], 'reasons' => ['contact_like']],
            'sales@example.com' => ['eligible' => false, 'flags' => ['contact_like', 'email_like'], 'reasons' => ['contact_like']],
            'https://example.com/balo' => ['eligible' => false, 'flags' => ['url_like'], 'reasons' => ['url_like']],
            'www.example.com' => ['eligible' => false, 'flags' => ['url_like'], 'reasons' => ['url_like']],
            'balo học sinh' => ['eligible' => true, 'flags' => [], 'reasons' => []],
            'balo 2026' => ['eligible' => true, 'flags' => [], 'reasons' => []],
            'top 10 balo' => ['eligible' => true, 'flags' => [], 'reasons' => []],
            'balo 15 inch' => ['eligible' => true, 'flags' => [], 'reasons' => []],
            'mua balo ở đâu?' => ['eligible' => true, 'flags' => ['question_like'], 'reasons' => []],
            'giá balo bao nhiêu' => ['eligible' => true, 'flags' => ['question_like'], 'reasons' => []],
            'báo giá balo theo yêu cầu' => ['eligible' => true, 'flags' => [], 'reasons' => []],
            'liên hệ ngay' => ['eligible' => true, 'flags' => [], 'reasons' => []],
            'balo 20 lít' => ['eligible' => true, 'flags' => [], 'reasons' => []],
        ];

        foreach ($cases as $text => $expected) {
            $decision = $this->gate->decideOne(new KeywordGroupingEligibilityCandidate('1', $text));
            self::assertSame($expected['eligible'], $decision->eligible, $text);
            self::assertSame($expected['flags'], $decision->flags, $text);
            self::assertSame($expected['reasons'], $decision->excludeReasons, $text);
        }
    }

    public function test_gate_does_not_reference_industry_group_matching(): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(KeywordGroupingEligibilityGate::class))->getFileName());
        self::assertStringNotContainsString('IndustryGroupSemanticMatcher', $source);
        self::assertStringNotContainsString('similar_text', $source);
        self::assertStringNotContainsString('levenshtein', $source);
        self::assertStringNotContainsString('sentence_like', $source);
    }
}
