<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpContentGapType;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpContentGapAnalyzer;
use PHPUnit\Framework\TestCase;

final class SerpContentGapTest extends TestCase
{
    private SerpContentGapAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new SerpContentGapAnalyzer;
    }

    public function test_multi_result_faq_and_schema_gaps(): void
    {
        $own = ['faq_count' => 0, 'schema_types' => [], 'media_count' => 1, 'word_count_approx' => 400];
        $competitors = [
            ['faq_count' => 3, 'schema_types' => ['FAQPage'], 'media_count' => 4, 'word_count_approx' => 900, 'title' => 'A'],
            ['faq_count' => 2, 'schema_types' => ['Article'], 'media_count' => 5, 'word_count_approx' => 850, 'title' => 'B'],
            ['faq_count' => 1, 'schema_types' => ['FAQPage'], 'media_count' => 3, 'word_count_approx' => 820, 'title' => 'C'],
        ];

        $gaps = $this->analyzer->analyze($own, $competitors, ['min_frequency' => 0.3, 'min_confidence' => 0.4]);

        $types = array_map(static fn (array $gap) => $gap['gap_type'], $gaps);
        self::assertContains(SerpContentGapType::MissingQuestion, $types);
        self::assertContains(SerpContentGapType::MissingSchema, $types);
    }

    public function test_single_heading_does_not_create_strong_gap(): void
    {
        $own = ['headings' => ['h2' => ['Pricing']]];
        // Mỗi heading thiếu chỉ xuất hiện 1 lần — không đủ làm strong MissingHeading.
        $competitors = [
            ['headings' => ['h2' => ['Pricing']]],
            ['headings' => ['h2' => ['OnlyOnceA']]],
            ['headings' => ['h2' => ['OnlyOnceB']]],
        ];

        $gaps = $this->analyzer->analyze($own, $competitors);

        foreach ($gaps as $gap) {
            if ($gap['gap_type'] === SerpContentGapType::MissingHeading) {
                self::fail('Single competitor heading frequency must not produce MissingHeading gap.');
            }
        }

        self::assertTrue(true);
    }

    public function test_analyzer_version_constant_present_in_metadata(): void
    {
        self::assertSame('1.0.0', SerpContentGapAnalyzer::CONTENT_GAP_ANALYZER_VERSION);

        $gaps = $this->analyzer->analyze(
            ['faq_count' => 0],
            [
                ['faq_count' => 2, 'schema_types' => [], 'media_count' => 0, 'word_count_approx' => 0],
                ['faq_count' => 2, 'schema_types' => [], 'media_count' => 0, 'word_count_approx' => 0],
                ['faq_count' => 2, 'schema_types' => [], 'media_count' => 0, 'word_count_approx' => 0],
            ],
            ['min_frequency' => 0.3, 'min_confidence' => 0.4],
        );

        self::assertNotEmpty($gaps);
        self::assertSame('1.0.0', $gaps[0]['metadata']['analyzer_version'] ?? null);
    }
}
