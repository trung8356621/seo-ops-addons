<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProjectArticleRowStatusResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRowStatus;
use PHPUnit\Framework\TestCase;

final class ContentProjectArticleRowStatusPhase21Test extends TestCase
{
    public function test_active_execution_wins(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'success',
            'workflow_steps' => [
                ['label' => 'Chạy lại FAQ', 'busy' => true, 'status' => 'processing'],
            ],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_RUNNING, $status->code);
        self::assertStringContainsString('FAQ', $status->label);
    }

    public function test_failed_latest_step_label(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'failed',
            'workflow_steps' => [
                ['label' => 'Chạy lại bài viết', 'busy' => false, 'status' => 'failed'],
            ],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_FAILED, $status->code);
        self::assertSame('Lỗi: Chạy lại bài viết', $status->label);
    }

    public function test_ignored_stale_label(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'success',
            'persist_status' => 'ignored_stale',
            'workflow_steps' => [],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_IGNORED_STALE, $status->code);
        self::assertSame('Bỏ qua kết quả AI cũ', $status->label);
    }

    public function test_manual_edit_after_ai(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'success',
            'last_manual_saved_at' => '2026-07-26 16:00:00',
            'last_ai_content_at' => '2026-07-26 15:00:00',
            'workflow_steps' => [],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_MANUAL_EDIT, $status->code);
        self::assertSame('Đã sửa thủ công', $status->label);
    }

    public function test_failed_row_not_running_when_active_execution_absent(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'failed',
            'error_message' => 'AI timeout',
            'active_execution' => null,
            'workflow_steps' => [
                ['label' => 'Dàn ý', 'busy' => false, 'status' => 'failed'],
            ],
        ]);
        self::assertSame(ContentProjectArticleRowStatus::CODE_FAILED, $status->code);
        self::assertNotSame(ContentProjectArticleRowStatus::CODE_RUNNING, $status->code);
    }
}
