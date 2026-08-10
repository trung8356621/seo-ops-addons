<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1.8 â€” orchestration stamp isolation (pure unit, no DB).
 */
final class ContentProjectOrchestrationIsolationTest extends TestCase
{
    public function test_stamped_php_ignores_global_and_allowlist_change(): void
    {
        $run = $this->runWith([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'settings' => [
                'use_php_engine' => true,
                'php_engine' => [
                    'orchestration' => 'php',
                    'enabled' => true,
                ],
            ],
        ]);

        self::assertSame('php', ContentProjectRunEngineFeature::orchestrationFor($run));
        self::assertTrue(ContentProjectRunEngineFeature::enabledFor($run));
    }

    public function test_stamped_legacy_ignores_would_be_global_on(): void
    {
        $run = $this->runWith([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'settings' => [
                'use_php_engine' => false,
                'php_engine' => [
                    'orchestration' => 'legacy',
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertSame('legacy', ContentProjectRunEngineFeature::orchestrationFor($run));
        self::assertFalse(ContentProjectRunEngineFeature::enabledFor($run));
    }

    public function test_historical_active_with_active_dispatch_resolves_php(): void
    {
        $run = $this->runWith([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'settings' => [
                'php_engine' => [
                    'active_dispatch' => [
                        'run_item_id' => 1,
                        'token' => 'abc',
                    ],
                ],
            ],
        ]);

        self::assertSame('php', ContentProjectRunEngineFeature::orchestrationFor($run));
    }

    public function test_historical_active_without_php_signals_resolves_legacy(): void
    {
        $run = $this->runWith([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'settings' => [
                'generate_post_images' => false,
            ],
        ]);

        self::assertSame('legacy', ContentProjectRunEngineFeature::orchestrationFor($run));
    }

    public function test_terminal_unstamped_resolves_legacy(): void
    {
        $run = $this->runWith([
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'settings' => [],
        ]);

        self::assertSame('legacy', ContentProjectRunEngineFeature::orchestrationFor($run));
    }

    public function test_create_settings_stamp_php_and_legacy(): void
    {
        $php = ContentProjectRunSettings::fromUserInput(['use_php_engine' => true])->toArray();
        self::assertSame('php', $php['php_engine']['orchestration'] ?? null);

        $legacy = ContentProjectRunSettings::fromUserInput(['use_php_engine' => false])->toArray();
        self::assertSame('legacy', $legacy['php_engine']['orchestration'] ?? null);
    }

    public function test_view_blocks_legacy_mutate_when_php_active(): void
    {
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php'
        );
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringNotContainsString('shouldBlockPhpEngineArticleMutation', $view);
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource.php'
        );
        self::assertStringContainsString("'use_php_engine' => true", $resource);
        self::assertStringContainsString('startGeneratePendingItems', $resource);
    }

    public function test_js_legacy_paths_guard_php_engine(): void
    {
        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/project-run-queue.js'
        );
        foreach (['processQueue', 'startQueue', 'runSingleTask', 'handleStartQueue'] as $fn) {
            self::assertStringContainsString('@deprecated Remove after PHP engine default-on', $js);
            self::assertStringContainsString('phpEngine', $js);
        }
    }

    public function test_poll_is_read_only_source(): void
    {
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php'
        );
        self::assertStringNotContainsString('function pollRunProgress', $view);
        self::assertStringContainsString('redirect', $view);
    }

    public function test_badge_blade_uses_bootstrap_stamp_not_global(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-project-run.blade.php')
        );
        self::assertStringContainsString('engineLabel', $blade);
        self::assertStringContainsString('Engine:', $blade);
        self::assertStringContainsString("orchestration'] ?? '') === 'php'", $blade);
    }

    public function test_feature_has_single_orchestration_resolver(): void
    {
        $ref = new \ReflectionClass(ContentProjectRunEngineFeature::class);
        self::assertTrue($ref->hasMethod('orchestrationFor'));
        self::assertTrue($ref->hasMethod('ensureStamped'));
        self::assertSame('php', ContentProjectRunEngineFeature::ORCHESTRATION_PHP);
        self::assertSame('legacy', ContentProjectRunEngineFeature::ORCHESTRATION_LEGACY);
    }

    /**
     * @param  array{status?: string, settings?: array<string, mixed>}  $attrs
     */
    private function runWith(array $attrs): SeoProjectRun
    {
        $run = new SeoProjectRun;
        $run->forceFill([
            'id' => 1,
            'status' => $attrs['status'] ?? SeoProjectRun::STATUS_RUNNING,
            'settings' => $attrs['settings'] ?? [],
        ]);

        return $run;
    }
}
