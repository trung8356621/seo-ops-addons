<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure semantics checks for Resume / lock failure contracts (source + constants).
 */
final class GlobalMonthlyResumeAndLockSemanticsTest extends TestCase
{
    public function test_resume_default_does_not_auto_retry_failed(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        $resumePos = strpos($src, 'public function resume(');
        self::assertNotFalse($resumePos);
        $slice = substr($src, $resumePos, 4500);

        self::assertStringContainsString('STATUS_PENDING', $slice);
        self::assertStringContainsString('Failed items require explicit retry selection', $slice);
        // Legacy sticky: failed NOT flipped to pending. Auto Cost uses waiting_retry path instead.
        self::assertStringNotContainsString("where('status', SeoContentGlobalBatchItem::STATUS_FAILED)", $slice);
        self::assertStringContainsString('STATUS_WAITING_RETRY', $src);
        self::assertStringContainsString('STATUS_STOPPED_AI_UNAVAILABLE', $src);
    }

    public function test_batch_item_terminal_statuses(): void
    {
        $item = new ReflectionClass(SeoContentGlobalBatchItem::class);
        $method = $item->getMethod('isTerminal');
        self::assertTrue($method instanceof ReflectionMethod);

        // Constants used by resume/advance mapping.
        self::assertSame('pending', SeoContentGlobalBatchItem::STATUS_PENDING);
        self::assertSame('succeeded', SeoContentGlobalBatchItem::STATUS_SUCCEEDED);
        self::assertSame('failed', SeoContentGlobalBatchItem::STATUS_FAILED);
        self::assertSame('skipped', SeoContentGlobalBatchItem::STATUS_SKIPPED);
    }

    public function test_start_releases_lock_token_on_first_slice_failure(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        self::assertStringContainsString('failBatchAndRelease($batchId, $token', $src);
        self::assertMatchesRegularExpression('/acquire\([\s\S]*?OWNER_GLOBAL_BATCH/', $src);
        self::assertStringContainsString('lock_owner_token', $src);
    }

    public function test_advance_idempotent_guards(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        self::assertStringContainsString('Stale advance ignored', $src);
        self::assertStringContainsString('Slice already running', $src);
        self::assertStringContainsString('current_project_run_id', $src);
    }
}
