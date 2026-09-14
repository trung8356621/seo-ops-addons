<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SplitExecutionProgressResolver;
use Omnichannel\Addons\AiPrompt\Support\SplitExecutionProgress;
use PHPUnit\Framework\TestCase;

final class SplitExecutionProgressTest extends TestCase
{
    public function test_a_partial_failed_counts_completed_failed_pending(): void
    {
        $sections = [];
        for ($i = 1; $i <= 8; $i++) {
            $status = match (true) {
                $i <= 3 => 'completed',
                $i === 4 => 'failed',
                default => 'pending',
            };
            $sections[] = [
                'section_id' => sprintf('sec_%02d', $i),
                'label' => 'Section '.$i,
                'status' => $status,
                'section_order' => $i,
            ];
        }

        $progress = SplitExecutionProgress::fromPersisted(
            ['sections' => $sections],
            [],
            null,
            'failed',
        );

        self::assertSame(SplitExecutionProgress::PRESENTATION_PARTIAL_FAILED, $progress->presentationState);
        self::assertSame(8, $progress->total);
        self::assertSame(3, $progress->completed);
        self::assertSame(1, $progress->failed);
        self::assertSame(4, $progress->pending);
        self::assertSame(3, $progress->reusable);
        self::assertSame(1, $progress->retryable);
        self::assertSame(SplitExecutionProgress::ASSEMBLE_NOT_RUN, $progress->assembleStatus);
        self::assertTrue($progress->supportsResume());
        self::assertStringContainsString('3/8', $progress->summary());
        self::assertStringContainsString('1 bước lỗi', $progress->summary());
        self::assertStringContainsString('Giữ lại 3 bước', $progress->resumeHint());
        self::assertSame('INCOMPLETE', $progress->presentationLabel());
        self::assertTrue($progress->technicalFailed);
    }

    public function test_b_fail_at_first_section_is_not_seven_failed(): void
    {
        $progress = SplitExecutionProgress::fromPersisted(
            null,
            [],
            [
                'completed_sections' => 0,
                'total_sections' => 8,
                'section_id' => 'intro_01',
            ],
            'failed',
        );

        self::assertSame(SplitExecutionProgress::PRESENTATION_FAILED_BEFORE_ANY_PROGRESS, $progress->presentationState);
        self::assertSame(0, $progress->completed);
        self::assertSame(1, $progress->failed);
        self::assertSame(7, $progress->pending);
        self::assertStringContainsString('Thất bại ở bước đầu', $progress->summary());
        self::assertFalse($progress->supportsResume());
    }

    public function test_g_technical_failed_still_presentation_partial(): void
    {
        $progress = SplitExecutionProgress::fromPersisted(
            [
                'sections' => [
                    ['section_id' => 'a', 'status' => 'completed', 'section_order' => 1],
                    ['section_id' => 'b', 'status' => 'failed', 'section_order' => 2],
                    ['section_id' => 'c', 'status' => 'pending', 'section_order' => 3],
                ],
            ],
            [],
            null,
            'failed',
        );

        $arr = $progress->toArray();
        self::assertTrue($arr['technical_failed']);
        self::assertSame('partial_failed', $arr['presentation_state']);
        self::assertSame('continue_from_failed_step', $arr['cta']);
        self::assertSame('Tiếp tục từ bước lỗi', $arr['cta_label']);
    }

    public function test_c_resume_bag_counts_match_ui_progress(): void
    {
        $state = [
            'sections' => [
                ['section_id' => 's1', 'status' => 'completed', 'section_order' => 1],
                ['section_id' => 's2', 'status' => 'completed', 'section_order' => 2],
                ['section_id' => 's3', 'status' => 'completed', 'section_order' => 3],
                ['section_id' => 's4', 'status' => 'failed', 'section_order' => 4],
                ['section_id' => 's5', 'status' => 'pending', 'section_order' => 5],
                ['section_id' => 's6', 'status' => 'pending', 'section_order' => 6],
                ['section_id' => 's7', 'status' => 'pending', 'section_order' => 7],
                ['section_id' => 's8', 'status' => 'pending', 'section_order' => 8],
            ],
        ];

        $progress = (new SplitExecutionProgressResolver())->fromOrchestratorPayload(
            [
                'sectioned_free_orchestrator' => true,
                'sectioned_free_state' => $state,
                'steps_total' => 8,
            ],
            null,
            [],
            'failed',
        );

        self::assertSame(3, $progress->reusable);
        self::assertSame(1, $progress->retryable);
        self::assertSame(4, $progress->pending);
        self::assertSame('s4', $progress->failedSectionId);
    }

    public function test_e_success_after_all_sections_and_assemble(): void
    {
        $sections = [];
        for ($i = 1; $i <= 8; $i++) {
            $sections[] = [
                'section_id' => 's'.$i,
                'status' => 'completed',
                'section_order' => $i,
            ];
        }

        $progress = SplitExecutionProgress::fromPersisted(
            ['sections' => $sections],
            [],
            null,
            'completed',
            [['event' => 'assemble_completed']],
        );

        self::assertSame(SplitExecutionProgress::PRESENTATION_SUCCESS, $progress->presentationState);
        self::assertSame(8, $progress->completed);
        self::assertSame(0, $progress->failed);
        self::assertSame(SplitExecutionProgress::ASSEMBLE_SUCCESS, $progress->assembleStatus);
    }

    public function test_child_rows_drive_progress_when_state_missing(): void
    {
        $children = [
            ['status' => 'success', 'section_id' => 'a', 'section_order' => 1, 'prompt_name' => 'A'],
            ['status' => 'success', 'section_id' => 'b', 'section_order' => 2, 'prompt_name' => 'B'],
            ['status' => 'failed', 'section_id' => 'c', 'section_order' => 3, 'prompt_name' => 'C'],
        ];

        $progress = SplitExecutionProgress::fromPersisted(
            null,
            $children,
            ['total_sections' => 8, 'completed_sections' => 2, 'section_id' => 'c'],
            'failed',
        );

        self::assertSame(2, $progress->completed);
        self::assertSame(1, $progress->failed);
        self::assertSame(5, $progress->pending);
        self::assertSame('partial_failed', $progress->presentationState);
    }
}
