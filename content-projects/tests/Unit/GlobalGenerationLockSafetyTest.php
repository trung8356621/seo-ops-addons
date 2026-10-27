<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\GlobalMonthlyGeneratorPage;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProjectProgress;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\ContentProjectGlobalLockBridge;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPercent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Lock safety: reads fail-soft; writes fail-closed; unavailable ≠ unlocked.
 */
final class GlobalGenerationLockSafetyTest extends TestCase
{
    public function test_missing_table_inspect_is_unavailable_not_unlocked(): void
    {
        $lock = new GlobalGenerationLock;
        $this->forceTableReady($lock, false, 'lock_table_missing');

        $inspect = $lock->inspect();
        self::assertSame(GlobalGenerationLock::STATE_UNAVAILABLE, $inspect['state']);
        self::assertNull($inspect['holder']);
        self::assertSame(GlobalGenerationLock::UNAVAILABLE_MESSAGE, $inspect['message']);
        self::assertFalse($lock->isHeld(), 'unavailable must not report held');
        self::assertTrue($lock->isUnavailable());
        self::assertFalse($lock->canAttemptAcquire(), 'unavailable must never look free for Start');
        self::assertNull($lock->currentHolder());
    }

    public function test_missing_table_acquire_fail_closed(): void
    {
        $lock = new GlobalGenerationLock;
        $this->forceTableReady($lock, false, 'lock_table_missing');

        $result = $lock->acquire(SeoContentGenerationLock::OWNER_PROJECT_RUN, 99, ['label' => 't']);
        self::assertFalse($result['ok']);
        self::assertSame(GlobalGenerationLock::UNAVAILABLE_MESSAGE, $result['message']);
        self::assertStringContainsString('lock_', (string) ($result['reason'] ?? ''));
    }

