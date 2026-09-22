<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpFreshness;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpReportBuilder;
use PHPUnit\Framework\TestCase;

final class MonthlyMcpReportBuilderTest extends TestCase
{
    public function test_two_valid_sources_make_report_ready(): void
    {
        $period = new SeoMcpPeriod(['year' => 2026, 'month' => 8]);
        $site = $this->snapshot(McpSourceKey::Site, ['health' => 'healthy', 'critical_findings' => 2, 'high_findings' => 0, 'noindex' => 1], [
            'findings' => ['top' => [['id' => 1, 'type' => 'broken_canonical', 'severity' => 'high', 'title' => 'x']]],
            'link_health' => ['broken_links' => 1],
        ]);
        $kw = $this->snapshot(McpSourceKey::Keywords, [
            'total' => 110,
            'focus' => 52,
            'error' => 5,
            'excluded' => 2,
            'clusters' => 18,
        ], [
            'groups' => [
                ['key' => 'group:1', 'label' => 'Vật liệu', 'count' => 23],
                ['key' => 'group:2', 'label' => 'Bảo quản', 'count' => 6],
            ],
            'weak_clusters' => [
                ['cluster_id' => '12', 'name' => 'Balo vải bố', 'keyword_count' => 18, 'article_count' => 4, 'coverage' => 'weak'],
            ],
        ], [
            'generation_context' => [
                'intent_gaps' => [['cluster' => 'Balo vải bố', 'missing_intent' => 'commercial']],
                'missing_directions' => [['cluster' => 'Balo vải bố', 'direction' => 'price']],
            ],
        ]);

        $built = (new MonthlyMcpReportBuilder())->build($period, 4, 'maytuicanvas.com', $site, $kw);
        self::assertSame('ready', $built['status']);
        self::assertSame(110, $built['overview']['keyword_total']);
        self::assertNotEmpty($built['highlights']);
        self::assertNotEmpty($built['risks']);
        $riskKeys = array_column($built['risks'], 'key');
        self::assertContains('keyword_error', $riskKeys);
        self::assertSame('mcp.monthly.v1', $built['ai_context']['schema']);
        $clusterIds = array_column($built['opportunities'], 'cluster_id');
        self::assertContains('12', $clusterIds);
        self::assertLessThanOrEqual(10, count($built['recommended_actions']));
        self::assertFalse($built['ai_context']['rebuilt'] ?? false);
    }

    public function test_keyword_failure_keeps_site_and_marks_incomplete(): void
    {
        $period = new SeoMcpPeriod(['year' => 2026, 'month' => 8]);
        $site = $this->snapshot(McpSourceKey::Site, ['health' => 'healthy'], []);
        $built = (new MonthlyMcpReportBuilder())->build($period, 4, 'example.com', $site, null);
        self::assertSame('incomplete', $built['status']);
        self::assertTrue($built['overview']['sources']['site']);
        self::assertFalse($built['overview']['sources']['keywords']);
        self::assertSame('healthy', $built['ai_context']['site']['health']);
    }

    public function test_keywords_mcp_v2_snapshot_does_not_throw_or_corrupt_report(): void
    {
        $period = new SeoMcpPeriod(['year' => 2026, 'month' => 9]);
        $site = $this->snapshot(McpSourceKey::Site, ['health' => 'healthy', 'article_total' => 12], []);
        $kw = $this->snapshot(
            McpSourceKey::Keywords,
            ['topic_count' => 2],
            [],
            [
                'topics' => [
                    [
                        'id' => 10,
                        'name' => 'Balo cho bé',
                        'mcp' => 0.1,
                        'dna_count' => 1,
                        'article_count' => 0,
                        'coverage' => 'weak',
                        'dna' => [['phrase' => 'balo', 'weight' => 2]],
                    ],
                    [
                        'id' => 11,
                        'name' => 'Túi xách',
                        'mcp' => 12.5,
                        'dna_count' => 0,
                        'article_count' => 3,
                        'coverage' => 'strong',
                        'dna' => [],
                    ],
                ],
            ],
        );
        $kw->schema_version = 'v2';

        self::assertSame('keywords', (string) $kw->source);
        self::assertSame('v2', (string) $kw->schema_version);
        self::assertNotSame('keywords.mcp.v2', (string) $kw->source);

        $built = (new MonthlyMcpReportBuilder)->build($period, 9, 'example.com', $site, $kw);

        self::assertSame('ready', $built['status']);
        self::assertTrue($built['overview']['sources']['keywords']);
        // Legacy metrics absent on v2 → remain zero (acceptable in this PR).
        self::assertSame(0, $built['overview']['keyword_total']);
        self::assertSame(0, $built['overview']['clusters']);
        self::assertIsArray($built['ai_context']['keyword_intelligence']);
        self::assertSame(['topic_count' => 2], $built['ai_context']['keyword_intelligence']['metrics']);
        self::assertSame([], $built['ai_context']['keyword_intelligence']['weak_clusters']);
        self::assertSame('mcp.monthly.v1', $built['ai_context']['schema']);
        self::assertIsArray($built['highlights']);
        self::assertIsArray($built['risks']);
        self::assertIsArray($built['opportunities']);
        self::assertIsArray($built['recommended_actions']);
    }

    public function test_content_hash_stable_for_same_payload(): void
    {
        $a = MonthlyMcpSourcePayload::make(McpSourceKey::Keywords, ['total' => 1], ['x' => 1], ['y' => 2], '2026-08-15T00:00:00+00:00');
        $b = MonthlyMcpSourcePayload::make(McpSourceKey::Keywords, ['total' => 1], ['x' => 1], ['y' => 2], '2026-08-15T01:00:00+00:00');
        self::assertSame($a->contentHash, $b->contentHash);
        $c = MonthlyMcpSourcePayload::make(McpSourceKey::Keywords, ['total' => 2], ['x' => 1], ['y' => 2], '2026-08-15T00:00:00+00:00');
        self::assertNotSame($a->contentHash, $c->contentHash);
    }

    public function test_stale_when_source_newer_than_snapshot(): void
    {
        self::assertTrue(MonthlyMcpFreshness::isNewer('2026-08-15T12:00:00+00:00', '2026-08-15T10:00:00+00:00'));
        self::assertFalse(MonthlyMcpFreshness::isNewer('2026-08-15T10:00:00+00:00', '2026-08-15T12:00:00+00:00'));
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    private function snapshot(McpSourceKey $source, array $metrics, array $summary, array $context = []): SeoMcpSourceSnapshot
    {
        $row = new SeoMcpSourceSnapshot([
            'source' => $source->value,
            'status' => 'current',
            'metrics_json' => $metrics,
            'summary_json' => $summary,
            'context_json' => $context,
        ]);
        $row->id = 1;

        return $row;
    }
}
