<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteTargetAllocator;
use PHPUnit\Framework\TestCase;

/**
 * MCP-normalized target_dna_count allocation (largest-remainder).
 */
final class AuditNoteTargetAllocatorTest extends TestCase
{
    public function test_mcp_weights_five_three_two_allocate_ten_six_four(): void
    {
        $result = AuditNoteTargetAllocator::apply([
            ['cluster_ref' => 'a', 'cluster_name_snapshot' => 'A', 'mcp_share_snapshot' => 5, 'target_mode' => 'auto', 'dna' => []],
            ['cluster_ref' => 'b', 'cluster_name_snapshot' => 'B', 'mcp_share_snapshot' => 3, 'target_mode' => 'auto', 'dna' => []],
            ['cluster_ref' => 'c', 'cluster_name_snapshot' => 'C', 'mcp_share_snapshot' => 2, 'target_mode' => 'auto', 'dna' => []],
        ], 20);

        self::assertSame(AuditNoteTargetAllocator::CODE_OK, $result['code']);
        self::assertSame([10, 6, 4], array_column($result['items'], 'target_dna_count'));
        self::assertSame(20, $result['total_target']);
    }

    public function test_zero_mcp_even_split_seven_seven_six(): void
    {
        $result = AuditNoteTargetAllocator::apply([
            ['cluster_ref' => 'a', 'cluster_name_snapshot' => 'A', 'mcp_share_snapshot' => 0, 'target_mode' => 'auto', 'dna' => []],
            ['cluster_ref' => 'b', 'cluster_name_snapshot' => 'B', 'mcp_share_snapshot' => 0, 'target_mode' => 'auto', 'dna' => []],
            ['cluster_ref' => 'c', 'cluster_name_snapshot' => 'C', 'mcp_share_snapshot' => 0, 'target_mode' => 'auto', 'dna' => []],
        ], 20);

        self::assertSame([7, 7, 6], array_column($result['items'], 'target_dna_count'));
    }

    public function test_mixed_manual_consumes_quantity_first(): void
    {
        $result = AuditNoteTargetAllocator::apply([
            [
                'cluster_ref' => 'a',
                'cluster_name_snapshot' => 'A',
                'mcp_share_snapshot' => 9,
                'target_mode' => 'manual',
                'target_dna_count' => 12,
                'dna' => [],
            ],
            ['cluster_ref' => 'b', 'cluster_name_snapshot' => 'B', 'mcp_share_snapshot' => 3, 'target_mode' => 'auto', 'dna' => []],
            ['cluster_ref' => 'c', 'cluster_name_snapshot' => 'C', 'mcp_share_snapshot' => 2, 'target_mode' => 'auto', 'dna' => []],
        ], 20);

        self::assertSame([12, 5, 3], array_column($result['items'], 'target_dna_count'));
        self::assertSame('manual', $result['items'][0]['target_mode']);
        self::assertSame('auto', $result['items'][1]['target_mode']);
    }

    public function test_specified_floor_raises_effective_target(): void
    {
        $result = AuditNoteTargetAllocator::apply([
            [
                'cluster_ref' => 'a',
                'cluster_name_snapshot' => 'A',
                'mcp_share_snapshot' => 1,
                'target_mode' => 'auto',
                'dna' => [
                    ['phrase' => '1', 'slots' => 1],
                    ['phrase' => '2', 'slots' => 1],
                    ['phrase' => '3', 'slots' => 1],
                    ['phrase' => '4', 'slots' => 1],
                    ['phrase' => '5', 'slots' => 1],
                    ['phrase' => '6', 'slots' => 1],
                ],
            ],
            ['cluster_ref' => 'b', 'cluster_name_snapshot' => 'B', 'mcp_share_snapshot' => 1, 'target_mode' => 'auto', 'dna' => []],
        ], 8);

        // Remaining after floor on A may push total above quantity — floors still apply.
        self::assertSame(6, $result['items'][0]['target_dna_count']);
        self::assertGreaterThanOrEqual(1, $result['items'][1]['target_dna_count']);
    }

    public function test_manual_override_survives_reallocation(): void
    {
        $items = [
            [
                'cluster_ref' => 'a',
                'cluster_name_snapshot' => 'A',
                'mcp_share_snapshot' => 5,
                'target_mode' => 'manual',
                'target_dna_count' => 15,
                'dna' => [],
            ],
            ['cluster_ref' => 'b', 'cluster_name_snapshot' => 'B', 'mcp_share_snapshot' => 5, 'target_mode' => 'auto', 'dna' => []],
        ];
        $first = AuditNoteTargetAllocator::apply($items, 20);
        self::assertSame(15, $first['items'][0]['target_dna_count']);

        $second = AuditNoteTargetAllocator::apply($first['items'], 50);
        self::assertSame(15, $second['items'][0]['target_dna_count']);
        self::assertSame('manual', $second['items'][0]['target_mode']);
        self::assertSame(35, $second['items'][1]['target_dna_count']);
    }

    public function test_return_auto_recalculates(): void
    {
        $items = [
            [
                'cluster_ref' => 'a',
                'cluster_name_snapshot' => 'A',
                'mcp_share_snapshot' => 5,
                'target_mode' => 'manual',
                'target_dna_count' => 15,
                'dna' => [],
            ],
            [
                'cluster_ref' => 'b',
                'cluster_name_snapshot' => 'B',
                'mcp_share_snapshot' => 5,
                'target_mode' => 'auto',
                'dna' => [],
            ],
        ];
        $items[0]['target_mode'] = 'auto';
        $result = AuditNoteTargetAllocator::apply($items, 20);
        self::assertSame([10, 10], array_column($result['items'], 'target_dna_count'));
    }

    public function test_too_many_topics_returns_warning_code(): void
    {
        $result = AuditNoteTargetAllocator::apply([
            ['cluster_ref' => 'a', 'cluster_name_snapshot' => 'A', 'mcp_share_snapshot' => 1, 'target_mode' => 'auto', 'dna' => []],
            ['cluster_ref' => 'b', 'cluster_name_snapshot' => 'B', 'mcp_share_snapshot' => 1, 'target_mode' => 'auto', 'dna' => []],
            ['cluster_ref' => 'c', 'cluster_name_snapshot' => 'C', 'mcp_share_snapshot' => 1, 'target_mode' => 'auto', 'dna' => []],
        ], 2);

        self::assertSame(AuditNoteTargetAllocator::CODE_TOO_MANY_TOPICS, $result['code']);
        self::assertSame(3, $result['topic_count']);
    }

    public function test_normalizer_defaults_target_mode_auto(): void
    {
        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => 'x',
            'cluster_name_snapshot' => 'X',
            'dna' => [],
        ]);
        self::assertNotNull($item);
        self::assertSame('auto', $item['target_mode']);
    }
}
