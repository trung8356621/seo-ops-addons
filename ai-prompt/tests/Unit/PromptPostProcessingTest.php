<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use PHPUnit\Framework\TestCase;

final class PromptPostProcessingTest extends TestCase
{
    public function test_normalize_grid_size_three_sets_square_and_panels(): void
    {
        $normalized = PromptPostProcessing::normalize([
            'split_enabled' => '1',
            'split_grid_size' => 3,
            'resize_enabled' => false,
        ]);

        $this->assertTrue($normalized['split_enabled']);
        $this->assertSame(3, $normalized['split_grid_size']);
        $this->assertSame(3, $normalized['split_rows']);
        $this->assertSame(3, $normalized['split_columns']);
        $this->assertSame(9, $normalized['expected_panels']);
    }

    public function test_normalize_grid_size_four_sets_sixteen_panels(): void
    {
        $normalized = PromptPostProcessing::normalize([
            'split_enabled' => true,
            'split_grid_size' => 4,
        ]);

        $this->assertSame(4, $normalized['split_grid_size']);
        $this->assertSame(16, $normalized['expected_panels']);
        $this->assertSame(4, $normalized['split_rows']);
        $this->assertSame(4, $normalized['split_columns']);
    }

    public function test_normalize_rejects_out_of_range_by_clamping(): void
    {
        $this->assertSame(2, PromptPostProcessing::normalize(['split_grid_size' => 0])['split_grid_size']);
        $this->assertSame(2, PromptPostProcessing::normalize(['split_grid_size' => 1])['split_grid_size']);
        $this->assertSame(2, PromptPostProcessing::normalize(['split_grid_size' => -3])['split_grid_size']);
        $this->assertSame(6, PromptPostProcessing::normalize(['split_grid_size' => 99])['split_grid_size']);
    }

    public function test_is_valid_grid_size_rejects_invalid_inputs(): void
    {
        $this->assertFalse(PromptPostProcessing::isValidGridSize(0));
        $this->assertFalse(PromptPostProcessing::isValidGridSize(1));
        $this->assertFalse(PromptPostProcessing::isValidGridSize(-1));
        $this->assertFalse(PromptPostProcessing::isValidGridSize(3.5));
        $this->assertFalse(PromptPostProcessing::isValidGridSize('3.5'));
        $this->assertFalse(PromptPostProcessing::isValidGridSize('abc'));
        $this->assertFalse(PromptPostProcessing::isValidGridSize(7));
        $this->assertTrue(PromptPostProcessing::isValidGridSize(3));
        $this->assertTrue(PromptPostProcessing::isValidGridSize('4'));
    }

    public function test_legacy_equal_rows_columns_become_grid_size(): void
    {
        $normalized = PromptPostProcessing::normalize([
            'split_enabled' => true,
            'split_rows' => 3,
            'split_columns' => 3,
        ]);

        $this->assertSame(3, $normalized['split_grid_size']);
        $this->assertSame(9, $normalized['expected_panels']);
    }

    public function test_legacy_rectangular_normalizes_to_rows_preference(): void
    {
        $normalized = PromptPostProcessing::normalize([
            'split_enabled' => true,
            'split_rows' => 3,
            'split_columns' => 4,
        ], promptId: 42);

        $this->assertSame(3, $normalized['split_grid_size']);
        $this->assertSame(3, $normalized['split_rows']);
        $this->assertSame(3, $normalized['split_columns']);
        $this->assertSame(9, $normalized['expected_panels']);
    }

    public function test_snapshot_round_trip_preserves_dispatch_config(): void
    {
        $config = PromptPostProcessing::normalize([
            'split_enabled' => true,
            'split_grid_size' => 3,
            'resize_enabled' => true,
            'resize_width' => 800,
            'resize_height' => null,
        ]);

        $variables = PromptPostProcessing::attachSnapshotToVariables([], $config);
        $fromSnapshot = PromptPostProcessing::fromVariablesSnapshot($variables);

        $this->assertNotNull($fromSnapshot);
        $this->assertTrue($fromSnapshot['split_enabled']);
        $this->assertSame(3, $fromSnapshot['split_grid_size']);
        $this->assertSame(9, $fromSnapshot['expected_panels']);
        $this->assertSame(800, $fromSnapshot['resize_width']);
        $this->assertNull($fromSnapshot['resize_height']);

        $snapshot = PromptPostProcessing::toSnapshot($config);
        $this->assertSame(3, $snapshot['grid_size']);
        $this->assertSame(9, $snapshot['expected_panels']);
        $this->assertTrue($snapshot['enabled']);
    }

    public function test_snapshot_wins_over_live_prompt_mutation(): void
    {
        $dispatchConfig = PromptPostProcessing::normalize([
            'split_enabled' => true,
            'split_grid_size' => 3,
        ]);
        $variables = PromptPostProcessing::attachSnapshotToVariables([], $dispatchConfig);

        $livePromptSettings = [
            'post_processing' => [
                'split_enabled' => true,
                'split_grid_size' => 4,
            ],
        ];

        $resolved = PromptPostProcessing::fromVariablesSnapshot($variables);
        $this->assertNotNull($resolved);
        $this->assertSame(3, $resolved['split_grid_size']);
        $this->assertNotSame(
            PromptPostProcessing::fromPromptSettings($livePromptSettings)['split_grid_size'],
            $resolved['split_grid_size'],
        );
    }

    public function test_merge_into_settings_preserves_other_keys(): void
    {
        $merged = PromptPostProcessing::mergeIntoSettings(
            ['detected_tags' => ['image']],
            ['split_enabled' => true, 'split_grid_size' => 4],
        );

        $this->assertSame(['image'], $merged['detected_tags']);
        $this->assertTrue($merged['post_processing']['split_enabled']);
        $this->assertSame(4, $merged['post_processing']['split_grid_size']);
        $this->assertSame(4, $merged['post_processing']['split_rows']);
        $this->assertSame(4, $merged['post_processing']['split_columns']);
    }

    public function test_is_active_requires_resize_dimensions(): void
    {
        $this->assertFalse(PromptPostProcessing::isActive([
            'split_enabled' => false,
            'split_grid_size' => 3,
            'split_rows' => 3,
            'split_columns' => 3,
            'expected_panels' => 9,
            'resize_enabled' => true,
            'resize_width' => null,
            'resize_height' => null,
        ]));

        $this->assertTrue(PromptPostProcessing::isActive([
            'split_enabled' => false,
            'split_grid_size' => 3,
            'split_rows' => 3,
            'split_columns' => 3,
            'expected_panels' => 9,
            'resize_enabled' => true,
            'resize_width' => 800,
            'resize_height' => null,
        ]));
    }

    public function test_disabled_split_keeps_grid_size_for_later(): void
    {
        $normalized = PromptPostProcessing::normalize([
            'split_enabled' => false,
            'split_grid_size' => 5,
        ]);

        $this->assertFalse($normalized['split_enabled']);
        $this->assertSame(5, $normalized['split_grid_size']);
    }
}
