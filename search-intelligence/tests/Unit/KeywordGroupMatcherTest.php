<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupCoverageBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupMatcher;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGroupCatalog;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

final class KeywordGroupMatcherTest extends TestCase
{
    public function test_matches_material_care_and_price_examples(): void
    {
        $matcher = new KeywordGroupMatcher(new KeywordNormalizer());
        $groups = $this->groupsFromCatalog();

        $vaiBo = $matcher->match('balo vải bố', $groups);
        self::assertSame(['material'], array_column($vaiBo, 'key'));

        $giat = $matcher->match('cách giặt balo canvas', $groups);
        self::assertEqualsCanonicalizing(['material', 'care'], array_column($giat, 'key'));

        $gia = $matcher->match('giá balo canvas', $groups);
        self::assertEqualsCanonicalizing(['material', 'price'], array_column($gia, 'key'));
    }

    public function test_vietnamese_case_and_accents_are_folded(): void
    {
        $matcher = new KeywordGroupMatcher(new KeywordNormalizer());
        $groups = $this->groupsFromCatalog();

        $hits = $matcher->match('CÁCH GIẶT BALO VẢI BỐ', $groups);
        self::assertEqualsCanonicalizing(['material', 'care'], array_column($hits, 'key'));

        self::assertNotEmpty($matcher->match('Canvas', $groups));
        self::assertNotEmpty($matcher->match('CANVAS', $groups));
        self::assertNotEmpty($matcher->match('pvc', $groups));
        self::assertSame(
            array_column($matcher->match('Canvas', $groups), 'key'),
            array_column($matcher->match('CANVAS', $groups), 'key'),
        );
    }

    public function test_coverage_is_deterministic(): void
    {
        $builder = new KeywordGroupCoverageBuilder();
        self::assertSame('unknown', $builder->score(0, 0, 0, 0));
        self::assertSame('strong', $builder->score(8, 3, 3, 2));
        self::assertSame('medium', $builder->score(4, 1, 0, 0));
        self::assertSame('weak', $builder->score(2, 0, 0, 0));
    }

    /**
     * @return list<array{id: int, key: string, label: string, rules: list<array{match_type: string, phrase: string}>}>
     */
    private function groupsFromCatalog(): array
    {
        $groups = [];
        foreach (KeywordGroupCatalog::systemDefaults() as $index => $def) {
            $rules = [];
            foreach ($def['phrases'] as $phrase) {
                $rules[] = ['match_type' => 'contains', 'phrase' => $phrase];
            }
            $groups[] = [
                'id' => $index + 1,
                'key' => $def['key'],
                'label' => $def['label'],
                'rules' => $rules,
            ];
        }

        return $groups;
    }
}
