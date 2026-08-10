<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapVersionDiffService;
use PHPUnit\Framework\TestCase;

final class TopicalMapVersionTest extends TestCase
{
    public function test_diff_detects_added_removed_moved(): void
    {
        $svc = new TopicalMapVersionDiffService;
        $diff = $svc->diff(
            [
                'topics' => [
                    ['topic_ref' => 'kwt_1', 'parent_ref' => null, 'name' => 'A'],
                    ['topic_ref' => 'kwt_2', 'parent_ref' => 'kwt_1', 'name' => 'B'],
                ],
                'assignments' => [
                    ['cluster_ref' => 'kwc_1', 'topic_ref' => 'kwt_1', 'relationship' => 'primary'],
                ],
                'summary' => ['coverage_score' => 40, 'gap_score' => 60, 'blocking_conflicts' => 1],
            ],
            [
                'topics' => [
                    ['topic_ref' => 'kwt_1', 'parent_ref' => null, 'name' => 'A renamed'],
                    ['topic_ref' => 'kwt_2', 'parent_ref' => null, 'name' => 'B'],
                    ['topic_ref' => 'kwt_3', 'parent_ref' => 'kwt_1', 'name' => 'C'],
                ],
                'assignments' => [
                    ['cluster_ref' => 'kwc_1', 'topic_ref' => 'kwt_2', 'relationship' => 'primary'],
                ],
                'summary' => ['coverage_score' => 55, 'gap_score' => 45, 'blocking_conflicts' => 0],
            ],
        );

        self::assertContains('kwt_3', $diff['topics_added']);
        self::assertNotEmpty($diff['topics_moved']);
        self::assertNotEmpty($diff['topics_renamed']);
        self::assertSame(15.0, $diff['coverage_delta']);
        self::assertSame(-1, $diff['blocking_conflicts_delta']);
    }
}
