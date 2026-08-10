<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpOverlapBand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpOverlapService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SerpOverlapTest extends TestCase
{
    private SerpOverlapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SerpOverlapService(new SerpUrlNormalizationService);
    }

    public function test_shared_normalized_urls_increase_score(): void
    {
        $shared = [];
        for ($i = 1; $i <= 6; $i++) {
            $shared[] = ['position' => $i, 'url' => "https://shared.test/page-{$i}"];
        }

        $result = $this->service->compare($shared, $shared, ['min_valid' => 5, 'top_n' => 10, 'position_weighted' => false]);

        self::assertTrue($result['valid']);
        self::assertGreaterThan(0.5, $result['score']);
        self::assertContains($result['band'], [SerpOverlapBand::VeryHigh, SerpOverlapBand::High]);
        self::assertCount(6, $result['shared_urls']);
    }

    public function test_insufficient_results_marks_invalid_low_confidence(): void
    {
        $a = [['position' => 1, 'url' => 'https://a.test/1']];
        $b = [['position' => 1, 'url' => 'https://b.test/1']];

        $result = $this->service->compare($a, $b, ['min_valid' => 5]);

        self::assertFalse($result['valid']);
        self::assertSame(0.0, $result['score']);
        self::assertSame(SerpOverlapBand::Low, $result['band']);
    }

    public function test_overlap_service_does_not_merge_clusters(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SerpOverlapService::class))->getFileName());

        self::assertStringNotContainsString('MergeKeywordClusters', $source);
        self::assertStringNotContainsString('mergeClusters', $source);
        self::assertStringContainsString("'score'", $source);
    }
}
