<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalLandingDashboardActivityPresenter;
use PHPUnit\Framework\TestCase;

final class OperationalLandingDashboardActivityPresenterTest extends TestCase
{
    public function test_maps_send_to_publishing_queue_to_vietnamese_copy(): void
    {
        $presented = OperationalLandingDashboardActivityPresenter::present([
            'action' => 'content_project.send_to_publishing_queue',
            'result' => 'success',
            'occurred_at' => '2026-09-22 10:24:00',
            'project_ref' => 'project:12',
            'item_ref' => 'item:99',
        ]);

        self::assertSame('10:24', $presented['time']);
        self::assertSame('Đã chuyển bài sang hàng chờ xuất bản', $presented['message']);
        self::assertStringNotContainsString('content_project', $presented['message']);
        self::assertStringNotContainsString('success', $presented['message']);
        self::assertStringContainsString('Dự án #12', (string) $presented['context']);
    }

    public function test_maps_legacy_plural_prefix_action_key(): void
    {
        $label = OperationalLandingDashboardActivityPresenter::labelFor('content_projects.send_to_publishing_queue');

        self::assertSame('Đã chuyển bài sang hàng chờ xuất bản', $label);
    }

    public function test_failed_result_appends_readable_suffix_without_raw_key(): void
    {
        $presented = OperationalLandingDashboardActivityPresenter::present([
            'action' => 'content_project.schedule',
            'result' => 'failed',
            'occurred_at' => '2026-09-22 08:12:00',
        ]);

        self::assertStringContainsString('không thành công', $presented['message']);
        self::assertStringNotContainsString('content_project.schedule', $presented['message']);
        self::assertStringNotContainsString('failed', strtolower($presented['message']));
        self::assertSame('danger', $presented['tone']);
    }

    public function test_unknown_action_never_echoes_raw_key(): void
    {
        $presented = OperationalLandingDashboardActivityPresenter::present([
            'action' => 'content_project.some_internal_thing',
            'result' => 'success',
            'occurred_at' => '2026-09-22 07:00:00',
        ]);

        self::assertStringNotContainsString('content_project.some_internal_thing', $presented['message']);
        self::assertStringNotContainsString('success', $presented['message']);
    }
}
