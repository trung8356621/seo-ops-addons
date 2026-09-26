<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\Access\SeoAccessGscComposer;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextLoader;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;
use PHPUnit\Framework\TestCase;

final class SeoAccessGscComposerTest extends TestCase
{
    public function test_absent_property_returns_unavailable_without_fake_zeros(): void
    {
        $composer = $this->composerWith(new GscContext(
            siteId: 7,
            periodKey: '2026-09',
            metrics: [
                'absent' => true,
                'absent_reason' => 'no_gsc_property',
                'clicks' => 0,
                'impressions' => 0,
            ],
            summary: [],
            context: [],
            sourceUpdatedAt: null,
            generatedAt: '2026-09-26T00:00:00+00:00',
            available: false,
            stale: false,
        ));

        $payload = $composer->compose(7, '2026-09');

        self::assertFalse($payload['available']);
        self::assertSame('no_gsc_property', $payload['reason']);
        self::assertArrayNotHasKey('performance', $payload);
        self::assertArrayNotHasKey('opportunities', $payload);
        self::assertArrayNotHasKey('cannibalization', $payload);
        self::assertArrayNotHasKey('clicks', $payload);
        self::assertStringContainsString('No active GSC property', $payload['message']);
    }

    public function test_no_synced_data_returns_unavailable_without_fake_zeros(): void
    {
        $composer = $this->composerWith(new GscContext(
            siteId: 7,
            periodKey: '2026-08',
            metrics: [
                'absent' => true,
                'absent_reason' => 'no_synced_data',
                'clicks' => 0,
                'impressions' => 0,
                'rising_count' => 0,
            ],
            summary: [],
            context: [],
            sourceUpdatedAt: '2026-07-01T00:00:00+00:00',
            generatedAt: '2026-09-26T00:00:00+00:00',
            available: false,
            stale: false,
        ));

        $payload = $composer->compose(7, '2026-08');

        self::assertFalse($payload['available']);
        self::assertSame('no_synced_data', $payload['reason']);
        self::assertArrayNotHasKey('performance', $payload);
        self::assertStringNotContainsString('"clicks":0', json_encode($payload) ?: '');
    }

    public function test_real_zero_metrics_remain_available(): void
    {
        $composer = $this->composerWith(new GscContext(
            siteId: 7,
            periodKey: '2026-07',
            metrics: [
                'absent' => false,
                'clicks' => 0,
                'impressions' => 0,
                'rising_count' => 0,
                'falling_count' => 0,
                'ctr_opportunity_count' => 0,
                'near_page_one_count' => 0,
                'content_decay_count' => 0,
                'new_content_opportunity_count' => 0,
                'possible_cannibalization_count' => 0,
            ],
            summary: [
                'period' => ['current' => '2026-07'],
                'totals' => ['clicks' => 0, 'impressions' => 0],
                'comparison' => [],
                'top_queries' => [],
                'top_pages' => [],
                'rising_queries' => [],
                'falling_queries' => [],
                'high_impression_low_ctr' => [],
                'near_page_one' => [],
                'content_decay' => [],
                'new_content_opportunities' => [],
                'possible_cannibalization' => [],
            ],
            context: [],
            sourceUpdatedAt: '2026-07-31T00:00:00+00:00',
            generatedAt: '2026-09-26T00:00:00+00:00',
            available: true,
            stale: false,
        ));

        $payload = $composer->compose(7, '2026-07');

        self::assertTrue($payload['available']);
        self::assertSame(0, $payload['performance']['clicks']);
        self::assertSame(0, $payload['performance']['impressions']);
        self::assertArrayNotHasKey('reason', $payload);
    }

    private function composerWith(GscContext $context): SeoAccessGscComposer
    {
        $loader = new class($context) implements GscContextLoader
        {
            public function __construct(private GscContext $context) {}

            public function forSite(int $siteId, string $periodKey): GscContext
            {
                return $this->context;
            }

            public function sourceUpdatedAt(int $siteId): ?string
            {
                return $this->context->sourceUpdatedAt;
            }
        };

        return new SeoAccessGscComposer(new GscContextSource($loader));
    }
}
