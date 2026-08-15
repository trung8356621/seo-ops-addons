<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\McpPeriodStatus;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodPolicy;
use PHPUnit\Framework\TestCase;

final class MonthlyMcpPeriodLifecycleTest extends TestCase
{
    public function test_create_open_period_defaults(): void
    {
        $period = new SeoMcpPeriod([
            'year' => 2026,
            'month' => 8,
            'status' => McpPeriodStatus::Open->value,
        ]);
        self::assertTrue($period->isOpen());
        self::assertSame('2026-08', $period->periodKey());
    }

    public function test_finalize_partial_requires_confirmation(): void
    {
        $gate = (new McpPeriodPolicy())->finalizeGate(4, 6, false);
        self::assertTrue($gate['needs_confirmation']);
        self::assertSame(4, $gate['available']);
        self::assertSame(6, $gate['expected']);
    }

    public function test_finalize_partial_allowed_when_confirmed(): void
    {
        $gate = (new McpPeriodPolicy())->finalizeGate(4, 6, true);
        self::assertFalse($gate['needs_confirmation']);
    }

    public function test_coverage_is_not_lifecycle_status(): void
    {
        $period = new SeoMcpPeriod([
            'status' => McpPeriodStatus::Finalized->value,
            'expected_sites' => 6,
            'available_sites' => 4,
        ]);
        self::assertTrue($period->isFinalized());
        self::assertSame('partial', $period->coverageKind());
        self::assertNotSame('finalized_partial', $period->status);
    }

    public function test_reopen_resets_finalize_flags(): void
    {
        $period = new SeoMcpPeriod([
            'status' => McpPeriodStatus::Finalized->value,
            'manual_finalized' => true,
            'finalized_by' => 9,
        ]);
        $out = (new McpPeriodPolicy())->applyReopen($period);
        self::assertTrue($out->isOpen());
        self::assertFalse((bool) $out->manual_finalized);
        self::assertNull($out->finalized_at);
        self::assertNull($out->finalized_by);
    }
}
