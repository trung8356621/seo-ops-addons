<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapHierarchyValidator;
use PHPUnit\Framework\TestCase;

final class TopicalMapHierarchyTest extends TestCase
{
    public function test_rejects_cycle_and_multiple_primary(): void
    {
        $validator = new TopicalMapHierarchyValidator;
        $topics = [
            ['topic_ref' => 'r', 'parent_ref' => null, 'topic_type' => 'root'],
            ['topic_ref' => 'a', 'parent_ref' => 'b', 'topic_type' => 'pillar'],
            ['topic_ref' => 'b', 'parent_ref' => 'a', 'topic_type' => 'subtopic'],
        ];
        $assignments = [
            ['cluster_ref' => 'kwc_1', 'topic_ref' => 'a', 'relationship' => 'primary'],
            ['cluster_ref' => 'kwc_1', 'topic_ref' => 'b', 'relationship' => 'primary'],
        ];

        $result = $validator->validate($topics, $assignments, 4);

        self::assertSame('invalid', $result['status']);
        $joined = implode(',', $result['reasons']);
        self::assertStringContainsString('cycle_detected', $joined);
        self::assertStringContainsString('cluster_multiple_primary', $joined);
    }

    public function test_related_link_does_not_count_as_second_primary(): void
    {
        $validator = new TopicalMapHierarchyValidator;
        $topics = [
            ['topic_ref' => 'r', 'parent_ref' => null, 'topic_type' => 'root'],
            ['topic_ref' => 'p', 'parent_ref' => 'r', 'topic_type' => 'pillar'],
        ];
        $assignments = [
            ['cluster_ref' => 'kwc_1', 'topic_ref' => 'p', 'relationship' => 'primary'],
            ['cluster_ref' => 'kwc_1', 'topic_ref' => 'p', 'relationship' => 'related'],
        ];

        $result = $validator->validate($topics, $assignments, 4);
        self::assertSame('valid', $result['status']);
    }

    public function test_max_depth_needs_review(): void
    {
        $validator = new TopicalMapHierarchyValidator;
        $topics = [
            ['topic_ref' => 'r', 'parent_ref' => null, 'topic_type' => 'root'],
            ['topic_ref' => 'a', 'parent_ref' => 'r', 'topic_type' => 'pillar'],
            ['topic_ref' => 'b', 'parent_ref' => 'a', 'topic_type' => 'subtopic'],
            ['topic_ref' => 'c', 'parent_ref' => 'b', 'topic_type' => 'subtopic'],
            ['topic_ref' => 'd', 'parent_ref' => 'c', 'topic_type' => 'cluster_group'],
        ];

        $result = $validator->validate($topics, [], 3);
        self::assertContains($result['status'], ['needs_review', 'invalid']);
        self::assertStringContainsString('max_depth_exceeded', implode(',', $result['reasons']));
    }
}
