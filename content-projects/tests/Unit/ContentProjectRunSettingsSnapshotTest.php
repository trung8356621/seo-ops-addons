<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use PHPUnit\Framework\TestCase;

final class ContentProjectRunSettingsSnapshotTest extends TestCase
{
    public function test_snapshot_for_run_preserves_task_ids_and_rerun_flags(): void
    {
        $raw = [
            'generate_post_images' => false,
            'use_php_engine' => true,
            'task_ids' => [427],
            'rerun' => true,
            'rerun_scope' => 'full',
            'rerun_from_step' => 'outline',
            'technical_confirm_full_rerun' => false,
        ];

        $snapshot = ContentProjectRunSettings::snapshotForRun($raw);

        self::assertSame([427], $snapshot['task_ids']);
        self::assertTrue($snapshot['rerun']);
        self::assertSame('full', $snapshot['rerun_scope']);
        self::assertSame('outline', $snapshot['rerun_from_step']);
        self::assertTrue($snapshot['use_php_engine']);
    }

    public function test_to_array_alone_does_not_include_task_ids(): void
    {
        $settings = ContentProjectRunSettings::fromArray([
            'task_ids' => [1, 2],
            'rerun' => true,
            'use_php_engine' => true,
        ]);
        $arr = $settings->toArray();
        self::assertArrayNotHasKey('task_ids', $arr);
        self::assertArrayNotHasKey('rerun', $arr);
    }

    public function test_start_run_source_uses_snapshot_for_run(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('ContentProjectRunSettings::snapshotForRun', $src);
        self::assertStringNotContainsString(
            'ContentProjectRunSettings::fromArray($settings)->toArray()',
            $src,
        );
    }
}
