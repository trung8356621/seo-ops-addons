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
        self::assertStringContainsString("data-state=\"{{ \$visual }}\"", $src);
        self::assertStringContainsString("'completed'", $src);
        self::assertStringContainsString("'active'", $src);
        self::assertStringContainsString("'pending'", $src);
        self::assertStringContainsString("'failed'", $src);
        self::assertStringContainsString("'retrying'", $src);
        self::assertStringContainsString('text-success-800', $src);
        self::assertStringContainsString('text-success-600', $src);
        self::assertStringContainsString('text-primary-800', $src);
        self::assertStringContainsString('border-l-4 border-primary-600', $src);
        self::assertStringContainsString('bg-primary-100', $src);
        self::assertStringContainsString('text-danger-700', $src);
        self::assertStringContainsString('text-gray-400', $src);
        self::assertStringContainsString('bg-primary-50', $src);
        self::assertStringContainsString('site_sync_technical_details', $src);
        self::assertStringContainsString('site_sync_steps_heading', $src);
        self::assertStringContainsString('site_sync_step_progress', $src);
        self::assertStringContainsString('siteSyncV2SourceChips', $src);
        self::assertStringContainsString("! in_array(\$st, ['completed', 'skipped', 'failed'], true)", $src);
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
    }

    public function test_api_key_layout_does_not_stretch_sibling_cards(): void
    {
        $cssPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'css'.DIRECTORY_SEPARATOR.'domain-overview.css';
        self::assertFileExists($cssPath);
        $css = (string) file_get_contents($cssPath);
        self::assertStringContainsString('.seo-api-key-layout__aside', $css);
        self::assertMatchesRegularExpression(
            '/\.seo-api-key-layout__aside\s*\{[^}]*align-items:\s*start/s',
            $css,
        );
        self::assertStringContainsString('.seo-api-key-layout__aside > *', $css);
        self::assertStringContainsString('align-self: start', $css);
        self::assertStringContainsString('.domain-site-health__sections', $css);
        self::assertDoesNotMatchRegularExpression(
            '/\.seo-api-key-layout\s*\{[^}]*flex-direction:\s*row;[^}]*align-items:\s*stretch/s',
            $css,
        );

        $general = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/general-domain.blade.php',
        );
        $src = (string) file_get_contents($general);
        self::assertStringContainsString('seo-api-key-layout__aside', $src);
        self::assertStringContainsString('partials.wp-plugin-bridge-status', $src);
        self::assertStringContainsString('partials.site-health-card', $src);

        $health = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/partials/site-health-card.blade.php',
        );
        $healthSrc = (string) file_get_contents($health);
        self::assertStringContainsString('domain-site-health__sections', $healthSrc);
        self::assertStringNotContainsString('sm:grid-cols-2', $healthSrc);
        self::assertStringContainsString('border-l-success-600', $healthSrc);
        self::assertStringContainsString("'running'", $healthSrc);
        self::assertStringNotContainsString('class="mt-6 ', $healthSrc);
        self::assertStringNotContainsString('h-full', $healthSrc);
    }
}
