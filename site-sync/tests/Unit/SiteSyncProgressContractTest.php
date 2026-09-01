<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use App\Core\Operations\LongRunningProgress;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Progress\SiteSyncProgressCopy;
use Omnichannel\Addons\SiteSync\Services\Progress\SiteSyncStepCatalog;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncProgressContractTest extends TestCase
{
    public function test_seven_steps_have_human_labels(): void
    {
        self::assertSame(SiteSyncSchema::ORCHESTRATOR_STEPS, SiteSyncStepCatalog::keys());
        self::assertSame(7, SiteSyncStepCatalog::totalSteps());
        self::assertSame(7, SiteSyncStepCatalog::order('finalize'));
        self::assertSame('Kiểm tra khả năng plugin', SiteSyncStepCatalog::label('detect_capability'));
        self::assertSame('Lấy dữ liệu WordPress', SiteSyncStepCatalog::label('request_snapshot_delta'));
        self::assertSame('Hoàn tất dữ liệu SEO', SiteSyncStepCatalog::label('finalize'));
        foreach (SiteSyncStepCatalog::keys() as $key) {
            self::assertNotSame($key, SiteSyncStepCatalog::label($key));
        }
    }

    public function test_running_headline_is_human(): void
    {
        $progress = LongRunningProgress::fromArray([
            'status' => 'running',
            'phase' => 'finalize',
            'step' => 7,
            'total_steps' => 7,
            'current' => 384,
            'total' => 492,
        ]);
        $text = SiteSyncProgressCopy::runningHeadline($progress, 'Hoàn tất dữ liệu SEO');
        self::assertStringContainsString('Bước 7/7', $text);
        self::assertStringContainsString('384', $text);
        self::assertStringContainsString('78%', $text);
        self::assertStringNotContainsString('finalize_snapshot', $text);
    }

    public function test_presenter_exposes_structured_progress_keys(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName());
        self::assertStringContainsString("'task_progress'", $src);
        self::assertStringContainsString("'steps'", $src);
        self::assertStringContainsString("'macro_steps'", $src);
        self::assertStringContainsString("'elapsed_label'", $src);
        self::assertStringContainsString("'last_activity_label'", $src);
        self::assertStringContainsString('SiteSyncStepCatalog', $src);
    }

    public function test_step_runner_checkpoints_and_keeps_metadata_fields(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner::class))->getFileName()
        );
        self::assertStringContainsString('checkpointProgress', $src);
        self::assertStringContainsString('checkpointBatch', $src);
        self::assertStringContainsString("'fields' => SiteSyncSchema::FIELDS_METADATA", $src);
        $trackerSrc = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Progress\SiteSyncProgressTracker::class))->getFileName()
        );
        self::assertStringContainsString('site_sync.progress', $trackerSrc);
        self::assertStringContainsString('last_progress_at', $trackerSrc);
    }
}
