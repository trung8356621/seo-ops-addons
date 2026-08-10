<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpClusterValidationAction;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpClusterValidationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpOverlapService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SerpClusterValidationTest extends TestCase
{
    private SerpClusterValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SerpClusterValidationService(new SerpOverlapService(new SerpUrlNormalizationService));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sharedResults(int $count = 6): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['position' => $i, 'url' => "https://overlap.test/r{$i}"];
        }

        return $rows;
    }

    public function test_high_overlap_suggests_keep_cluster(): void
    {
        $members = [
            ['keyword_ref' => 'kw_a', 'results' => $this->sharedResults()],
            ['keyword_ref' => 'kw_b', 'results' => $this->sharedResults()],
            ['keyword_ref' => 'kw_c', 'results' => $this->sharedResults()],
        ];

        $suggestions = $this->service->suggest($members, ['min_valid' => 5, 'position_weighted' => false]);

        self::assertNotEmpty($suggestions);
        self::assertSame(SerpClusterValidationAction::KeepCluster, $suggestions[0]['action']);
    }

    public function test_low_overlap_suggests_split(): void
    {
        $members = [
            ['keyword_ref' => 'kw_a', 'results' => $this->sharedResults()],
            ['keyword_ref' => 'kw_b', 'results' => array_map(
                static fn (int $i): array => ['position' => $i, 'url' => "https://other-{$i}.test/x"],
                range(1, 6),
            )],
        ];

        $suggestions = $this->service->suggest($members, [
            'min_valid' => 5,
            'position_weighted' => false,
            'split_overlap_max' => 0.99,
        ]);

        $actions = array_map(static fn (array $row) => $row['action'], $suggestions);
        self::assertContains(SerpClusterValidationAction::SplitCluster, $actions);
    }

    public function test_validation_service_returns_suggestions_only_no_db_mutation(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SerpClusterValidationService::class))->getFileName());

        self::assertStringNotContainsString('->save(', $source);
        self::assertStringNotContainsString('->update(', $source);
        self::assertStringNotContainsString('->delete(', $source);
        self::assertStringContainsString('function suggest', $source);
    }
}
