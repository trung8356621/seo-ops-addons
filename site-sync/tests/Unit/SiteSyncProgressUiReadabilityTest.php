<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

final class SiteSyncProgressUiReadabilityTest extends TestCase
{
    public function test_progress_partial_maps_semantic_states_and_keeps_technical_details(): void
    {
        $path = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/partials/site-sync-progress.blade.php',
        );
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('site-sync-progress', $src);
        self::assertStringContainsString('site-sync-step', $src);
        self::assertStringContainsString('site-sync-progress__macro', $src);
        self::assertStringContainsString('data-macro-count', $src);
        self::assertStringContainsString('siteSyncV3MacroSteps', $src);
        self::assertStringContainsString('site_sync_phases_technical', $src);
        self::assertStringContainsString('site_sync_technical_details', $src);
        self::assertStringContainsString("data-state=\"{{ \$visual }}\"", $src);
        self::assertStringContainsString("'completed'", $src);
        self::assertStringContainsString("'active'", $src);
        self::assertStringContainsString("'pending'", $src);
        self::assertStringContainsString("'failed'", $src);
        self::assertStringContainsString('Đồng bộ hoàn tất', $src);
        self::assertStringNotContainsString('site_sync_step_of', $src);
        self::assertStringNotContainsString('Step 0/7', $src);
        self::assertStringNotContainsString('Step 1/7', $src);
        self::assertStringNotContainsString('text-[10px]', $src);
        self::assertStringNotContainsString('text-[11px]', $src);
    }

    public function test_domain_sync_actions_includes_shared_progress_partial(): void
    {
        $blade = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php',
        );
        $src = (string) file_get_contents($blade);
        self::assertStringContainsString('partials.site-sync-progress', $src);
        self::assertStringContainsString('site_sync_running_button', $src);
        self::assertStringContainsString('siteSyncV2Cancellable', $src);
        self::assertStringContainsString('Đồng bộ & kiểm tra website', $src);
        self::assertStringNotContainsString('Đồng bộ lại toàn bộ website', $src);
        self::assertStringNotContainsString('Chấm lại toàn bộ bài viết', $src);
        self::assertStringNotContainsString('Test sync (Debug)', $src);
        self::assertStringNotContainsString('test_sync_debug', $src);
        self::assertStringNotContainsString('wire:model.live="siteSyncForceFull"', $src);
        self::assertStringNotContainsString('runRequeueAllSeoScoringAction', $src);
    }

    public function test_overview_sections_use_consistent_vertical_spacing(): void
    {
        $cssPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'css'.DIRECTORY_SEPARATOR.'domain-overview.css';
        self::assertFileExists($cssPath);
        $css = (string) file_get_contents($cssPath);
        self::assertMatchesRegularExpression(
            '/\.seo-domain-overview\s*\{[^}]*gap:\s*1\.25rem/s',
            $css,
        );
        self::assertStringContainsString('.seo-domain-overview__section', $css);
        self::assertStringContainsString('.seo-connection-summary', $css);
        self::assertStringContainsString('.domain-site-health__ops', $css);

        $general = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/general-domain.blade.php',
        );
        $src = (string) file_get_contents($general);
        self::assertStringContainsString('seo-domain-overview', $src);
        self::assertStringContainsString('seo-domain-overview__section', $src);
        self::assertStringContainsString('partials.wp-plugin-bridge-status', $src);
        self::assertStringContainsString('partials.site-health-card', $src);
        self::assertStringContainsString('heading">Connection', $src);
        self::assertStringContainsString('heading">Website operations', $src);
        self::assertStringNotContainsString('seo-api-key-layout__aside', $src);
        self::assertStringNotContainsString("'showTest'", $src);
        self::assertStringNotContainsString('Đồng bộ lại toàn bộ website', $src);
        self::assertStringNotContainsString('Chấm lại toàn bộ bài viết', $src);
        self::assertStringNotContainsString('Test sync (Debug)', $src);

        $health = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/partials/site-health-card.blade.php',
        );
        $healthSrc = (string) file_get_contents($health);
        self::assertStringContainsString('domain-site-health__sections', $healthSrc);
        self::assertStringContainsString('domain-site-health__ops', $healthSrc);
        self::assertStringContainsString('domain-site-health__metrics', $healthSrc);
        self::assertStringContainsString('domain-site-health__metric', $healthSrc);
        self::assertStringContainsString('Publishing', $healthSrc);
        self::assertStringContainsString('Site Sync', $healthSrc);
        self::assertStringContainsString('SEO Data', $healthSrc);
        self::assertStringContainsString("'Links'", $healthSrc);
        self::assertStringContainsString('Internal links', $healthSrc);
        self::assertStringContainsString('Orphan pages', $healthSrc);
        self::assertStringContainsString('Link opportunities', $healthSrc);
        self::assertStringContainsString('Broken links', $healthSrc);
        self::assertStringContainsString('Technical details', $healthSrc);
        self::assertStringContainsString('border-l-success-600', $healthSrc);
        self::assertStringContainsString("'running'", $healthSrc);
        self::assertStringNotContainsString('h-full', $healthSrc);
        self::assertStringNotContainsString('Internal links<br>', $healthSrc);
        self::assertStringNotContainsString('grid-cols-2 gap-2 text-[12px]', $healthSrc);

        self::assertStringContainsString('.domain-site-health__metrics', $css);
        self::assertStringContainsString('.domain-site-health__metric-value', $css);

        $bridge = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/partials/wp-plugin-bridge-status.blade.php',
        );
        $bridgeSrc = (string) file_get_contents($bridge);
        self::assertStringContainsString('seo-connection-summary', $bridgeSrc);
        self::assertStringContainsString('Check status', $bridgeSrc);
        self::assertStringContainsString('Check version', $bridgeSrc);
        self::assertStringContainsString('Update Bridge', $bridgeSrc);
    }
}
