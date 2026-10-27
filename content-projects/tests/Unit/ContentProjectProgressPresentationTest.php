<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProgressReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchProgressReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPercent;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPresentation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectProgressPresentationTest extends TestCase
{
    /**
     * Test 1: Running 5/30 -> actual percentage.
     */
    public function test_running_5_of_30_displays_actual_percentage_and_active_state(): void
    {
        $pres = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'running',
            snapshotTotal: 30,
            succeeded: 5,
            failed: 0,
            skipped: 0,
            pending: 25,
            processing: 1,
            cancelled: 0,
            isTerminal: false,
        );

        self::assertFalse($pres['is_terminal']);
        self::assertSame(ContentProjectProgressPresentation::STATE_ACTIVE, $pres['state']);
        self::assertNull($pres['status_heading']);
        self::assertSame(16, $pres['display_percent']);
        self::assertSame(16, $pres['lifecycle_percent']);
        self::assertSame(16, $pres['raw_percent']);
        self::assertSame('5/30 bài đã xử lý', $pres['headline_left']);
        self::assertSame('16%', $pres['headline_right']);
        self::assertSame('Chờ xử lý', $pres['pending_label']);
        self::assertStringContainsString('5 thành công', $pres['summary_text']);
        self::assertStringContainsString('25 chờ xử lý', $pres['summary_text']);
        self::assertSame('bg-primary-600', $pres['progress_bar_class']);
    }

    /**
     * Test 2: Completed 24 success + 6 skipped -> 100%.
     */
    public function test_completed_24_success_plus_6_skipped_displays_100_percent_success_heading(): void
    {
        $pres = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'completed',
            snapshotTotal: 30,
            succeeded: 24,
            failed: 0,
            skipped: 6,
            pending: 0,
            processing: 0,
            cancelled: 0,
            isTerminal: true,
        );

        self::assertTrue($pres['is_terminal']);
        self::assertSame(ContentProjectProgressPresentation::STATE_SUCCESS, $pres['state']);
        self::assertSame('ĐÃ HOÀN TẤT', $pres['status_heading']);
        self::assertStringContainsString('text-success', $pres['status_heading_class']);
        self::assertStringContainsString('bg-success', $pres['progress_bar_class']);
        self::assertSame(100, $pres['display_percent']);
        self::assertSame(100, $pres['lifecycle_percent']);
        self::assertSame('ĐÃ HOÀN TẤT', $pres['headline_left']);
        self::assertSame('Lifecycle Progress: 100%', $pres['headline_right']);
        self::assertSame('24 thành công · 0 thất bại · 6 bỏ qua · 0 chưa thực hiện', $pres['summary_text']);
        self::assertSame('chưa thực hiện', $pres['pending_label']);
    }

    /**
     * Test 3: Terminal with failed items -> 100%, warning.
     */
    public function test_terminal_with_failed_items_displays_100_percent_with_warning_heading(): void
    {
        $pres = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'completed',
            snapshotTotal: 30,
            succeeded: 20,
            failed: 4,
            skipped: 6,
            pending: 0,
            processing: 0,
            cancelled: 0,
            isTerminal: true,
        );

        self::assertTrue($pres['is_terminal']);
        self::assertSame(ContentProjectProgressPresentation::STATE_COMPLETED_WITH_ERRORS, $pres['state']);
        self::assertSame('ĐÃ KẾT THÚC — CÓ LỖI', $pres['status_heading']);
        self::assertStringContainsString('text-amber', $pres['status_heading_class']);
        self::assertStringContainsString('bg-amber', $pres['progress_bar_class']);
        self::assertSame(100, $pres['display_percent']);
        self::assertSame(100, $pres['lifecycle_percent']);
        self::assertSame('ĐÃ KẾT THÚC — CÓ LỖI', $pres['headline_left']);
        self::assertSame('Lifecycle Progress: 100%', $pres['headline_right']);
        self::assertSame('20 thành công · 4 thất bại · 6 bỏ qua · 0 chưa thực hiện', $pres['summary_text']);
    }

    /**
     * Test 4: Terminal with pending items -> 100%, pending remains "chưa thực hiện".
     * Production scenario: Total: 30, Succeeded: 1, Failed: 3, Skipped: 5, Pending: 21.
     */
    public function test_terminal_with_pending_items_displays_100_percent_and_pending_remains_chua_thuc_hien(): void
    {
        $pres = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'completed',
            snapshotTotal: 30,
            succeeded: 1,
            failed: 3,
            skipped: 5,
            pending: 21,
            processing: 0,
            cancelled: 0,
            isTerminal: true,
        );

        self::assertTrue($pres['is_terminal']);
        self::assertSame(ContentProjectProgressPresentation::STATE_COMPLETED_WITH_ERRORS, $pres['state']);
        self::assertSame('ĐÃ KẾT THÚC — CÓ LỖI', $pres['status_heading']);
        self::assertStringContainsString('text-amber', $pres['status_heading_class']);
        self::assertSame(100, $pres['display_percent']);
        self::assertSame(100, $pres['lifecycle_percent']);
        self::assertSame(16, $pres['raw_percent']);
        self::assertSame('ĐÃ KẾT THÚC — CÓ LỖI', $pres['headline_left']);
        self::assertStringNotContainsString('30/30 bài đã xử lý', $pres['headline_left']);
        self::assertSame('Lifecycle Progress: 100%', $pres['headline_right']);
        self::assertSame('1 thành công · 3 thất bại · 5 bỏ qua · 21 chưa thực hiện', $pres['summary_text']);
        self::assertSame('chưa thực hiện', $pres['pending_label']);
    }

    /**
     * Test 5: Cancelled -> 100%, label "Đã dừng".
     */
    public function test_cancelled_displays_100_percent_with_da_dung_heading(): void
    {
        $pres = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'cancelled',
            snapshotTotal: 30,
            succeeded: 5,
            failed: 1,
            skipped: 2,
            pending: 22,
            processing: 0,
            cancelled: 0,
            isTerminal: true,
        );

        self::assertTrue($pres['is_terminal']);
        self::assertSame(ContentProjectProgressPresentation::STATE_CANCELLED, $pres['state']);
        self::assertSame('ĐÃ DỪNG', $pres['status_heading']);
        self::assertStringContainsString('text-amber', $pres['status_heading_class']);
        self::assertSame(100, $pres['display_percent']);
        self::assertSame('ĐÃ DỪNG', $pres['headline_left']);
        self::assertSame('Lifecycle Progress: 100%', $pres['headline_right']);
        self::assertSame('chưa thực hiện', $pres['pending_label']);

        // Also verify stopped status
        $stopped = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'stopped',
            snapshotTotal: 30,
            succeeded: 5,
            failed: 0,
            skipped: 0,
            pending: 25,
            isTerminal: true,
        );
        self::assertSame('ĐÃ DỪNG', $stopped['status_heading']);
        self::assertSame(100, $stopped['display_percent']);
    }

    /**
     * Test: Failed status displays "TIẾN TRÌNH THẤT BẠI".
     */
    public function test_failed_run_displays_100_percent_with_tien_trinh_that_bai_heading(): void
    {
        $pres = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'failed',
            snapshotTotal: 30,
            succeeded: 0,
            failed: 1,
            skipped: 0,
            pending: 29,
            isTerminal: true,
        );

        self::assertTrue($pres['is_terminal']);
        self::assertSame(ContentProjectProgressPresentation::STATE_FAILED, $pres['state']);
        self::assertSame('TIẾN TRÌNH THẤT BẠI', $pres['status_heading']);
        self::assertStringContainsString('text-danger', $pres['status_heading_class']);
        self::assertStringContainsString('bg-danger', $pres['progress_bar_class']);
        self::assertSame(100, $pres['display_percent']);
    }

    /**
     * Test 6: No false success indicators.
     */
    public function test_no_false_success_indicators(): void
    {
        $pres = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: 'completed',
            snapshotTotal: 30,
            succeeded: 1,
            failed: 3,
            skipped: 5,
            pending: 21,
            isTerminal: true,
        );

        // Failed is never marked as succeeded
        self::assertStringContainsString('1 thành công', $pres['summary_text']);
        self::assertStringContainsString('3 thất bại', $pres['summary_text']);
        self::assertStringNotContainsString('4 thành công', $pres['summary_text']);

        // Pending is never marked as succeeded
        self::assertStringContainsString('21 chưa thực hiện', $pres['summary_text']);
        self::assertStringNotContainsString('22 thành công', $pres['summary_text']);
        self::assertStringNotContainsString('30 thành công', $pres['summary_text']);

        // Math retains exact figures
        $math = ContentProjectProgressPercent::compute(30, 1, 3, 5, true);
        self::assertSame(1, $math['succeeded']);
        self::assertSame(3, $math['failed']);
        self::assertSame(5, $math['skipped']);
        self::assertSame(25, $math['eligible_total']);
        self::assertSame(4, $math['processed']);
        self::assertSame(100, $math['percent']);
        self::assertSame(16, $math['raw_percent']);
    }

    /**
     * Test: Read models expose shared presentation logic.
     */
    public function test_both_read_models_use_shared_presentation(): void
    {
        $projectSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectProgressReadModel::class))->getFileName(),
        );
        $globalSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectProgressPresentation::present', $projectSrc);
        self::assertStringContainsString('ContentProjectProgressPresentation::present', $globalSrc);
        self::assertStringContainsString("'presentation' =>", $projectSrc);
        self::assertStringContainsString("'presentation' =>", $globalSrc);
    }
}
