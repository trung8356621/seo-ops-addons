<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishResult;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRemovePolicy;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\VideoLastFrameSeek;
use PHPUnit\Framework\TestCase;

final class ScriptRemoveAndMediaContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_migration_adds_tombstones_and_release_media(): void
    {
        $path = $this->root.'/database/migrations/2026_10_22_160000_sa_script_tombstones_and_release_media.php';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        self::assertStringContainsString("create('sa_script_tombstones'", $sql);
        self::assertStringContainsString("create('sa_script_release_media'", $sql);
        self::assertStringContainsString('script_id', $sql);
        self::assertStringContainsString('video_demo', $sql);
        self::assertStringContainsString('thumbnail', $sql);
        self::assertStringContainsString("protected \$connection = 'omi_social_automation'", $sql);
    }

    public function test_remove_policy_allows_when_no_deps_or_history(): void
    {
        $gate = (new ScriptRemovePolicy)->evaluate([], []);
        self::assertTrue($gate['ok']);
        self::assertSame([], $gate['errors']);
    }

    public function test_remove_policy_blocks_dependencies(): void
    {
        $gate = (new ScriptRemovePolicy)->evaluate([
            ['package_key' => 'w1', 'name' => 'Widget 1', 'kind' => 'widget'],
        ], []);
        self::assertFalse($gate['ok']);
        self::assertContains('has_dependencies', $gate['errors']);
        self::assertSame('w1', $gate['dependencies'][0]['package_key']);
    }

    public function test_remove_policy_blocks_job_history_refs(): void
    {
        $gate = (new ScriptRemovePolicy)->evaluate([], [
            ['ref_type' => 'job', 'ref_id' => 'uuid-1'],
        ]);
        self::assertFalse($gate['ok']);
        self::assertContains('referenced_by_job_history', $gate['errors']);
    }

    public function test_sync_plan_skips_tombstoned_and_still_pushes_others(): void
    {
        $plan = (new ScriptSyncPlanner)->plan(
            [
                ['script_id' => 'removed-one', 'package_key' => 'removed-one', 'version' => '1.0.0', 'content_digest' => str_repeat('a', 64)],
                ['script_id' => 'keep-two', 'package_key' => 'keep-two', 'version' => '1.0.0', 'content_digest' => str_repeat('b', 64)],
            ],
            [],
            ['removed-one'],
        );
        self::assertCount(1, $plan['push_to_remote']);
        self::assertSame('keep-two', $plan['push_to_remote'][0]['script_id']);
        self::assertCount(1, $plan['skip']);
        self::assertSame('removed_on_server', $plan['skip'][0]['reason']);
        self::assertSame('removed-one', $plan['skip'][0]['script_id']);
    }

    public function test_publish_result_exposes_removed_on_server_status(): void
    {
        $result = new PackagePublishResult(PackagePublishResult::OUTCOME_REMOVED_ON_SERVER, errors: ['removed_on_server']);
        self::assertSame(409, $result->httpStatus());
        $arr = $result->toArray();
        self::assertFalse($arr['ok']);
        self::assertSame('removed_on_server', $arr['outcome']);
    }

    public function test_last_frame_seek_avoids_start_and_exact_eof(): void
    {
        $seek = new VideoLastFrameSeek;
        self::assertSame(0.0, $seek->seekSeconds(0.0));
        self::assertEqualsWithDelta(9.75, $seek->seekSeconds(10.0), 0.001);
        self::assertGreaterThan(0.0, $seek->seekSeconds(0.2));
        self::assertLessThan(0.2, $seek->seekSeconds(0.2));
    }

    public function test_scripts_ui_is_card_grid_with_remove_and_media(): void
    {
        $blade = (string) file_get_contents($this->root.'/resources/views/filament/pages/scripts.blade.php');
        self::assertStringContainsString('grid-cols-1', $blade);
        self::assertStringContainsString('md:grid-cols-2', $blade);
        self::assertStringContainsString('xl:grid-cols-3', $blade);
        self::assertStringContainsString('aspect-video', $blade);
        self::assertStringContainsString('openRemoveConfirm', $blade);
        self::assertStringContainsString('remove_confirm', $blade);
        self::assertStringContainsString('openVideoPlayer', $blade);
        self::assertStringNotContainsString('<table', $blade);

        $page = (string) file_get_contents($this->root.'/src/Filament/Pages/SocialAutomationScriptsPage.php');
        self::assertStringContainsString('removeScript', $page);
        self::assertStringContainsString('ScriptRemoveService', $page);
        self::assertStringContainsString('assertCanMutate', $page);

        $ui = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptRegistryUiService.php');
        self::assertStringContainsString('updated_by_label', $ui);
        self::assertStringContainsString('video_demo_status', $ui);
        self::assertStringContainsString('widget_dependency_count', $ui);

        $media = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptReleaseMediaService.php');
        self::assertStringContainsString('attachVideoDemo', $media);
        self::assertStringContainsString('deleteForReleases', $media);
        self::assertStringContainsString('last_frame', $media);

        $ffmpeg = (string) file_get_contents($this->root.'/src/PackageRegistry/FfmpegVideoLastFrameExtractor.php');
        self::assertStringContainsString('-ss', $ffmpeg);
        self::assertStringContainsString('-frames:v', $ffmpeg);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('script-media/{mediaId}', $provider);
        self::assertStringContainsString('scripts/video-demo', $provider);

        $publish = (string) file_get_contents($this->root.'/src/PackageRegistry/PackageRegistryService.php');
        self::assertStringContainsString('OUTCOME_REMOVED_ON_SERVER', $publish);
        self::assertStringContainsString('isSuppressed', $publish);
    }

    public function test_idempotent_remove_outcome_constant(): void
    {
        $src = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptRemoveService.php');
        self::assertStringContainsString("outcome' => 'removed_on_server'", $src);
        self::assertStringContainsString('tombstones->record', $src);
        self::assertStringContainsString('collectDependencies', $src);
        self::assertStringContainsString('collectHistoryRefs', $src);

        $policy = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptRemovePolicy.php');
        self::assertStringContainsString('has_dependencies', $policy);
        self::assertStringContainsString('referenced_by_job_history', $policy);
    }
}
