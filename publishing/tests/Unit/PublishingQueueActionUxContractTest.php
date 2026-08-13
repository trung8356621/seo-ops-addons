<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStatusLabelBuilder;
use PHPUnit\Framework\TestCase;

final class PublishingQueueActionUxContractTest extends TestCase
{
    public function test_unscheduled_shows_publish_and_schedule_not_recover(): void
    {
        $a = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'unscheduled',
            'article_edit_url' => '/a/1',
        ]);

        self::assertTrue($a['publish_now']);
        self::assertFalse($a['retry_now']);
        self::assertTrue($a['schedule']);
        self::assertTrue($a['remove_from_queue']);
        self::assertFalse($a['show_recover_banner']);
        self::assertFalse($a['publish_now'] && $a['retry_now']);
    }

    public function test_scheduled_shows_publish_and_schedule_actions(): void
    {
        $a = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'scheduled',
            'article_edit_url' => null,
        ]);

        self::assertTrue($a['publish_now']);
        self::assertTrue($a['schedule']);
        self::assertTrue($a['unschedule']);
        self::assertFalse($a['retry_now']);
        self::assertFalse($a['show_recover_banner']);
    }

    public function test_retry_wait_shows_retry_not_recover(): void
    {
        $a = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'retry_wait',
            'last_publish_error' => 'timeout',
        ]);

        self::assertTrue($a['retry_now']);
        self::assertFalse($a['publish_now']);
        self::assertTrue($a['schedule']);
        self::assertTrue($a['remove_from_queue']);
        self::assertFalse($a['show_recover_banner']);
        self::assertFalse($a['publish_now'] && $a['retry_now']);
    }

    public function test_failed_shows_retry_and_error_not_recover(): void
    {
        $a = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'failed',
            'last_publish_error' => 'WP 500',
        ]);

        self::assertTrue($a['retry_now']);
        self::assertFalse($a['publish_now']);
        self::assertFalse($a['schedule']);
        self::assertTrue($a['view_error']);
        self::assertFalse($a['show_recover_banner']);
    }

    public function test_publishing_hides_normal_mutation_actions(): void
    {
        $a = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'publishing',
            'publish_queue_status' => 'processing',
            'publisher_started_at' => now()->toIso8601String(),
            'publish_lease_expires_at' => now()->addMinutes(4)->toIso8601String(),
        ]);

        self::assertFalse($a['publish_now']);
        self::assertFalse($a['retry_now']);
        self::assertFalse($a['schedule']);
        self::assertFalse($a['unschedule']);
        self::assertFalse($a['remove_from_queue']);
        self::assertTrue($a['immediate_disabled']);
        self::assertSame('Bài đang được xuất bản.', $a['immediate_disabled_reason']);
        self::assertFalse($a['show_recover_banner']);
    }

    public function test_recover_only_for_expired_active_publisher(): void
    {
        $stuck = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'publishing',
            'publish_queue_status' => 'processing',
            'publisher_started_at' => now()->subMinutes(20)->toIso8601String(),
            'publish_lease_expires_at' => now()->subMinute()->toIso8601String(),
        ]);
        self::assertTrue($stuck['show_recover_banner']);
        self::assertFalse($stuck['publish_now']);
        self::assertFalse($stuck['retry_now']);

        $retryWait = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'retry_wait',
            'publish_queue_status' => 'waiting',
            'publisher_started_at' => now()->subMinutes(20)->toIso8601String(),
            'publish_lease_expires_at' => now()->subMinute()->toIso8601String(),
        ]);
        self::assertFalse($retryWait['show_recover_banner']);
    }

    public function test_published_only_wordpress_actions(): void
    {
        $a = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'published',
            'article_edit_url' => '/a/1',
            'wp_permalink' => 'https://example.com/post/',
        ]);

        self::assertTrue($a['view_on_wordpress']);
        self::assertFalse($a['publish_now']);
        self::assertFalse($a['retry_now']);
        self::assertFalse($a['schedule']);
        self::assertFalse($a['show_recover_banner']);
    }

    public function test_user_facing_labels_have_no_technical_jargon(): void
    {
        foreach ([
            'unscheduled' => 'Chưa lên lịch',
            'scheduled' => 'Đã lên lịch',
            'awaiting_delivery' => 'Đang chuẩn bị',
            'publishing' => 'Đang xuất bản',
            'retry_wait' => 'Thử lại sau',
            'failed' => 'Không thể xuất bản',
            'published' => 'Đã xuất bản',
        ] as $state => $label) {
            self::assertSame($label, PublishingQueueStatusLabelBuilder::label(['publish_state' => $state]));
        }

        $forbidden = ['claim', 'dispatch', 'lease', 'worker', 'stale', 'publisher_started_at', 'superseded', 'idempotency'];
        foreach ($forbidden as $term) {
            self::assertStringNotContainsString(
                $term,
                PublishingQueueStatusLabelBuilder::label(['publish_state' => 'awaiting_delivery']),
            );
            self::assertStringNotContainsString(
                $term,
                PublishingQueueStatusLabelBuilder::label(['publish_state' => 'publishing']),
            );
        }
    }

    public function test_bulk_summary_formats_succeeded_skipped_failed(): void
    {
        self::assertSame(
            'Đã lên lịch 12 bài.',
            PublishingQueueItemActionsPresenter::bulkSummary('Đã lên lịch', ['succeeded' => 12]),
        );
        self::assertSame(
            'Đã thử lại 10 bài. Bỏ qua 2 bài đang xuất bản.',
            PublishingQueueItemActionsPresenter::bulkSummary('Đã thử lại', [
                'succeeded' => 10,
                'skipped' => 2,
            ]),
        );
        self::assertSame(
            'Không có bài phù hợp.',
            PublishingQueueItemActionsPresenter::bulkSummary('Đã khôi phục', []),
        );
    }

    public function test_bulk_toolbar_has_three_menus_without_legacy_labels(): void
    {
        $toolbar = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-bulk-selection-toolbar.blade.php'),
        );
        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/publishing-queue-item-actions-menu.blade.php'),
        );
        $hub = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/publishing-queue-hub.blade.php'),
        );

        // Only the publishing_queue branch — CP ops variant may still say Lifecycle.
        $pqStart = strpos($toolbar, "\$variant === 'publishing_queue'");
        $pqElse = strpos($toolbar, '@else', $pqStart !== false ? $pqStart : 0);
        self::assertNotFalse($pqStart);
        self::assertNotFalse($pqElse);
        $pqToolbar = substr($toolbar, $pqStart, $pqElse - $pqStart);

        self::assertStringContainsString('Xuất bản', $pqToolbar);
        self::assertStringContainsString('Lịch xuất bản', $pqToolbar);
        self::assertStringContainsString('Thêm', $pqToolbar);
        self::assertStringContainsString('Bỏ khỏi Publishing Queue', $pqToolbar);
        self::assertStringContainsString('Hủy lịch', $pqToolbar);

        self::assertStringNotContainsString('Schedule (+5m)', $pqToolbar);
        self::assertStringNotContainsString('Publish now', $pqToolbar);
        self::assertStringNotContainsString('Retry selected', $pqToolbar);
        self::assertStringNotContainsString('Recover stuck', $pqToolbar);
        self::assertStringNotContainsString('>Cancel<', $pqToolbar);
        self::assertStringNotContainsString('Schedule</span>', $pqToolbar);
        self::assertStringNotContainsString('Publishing</span>', $pqToolbar);
        self::assertStringNotContainsString('Lifecycle</span>', $pqToolbar);

        self::assertStringNotContainsString('Recover stuck', $hub);
        self::assertStringNotContainsString('Recover now (', $hub);
        self::assertStringNotContainsString('recoverOpen = true', $hub);

        self::assertStringContainsString('x-teleport="body"', $menu);
        self::assertStringContainsString('cp-ops-menu--portal', $menu);
        self::assertStringContainsString('reposition()', $menu);
        self::assertStringContainsString('Xuất bản ngay', $menu);
        self::assertStringContainsString('Thử lại ngay', $menu);
        self::assertStringContainsString('Bỏ khỏi Publishing Queue', $menu);
        self::assertStringContainsString('Hủy lịch', $menu);
        self::assertStringContainsString('Quá trình xuất bản bị gián đoạn.', $menu);
        self::assertStringNotContainsString('>Cancel<', $menu);
        self::assertStringNotContainsString('Publish now', $menu);
        self::assertStringNotContainsString('Retry selected', $menu);
        self::assertStringNotContainsString('cp-ops-menu__heading">Publishing', $menu);
        self::assertStringNotContainsString('cp-ops-menu__heading">Lifecycle', $menu);
        self::assertStringNotContainsString('cp-ops-menu__heading">Schedule', $menu);
    }

    public function test_publish_now_and_retry_never_both_true_across_states(): void
    {
        foreach (['unscheduled', 'scheduled', 'awaiting_delivery', 'publishing', 'retry_wait', 'failed', 'published', 'needs_attention'] as $state) {
            $a = PublishingQueueItemActionsPresenter::forRow(['publish_state' => $state]);
            self::assertFalse(
                $a['publish_now'] && $a['retry_now'],
                "state {$state} must not expose both immediate actions",
            );
        }
    }
}