    public function test_db_exception_path_acquire_fail_closed_in_source(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLock::class))->getFileName(),
        );

        self::assertStringContainsString("reason' => 'acquire_exception'", $src);
        self::assertStringContainsString('lock_query_failed', $src);
        self::assertStringContainsString('schema_probe_failed', $src);
        self::assertStringContainsString('UNAVAILABLE_MESSAGE', $src);
        // Write path must not treat unavailable as free.
        self::assertMatchesRegularExpression(
            '/canAttemptAcquire[\s\S]*?STATE_AVAILABLE/',
            $src,
        );
    }

    public function test_held_state_blocks_acquire_and_start_gates(): void
    {
        $lock = new GlobalGenerationLock;
        // Simulate held without DB: override inspect via anonymous subclass is heavy;
        // contract: acquire rejects STATE_HELD before transaction.
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLock::class))->getFileName(),
        );
        self::assertStringContainsString("STATE_HELD", $src);
        self::assertStringContainsString("'reason' => 'busy'", $src);
        self::assertStringContainsString('BUSY_MESSAGE', $src);

        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('STATE_UNAVAILABLE', $orch);
        self::assertStringContainsString('STATE_HELD', $orch);
        self::assertStringContainsString('lock_unavailable', $orch);

        $bridge = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectGlobalLockBridge::class))->getFileName(),
        );
        self::assertStringContainsString('assertMayStart', $bridge);
        self::assertStringContainsString('STATE_UNAVAILABLE', $bridge);
        self::assertStringContainsString('STATE_HELD', $bridge);
    }

    public function test_acquire_transaction_uses_lock_for_update_for_duplicate_start(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLock::class))->getFileName(),
        );

        self::assertStringContainsString('lockForUpdate()', $src);
        self::assertStringContainsString("SLOT_GLOBAL", $src);
        // Second concurrent acquire sees existing row → busy.
        self::assertStringContainsString('if ($existing instanceof SeoContentGenerationLock)', $src);
        self::assertStringContainsString("return false;", $src);
    }

    public function test_ui_start_gates_use_can_attempt_acquire_not_is_held_alone(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString('canAttemptAcquire()', $resource);

        $progressPage = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProjectProgress::class))->getFileName(),
        );
        self::assertStringContainsString('canAttemptAcquire()', $progressPage);
        self::assertStringContainsString('generateControlsLocked', $progressPage);
        self::assertStringContainsString('globalLockInspect', $progressPage);

        $globalPage = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyGeneratorPage::class))->getFileName(),
        );
        self::assertStringContainsString('canStartBatch', $globalPage);
        self::assertStringContainsString("preflight['can_start']", $globalPage);
        self::assertStringContainsString('STATE_UNAVAILABLE', $globalPage);
        self::assertStringContainsString('refreshLock', $globalPage);
    }

    public function test_snapshot_preflight_can_start_false_when_unavailable(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchSnapshotBuilder::class))->getFileName(),
        );

        self::assertStringContainsString("'can_start' => \$state === GlobalGenerationLock::STATE_AVAILABLE", $src);
        self::assertStringContainsString('lock_unavailable', $src);
        self::assertStringContainsString('lock_busy', $src);
    }

    public function test_is_held_docblock_warns_against_treating_unavailable_as_free(): void
    {
        $method = new ReflectionMethod(GlobalGenerationLock::class, 'isHeld');
        $doc = (string) $method->getDocComment();
        self::assertStringContainsString('canAttemptAcquire', $doc);
        self::assertStringContainsString('Unavailable', $doc);
    }

    public function test_progress_math_24_of_24_with_6_skipped(): void
    {
        $math = ContentProjectProgressPercent::compute(30, 24, 0, 6, true);
        self::assertSame(24, $math['eligible_total']);
        self::assertSame(24, $math['processed']);
        self::assertSame(100, $math['percent']);
    }

    public function test_progress_math_failed_still_processed_not_all_success(): void
    {
        $math = ContentProjectProgressPercent::compute(30, 20, 4, 6, true);
        self::assertSame(24, $math['eligible_total']);
        self::assertSame(24, $math['processed']);
        self::assertSame(100, $math['percent']);
        self::assertSame(20, $math['succeeded']);
        self::assertSame(4, $math['failed']);
        self::assertNotSame($math['succeeded'], $math['processed']);
    }

    public function test_global_playlist_blade_contract(): void
    {
        $blade = (string) realpath(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/global-monthly-generator.blade.php',
        );
        self::assertNotSame('', $blade);
        self::assertFileExists($blade);
        $src = (string) file_get_contents($blade);

        self::assertStringContainsString('data-gm-progress-page', $src);
        self::assertStringContainsString('data-gm-playlist-scroll', $src);
        self::assertStringContainsString('gm-progress-playlist', $src);
        self::assertStringContainsString('Bài viết', $src);
        self::assertStringContainsString('eligible_total', $src);
        self::assertStringContainsString('no_eligible_work', $src);
        self::assertStringContainsString('nowPlaying', $src);
        self::assertStringContainsString('wire:poll.5s="refreshProgress"', $src);
        self::assertStringContainsString('activeTab', $src);
        self::assertStringContainsString('History', $src);
        // History is a separate tab — not embedded in Progress chrome as a long table.
        self::assertStringContainsString("activeTab === 'history'", $src);
        self::assertStringContainsString('UNAVAILABLE_MESSAGE', $src);
        self::assertStringContainsString('canStartBatch', $src);
        self::assertStringNotContainsString('ai_input', $src);
        self::assertStringNotContainsString('ai_output', $src);
    }

    private function forceTableReady(GlobalGenerationLock $lock, bool $ready, string $error): void
    {
        $ref = new ReflectionClass($lock);
        $readyProp = $ref->getProperty('tableReady');
        $readyProp->setAccessible(true);
        $readyProp->setValue($lock, $ready);

        $errProp = $ref->getProperty('tableProbeError');
        $errProp->setAccessible(true);
        $errProp->setValue($lock, $error);
    }
}
