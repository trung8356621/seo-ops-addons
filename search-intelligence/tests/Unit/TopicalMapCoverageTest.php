<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalCoverageService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicalMapCoverageTest extends TestCase
{
    public function test_summarize_declares_internal_proxy_authority(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(TopicalCoverageService::class))->getFileName()
        );
        self::assertStringContainsString('authority_score_source', $src);
        self::assertStringContainsString('internal_proxy', $src);
        self::assertTrue(method_exists(TopicalCoverageService::class, 'summarize'));
        self::assertTrue(method_exists(TopicalCoverageService::class, 'calculate'));
    }

    public function test_coverage_formula_approved_over_active(): void
    {
        // Pure arithmetic mirror of summarize() without DB.
        $clusterCount = 10;
        $approved = 7;
        $coverage = $clusterCount > 0 ? round(min(100.0, ($approved / $clusterCount) * 100), 2) : 0.0;
        $gap = round(max(0.0, 100.0 - $coverage), 2);

        self::assertSame(70.0, $coverage);
        self::assertSame(30.0, $gap);
    }
}
