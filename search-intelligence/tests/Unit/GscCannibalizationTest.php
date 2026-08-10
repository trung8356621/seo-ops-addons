<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscCannibalizationType;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryCannibalizationDetector;
use PHPUnit\Framework\TestCase;

final class GscCannibalizationTest extends TestCase
{
    private GscQueryCannibalizationDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new GscQueryCannibalizationDetector;
    }

    public function test_competing_pages_issue_emitted(): void
    {
        $rows = [
            ['normalized_query' => 'dịch vụ seo', 'normalized_page' => 'https://example.test/a', 'clicks' => 5, 'impressions' => 50],
            ['normalized_query' => 'dịch vụ seo', 'normalized_page' => 'https://example.test/b', 'clicks' => 3, 'impressions' => 40],
        ];

        $issues = $this->detector->detect('dịch vụ seo', $rows);
        self::assertNotEmpty($issues);
        self::assertSame(GscCannibalizationType::CompetingPages->value, $issues[0]['type']);
        self::assertFalse($issues[0]['auto_consolidate']);
    }

    public function test_expected_multi_page_not_marked_as_false_critical(): void
    {
        $rows = [
            ['normalized_query' => 'trang chủ omi', 'normalized_page' => 'https://example.test/', 'clicks' => 5, 'impressions' => 50],
            ['normalized_query' => 'trang chủ omi', 'normalized_page' => 'https://example.test/login', 'clicks' => 3, 'impressions' => 40],
        ];

        $issues = $this->detector->detect('trang chủ omi', $rows);
        $types = array_column($issues, 'type');
        self::assertContains(GscCannibalizationType::ExpectedMultiPage->value, $types);

        foreach ($issues as $issue) {
            if ($issue['type'] === GscCannibalizationType::ExpectedMultiPage->value) {
                self::assertTrue($issue['metadata']['expected_multi_page'] ?? false);
            }
            self::assertFalse($issue['auto_consolidate']);
        }
    }

    public function test_no_auto_consolidate_on_any_issue(): void
    {
        $rows = [
            ['normalized_query' => 'seo', 'normalized_page' => 'https://example.test/a', 'clicks' => 10, 'impressions' => 100],
            ['normalized_query' => 'seo', 'normalized_page' => 'https://example.test/b', 'clicks' => 8, 'impressions' => 90],
        ];

        foreach ($this->detector->detect('seo', $rows) as $issue) {
            self::assertFalse($issue['auto_consolidate']);
        }
    }
}
