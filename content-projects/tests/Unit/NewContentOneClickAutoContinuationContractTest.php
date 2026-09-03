<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Jobs\GenerateNewContentSuggestionsJob;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentAutoContinuationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentPlannerRunOutcome;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * One-click automatic Keyword Discovery — consecutive_no_progress is recovery, not terminal.
 */
final class NewContentOneClickAutoContinuationContractTest extends TestCase
{
    public function test_consecutive_no_progress_escalates_instead_of_terminal(): void
    {
        $decision = NewContentAutoContinuationPolicy::afterNoProgress(0, 0, 0);
        self::assertSame(NewContentAutoContinuationPolicy::ACTION_CONTINUE, $decision['action']);
        self::assertSame(NewContentAutoContinuationPolicy::RECOVERY_REASON_NO_PROGRESS, $decision['recovery_reason']);
        self::assertGreaterThanOrEqual(1, $decision['recovery_level']);

        $continuing = NewContentPlannerRunOutcome::continuing(
            11,
            36,
            NewContentAutoContinuationPolicy::PHASE_RECOVERING,
            NewContentAutoContinuationPolicy::RECOVERY_REASON_NO_PROGRESS,
            16,
        );
        self::assertTrue($continuing['needs_continuation']);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_RECOVERING, $continuing['status']);
        self::assertSame(25, $continuing['remaining']);
        self::assertStringContainsString('Đang tránh ý tưởng trùng', $continuing['user_message']);
        self::assertStringNotContainsString('stop=', $continuing['user_message']);
    }

    public function test_terminal_only_after_recovery_budget_exhausted(): void
    {
        $decision = NewContentAutoContinuationPolicy::afterNoProgress(
            NewContentAutoContinuationPolicy::MAX_RECOVERY_LEVEL,
            NewContentAutoContinuationPolicy::MAX_NO_PROGRESS_ESCALATIONS_PER_SLICE,
            NewContentAutoContinuationPolicy::MAX_CONTINUATION_SLICES,
        );
        self::assertSame(NewContentAutoContinuationPolicy::ACTION_TERMINAL, $decision['action']);

        $partial = NewContentPlannerRunOutcome::resolve(
            11,
            36,
            NewContentPlannerRunOutcome::STOP_AUTO_RECOVERY_EXHAUSTED,
            duplicateSkipped: 16,
        );
        self::assertSame(SeoContentProjectPlannerRun::STATUS_PARTIAL, $partial['status']);
        self::assertFalse($partial['needs_continuation']);
        self::assertStringNotContainsString('stop=', $partial['message']);
    }

    public function test_mock_one_click_reaches_36_without_manual_click(): void
    {
        // plan=50 existing=14 → run requested=36
        // batches: 11 unique, 0 unique+16 dups (recovery), 10 unique, 15 unique
        $accepted = 0;
        $requested = 36;
        $slices = 0;
        $level = 0;
        $batches = [
            ['accepted' => 11, 'dups' => 0],
            ['accepted' => 0, 'dups' => 16],
            ['accepted' => 10, 'dups' => 0],
            ['accepted' => 15, 'dups' => 0],
        ];
        $needsManual = false;
        $status = SeoContentProjectPlannerRun::STATUS_RUNNING;

        foreach ($batches as $batch) {
            $accepted += $batch['accepted'];
            if ($accepted >= $requested) {
                $status = SeoContentProjectPlannerRun::STATUS_COMPLETED;
                break;
            }
            if ($batch['accepted'] <= 0) {
                $decision = NewContentAutoContinuationPolicy::afterNoProgress($level, 0, $slices);
                if ($decision['action'] === NewContentAutoContinuationPolicy::ACTION_TERMINAL) {
                    $status = SeoContentProjectPlannerRun::STATUS_PARTIAL;
                    $needsManual = true;
                    break;
                }
                $level = (int) $decision['recovery_level'];
                $status = SeoContentProjectPlannerRun::STATUS_RECOVERING;
                if ($decision['action'] === NewContentAutoContinuationPolicy::ACTION_YIELD_SLICE) {
                    $slices++;
                }
                continue;
            }
            $level = 0;
        }

        if ($accepted >= $requested) {
            $status = SeoContentProjectPlannerRun::STATUS_COMPLETED;
        }

        self::assertSame(36, $accepted);
        self::assertSame(SeoContentProjectPlannerRun::STATUS_COMPLETED, $status);
        self::assertFalse($needsManual);
        self::assertSame(50, 14 + $accepted); // final plan usable
    }

    public function test_job_auto_dispatches_same_logical_run_continuation(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(GenerateNewContentSuggestionsJob::class))->getFileName());
        self::assertStringContainsString('needs_continuation', $src);
        self::assertStringContainsString('queueContinuation', $src);
        self::assertStringContainsString('content-project-new-content:run:', $src);
        self::assertStringContainsString('->delay(', $src);

        $planner = (string) file_get_contents((new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName());
        self::assertStringContainsString('function queueContinuation', $planner);
        self::assertStringContainsString('NewContentAutoContinuationPolicy::afterNoProgress', $planner);
        self::assertStringContainsString('ACTION_CONTINUE', $planner);
        self::assertDoesNotMatchRegularExpression(
            '/STOP_CONSECUTIVE_NO_PROGRESS;\s*\n\s*break;/',
            $planner,
        );
    }

    public function test_ui_hides_manual_fill_while_auto_recovering(): void
    {
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');
        self::assertStringContainsString('planner_retry_remaining', $card);
        self::assertStringContainsString('progress_user_message', $card);
        self::assertStringContainsString('can_fill_remaining', $card);

        $concern = (string) file_get_contents((new ReflectionClass(
            \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions::class
        ))->getFileName());
        self::assertStringContainsString('&& ! $generating', $concern);
        self::assertStringContainsString('progress_user_message', $concern);
        self::assertStringContainsString('activeStatuses()', $concern);

        $model = (string) file_get_contents((new ReflectionClass(SeoContentProjectPlannerRun::class))->getFileName());
        self::assertStringContainsString('STATUS_RECOVERING', $model);
        self::assertStringContainsString('STATUS_WAITING_RETRY', $model);
        self::assertStringContainsString('activeStatuses', $model);
    }

    public function test_oversample_and_coverage_rotate_helpers(): void
    {
        self::assertSame(14, NewContentAutoContinuationPolicy::oversampleRawTarget(10, 1.4, 40));
        $rotated = NewContentAutoContinuationPolicy::rotateCoverageSlice([
            ['cluster_ref' => 'a'],
            ['cluster_ref' => 'b'],
            ['cluster_ref' => 'c'],
        ]);
        self::assertSame('b', $rotated[0]['cluster_ref']);
        self::assertSame('a', $rotated[2]['cluster_ref']);
    }

    public function test_provider_transient_uses_wait_retry_not_cross_model(): void
    {
        $decision = NewContentAutoContinuationPolicy::afterProviderTransient(0);
        self::assertSame(NewContentAutoContinuationPolicy::ACTION_WAIT_RETRY, $decision['action']);
        self::assertGreaterThan(0, $decision['delay_seconds']);

        $planner = (string) file_get_contents((new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName());
        self::assertStringContainsString('afterProviderTransient', $planner);
        self::assertStringNotContainsString('cross-model because duplicate', $planner);
    }

    public function test_truncated_triggers_reduce_batch_not_cross_model(): void
    {
        $decision = NewContentAutoContinuationPolicy::afterTruncatedRepairFailed(1);
        self::assertSame(NewContentAutoContinuationPolicy::ACTION_CONTINUE, $decision['action']);
        self::assertSame(5, $decision['forced_batch_cap']);
        self::assertTrue($decision['rotate_coverage']);

        $planner = (string) file_get_contents((new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName());
        self::assertStringContainsString('afterTruncatedRepairFailed', $planner);
        self::assertStringContainsString('isRecoverableStructuredOutputError', $planner);
        self::assertStringContainsString('STATUS_CANCELLED', $planner);
    }

    public function test_cancel_blocks_continuation_dispatch(): void
    {
        $job = (string) file_get_contents((new ReflectionClass(GenerateNewContentSuggestionsJob::class))->getFileName());
        self::assertStringContainsString('STATUS_CANCELLED', $job);
    }
}
