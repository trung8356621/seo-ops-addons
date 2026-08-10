<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSerpGscMismatchType;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\SerpGscEvidenceReconciler;
use PHPUnit\Framework\TestCase;

final class GscSerpReconciliationTest extends TestCase
{
    private SerpGscEvidenceReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciler = new SerpGscEvidenceReconciler;
    }

    public function test_impression_without_serp_presence_is_review_only(): void
    {
        $suggestions = $this->reconciler->reconcile(
            ['in_top_results' => false],
            ['impressions' => 120, 'position' => 8.0],
            ['normalized_query' => 'dịch vụ seo'],
        );

        self::assertCount(1, $suggestions);
        self::assertSame('serp_gsc_mismatch', $suggestions[0]['code']);
        self::assertSame('review_only', $suggestions[0]['action']);
        self::assertSame(
            GscSerpGscMismatchType::ImpressionWithoutSerpPresence->value,
            $suggestions[0]['mismatch_type'],
        );
    }

    public function test_position_mismatch_suggestion_only(): void
    {
        $suggestions = $this->reconciler->reconcile(
            ['in_top_results' => true, 'position' => 3.0],
            ['impressions' => 50, 'position' => 12.0],
            ['normalized_query' => 'seo audit'],
        );

        $types = array_column($suggestions, 'mismatch_type');
        self::assertContains(GscSerpGscMismatchType::PositionMismatch->value, $types);

        foreach ($suggestions as $suggestion) {
            self::assertSame('review_only', $suggestion['action']);
        }
    }
}
