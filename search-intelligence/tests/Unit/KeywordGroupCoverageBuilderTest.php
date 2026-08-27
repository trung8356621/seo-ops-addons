<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupCoverageBuilder;
use PHPUnit\Framework\TestCase;

final class KeywordGroupCoverageBuilderTest extends TestCase
{
    public function test_coverage_uses_dna_branch_count_not_rule_groups(): void
    {
        $builder = new KeywordGroupCoverageBuilder();

        self::assertSame('unknown', $builder->score(0, 0, 0, 0));
        self::assertSame('strong', $builder->score(8, 3, 3, 2));
        self::assertSame('medium', $builder->score(4, 1, 0, 0));
        self::assertSame('weak', $builder->score(2, 0, 0, 0));

        // dna_branch_count=2 with 4 KW → medium (DNA diversity path)
        self::assertSame('medium', $builder->score(4, 0, 2, 0));
        // Low DNA alone does not create strong coverage
        self::assertSame('weak', $builder->score(2, 0, 5, 0));
    }
}
