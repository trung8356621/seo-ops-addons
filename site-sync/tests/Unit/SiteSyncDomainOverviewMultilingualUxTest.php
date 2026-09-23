<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncDomainLanguagePanelService;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3SecondaryGateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Domain Overview multilingual UX: health in language tabs; modal = confirmation only.
 *
 * Run: vendor/bin/phpunit --filter=SiteSyncDomainOverviewMultilingualUxTest
 */
final class SiteSyncDomainOverviewMultilingualUxTest extends TestCase
{
    private function blade(string $relative): string
    {
        return dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/'.$relative;
    }

    public function test_primary_tab_renders_scoped_wp_vs_seo_ops_and_data_health(): void
    {
        $tabs = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        $panel = (string) file_get_contents($this->blade('domain-language-health-panel.blade.php'));

        // Alpine-owned tabs; primary panel shows scoped counts + sync actions.
        self::assertStringContainsString('x-data', $tabs);
        self::assertStringContainsString('$isPrimary', $tabs);
        self::assertStringContainsString('Phủ bản dịch', $tabs);
        self::assertStringContainsString('Đồng bộ &amp; kiểm tra', $tabs);
        self::assertStringContainsString('Có trên WordPress', $tabs);
        // Health panel partial remains available for richer inspect (lazy/remote path).
        self::assertStringContainsString('WordPress vs SEO Ops', $panel);
        self::assertStringContainsString('Data Health', $panel);
        self::assertStringContainsString('SEO scoring', $panel);
    }

    public function test_secondary_data_does_not_leak_into_primary_health_panel(): void
    {
        $panelSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncDomainLanguagePanelService::class))->getFileName()
        );
        // Snapshot is built for one language argument only.
        self::assertStringContainsString('evaluateLocalOnly($site, $language', $panelSrc);
        self::assertStringContainsString('getWpBackedScoringProgress((int) $site->id, $language', $panelSrc);

        $tabs = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        // Each language gets its own x-show panel; primary/secondary are separate branches.
        self::assertStringContainsString('@if ($isPrimary)', $tabs);
        self::assertStringContainsString('@else', $tabs);
        self::assertStringContainsString("x-show=\"activeTab === @js(\$tabKey)\"", $tabs);
    }

    public function test_secondary_unsynced_shows_availability_without_health_warning(): void
    {
        $tabs = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        self::assertStringContainsString('Có trên WordPress', $tabs);
        self::assertStringContainsString('Đã sync SEO Ops', $tabs);
        self::assertStringContainsString('không phải lỗi Data Health', $tabs);
        self::assertStringContainsString('$showFullHealth', $tabs);
        self::assertStringContainsString('$isPrimary || $syncedCount > 0', $tabs);

        $panelSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncDomainLanguagePanelService::class))->getFileName()
        );
        self::assertStringContainsString('$isPrimary || $synced > 0', $panelSrc);
        self::assertStringContainsString("'show_full_health' => \$showFullHealth", $panelSrc);
    }

    public function test_mount_does_not_remote_preflight_all_languages(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        self::assertStringContainsString('Intentionally do NOT remote-preflight all languages on mount', $domainSrc);
        self::assertStringContainsString('languageTabSnapshots', $domainSrc);
        self::assertStringContainsString('ensureLanguageTabSnapshot', $domainSrc);

        $mount = $this->extractMethod($domainSrc, 'mount');
        self::assertStringNotContainsString('evaluate(', $mount);
        self::assertStringNotContainsString('withRemote(', $mount);
        self::assertStringNotContainsString('ensureLanguageTabSnapshot', $mount);
        self::assertStringNotContainsString('getSiteSyncLanguageCoverage', $mount);
        self::assertStringNotContainsString('foreach', $mount);
    }

    public function test_only_active_language_tab_fetches_remote_health(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        self::assertStringContainsString('setOverviewLanguageTab', $domainSrc);
        self::assertStringContainsString('refreshActiveLanguageTabRemote', $domainSrc);
        self::assertStringContainsString('ensureLanguageTabSnapshot($this->overviewLanguageTab, fetchRemote: false)', $domainSrc);
        self::assertStringContainsString('fetchRemote: true', $domainSrc);

        $ensure = $this->extractMethod($domainSrc, 'ensureLanguageTabSnapshot');
        self::assertStringContainsString('withRemote', $ensure);
        self::assertStringContainsString("! (bool) (\$snapshot['remote_fetched'] ?? false)", $ensure);
        self::assertStringContainsString('buildLocalSnapshot', $ensure);

        $setTab = $this->extractMethod($domainSrc, 'setOverviewLanguageTab');
        self::assertStringContainsString('fetchRemote: false', $setTab);
        self::assertStringNotContainsString('fetchRemote: true', $setTab);
        self::assertStringContainsString('#[Renderless]', $domainSrc);

        $tabsSrc = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        self::assertStringContainsString('x-data', $tabsSrc);
        self::assertStringContainsString('sessionStorage', $tabsSrc);
        self::assertStringContainsString("select('overview')", $tabsSrc);
        self::assertStringContainsString('x-show="activeTab', $tabsSrc);
        self::assertStringNotContainsString('wire:click="setOverviewLanguageTab', $tabsSrc);

        $refreshSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        // Status polling must not re-run expensive preflight.
        self::assertDoesNotMatchRegularExpression(
            '/function refreshSiteSyncV2Progress\([\s\S]*?SiteSyncPreflightService/',
            $refreshSrc,
        );
        self::assertDoesNotMatchRegularExpression(
            '/function refreshSiteSyncV2Progress\([\s\S]*?withRemote\(/',
            $refreshSrc,
        );
        $refreshProgress = $this->extractMethod($refreshSrc, 'refreshSyncProgress');
        self::assertStringNotContainsString('ensureLanguageTabSnapshot', $refreshProgress);
    }

    public function test_sync_click_opens_confirmation_without_dispatch(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        self::assertStringContainsString('function runScopedSiteSyncAction', $domainSrc);
        self::assertStringContainsString('openSiteSyncConfirm(', $domainSrc);

        $runScoped = $this->extractMethod($domainSrc, 'runScopedSiteSyncAction');
        self::assertStringNotContainsString('dispatchSiteSyncBus', $runScoped);
        self::assertStringNotContainsString('ForceFullSiteSyncCommand', $runScoped);
        self::assertStringNotContainsString('RunSiteSyncCommand', $runScoped);
        self::assertStringContainsString('openSiteSyncConfirm', $runScoped);

        $openConfirm = $this->extractMethod($domainSrc, 'openSiteSyncConfirm');
        self::assertStringNotContainsString('dispatchSiteSyncBus', $openConfirm);
        self::assertStringContainsString('buildConfirmPayload', $openConfirm);
        self::assertStringContainsString('siteSyncConfirmOpen = true', $openConfirm);
    }

    public function test_confirmation_preserves_captured_language_role_mode(): void
    {
        $panelSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncDomainLanguagePanelService::class))->getFileName()
        );
        self::assertStringContainsString("'language' => \$language", $panelSrc);
        self::assertStringContainsString("'language_role' =>", $panelSrc);
        self::assertStringContainsString("'mode' => \$mode", $panelSrc);
        self::assertStringContainsString("'estimated_count' => \$estimated", $panelSrc);

        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $dispatch = $this->extractMethod($domainSrc, 'dispatchCapturedSiteSync');
        self::assertStringContainsString("\$payload['language']", $dispatch);
        self::assertStringContainsString("\$payload['language_role']", $dispatch);
        self::assertStringContainsString("\$payload['mode']", $dispatch);
        // Must not re-resolve language after dialog opens.
        self::assertStringNotContainsString('resolveForStart', $dispatch);
        self::assertStringNotContainsString('primaryLanguage', $dispatch);
    }

    public function test_confirm_normal_and_force_full_dispatch_scoped_modes(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $dispatch = $this->extractMethod($domainSrc, 'dispatchCapturedSiteSync');
        self::assertStringContainsString('ForceFullSiteSyncCommand', $dispatch);
        self::assertStringContainsString('RunSiteSyncCommand', $dispatch);
        self::assertStringContainsString("mode: 'delta'", $dispatch);
        self::assertStringContainsString('MODE_FORCE_FULL', $dispatch);
        self::assertStringContainsString('language:', $dispatch);
        self::assertStringContainsString('languageRole:', $dispatch);
    }

    public function test_cancel_confirmation_dispatches_nothing(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $cancel = $this->extractMethod($domainSrc, 'cancelSiteSyncConfirm');
        self::assertStringContainsString('closeSiteSyncConfirm', $cancel);
        self::assertStringNotContainsString('dispatchSiteSyncBus', $cancel);
        self::assertStringNotContainsString('dispatchCapturedSiteSync', $cancel);

        $modal = (string) file_get_contents($this->blade('site-sync-preflight-modal.blade.php'));
        self::assertStringContainsString('cancelSiteSyncConfirm', $modal);
        self::assertStringContainsString('Hủy', $modal);
        self::assertStringContainsString('confirmSiteSyncConfirm', $modal);
        self::assertStringNotContainsString('WordPress vs SEO Ops (WP-backed)', $modal);
        self::assertStringNotContainsString('SEO Ops data health', $modal);
    }

    public function test_generic_website_sync_resolves_to_primary_before_confirmation(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $open = $this->extractMethod($domainSrc, 'openSiteSyncPreflight');
        self::assertStringContainsString('primaryLanguage', $open);
        self::assertStringContainsString('openSiteSyncConfirm', $open);
        self::assertStringNotContainsString('evaluate(', $open);
        self::assertStringNotContainsString('dispatchSiteSyncBus', $open);

        $actions = (string) file_get_contents($this->blade('domain-sync-actions.blade.php'));
        self::assertStringContainsString('openSiteSyncPreflight', $actions);
    }

    public function test_secondary_gate_still_blocks_confirm_when_primary_not_ready(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $open = $this->extractMethod($domainSrc, 'openSiteSyncConfirm');
        self::assertStringContainsString('evaluateSecondarySync', $open);
        self::assertStringContainsString('LANGUAGE_ROLE_SECONDARY', $open);
        self::assertStringContainsString('Chưa thể đồng bộ', $open);
        // Gate failure returns before setting confirm open.
        self::assertMatchesRegularExpression(
            '/evaluateSecondarySync[\s\S]*?return;[\s\S]*?siteSyncConfirmOpen = true/',
            $open,
        );

        $gateSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3SecondaryGateService::class))->getFileName()
        );
        self::assertStringContainsString('evaluateSecondarySync', $gateSrc);
        self::assertStringContainsString('primary_incomplete', $gateSrc);
    }

    public function test_confirm_payload_copy_matches_product_copy(): void
    {
        $panelSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncDomainLanguagePanelService::class))->getFileName()
        );
        self::assertStringContainsString('Đồng bộ lại toàn bộ ', $panelSrc);
        self::assertStringContainsString('Tác vụ này sẽ duyệt lại toàn bộ nội dung của ngôn ngữ này.', $panelSrc);
        self::assertStringContainsString('Tác vụ nền này có thể xử lý khoảng ', $panelSrc);
        self::assertStringContainsString('Đồng bộ thay đổi', $panelSrc);
        self::assertStringContainsString('Xác nhận đồng bộ toàn bộ', $panelSrc);
        self::assertSame(SiteSyncV3Schema::MODE_FORCE_FULL, 'force_full');
        self::assertSame(SiteSyncV3Schema::MODE_DELTA, 'delta');
    }

    public function test_preflight_service_exposes_language_scoped_local_api(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncPreflightService::class))->getFileName()
        );
        self::assertStringContainsString('function evaluate(Site $site, ?string $language = null)', $src);
        self::assertStringContainsString('function evaluateLocalOnly(Site $site, ?string $language = null)', $src);
        self::assertStringContainsString("'language' => \$langArg", $src);
    }

    private function extractMethod(string $src, string $method): string
    {
        if (! preg_match(
            '/public function '.$method.'\([^{]*\{([\s\S]*?)\n    (?:public|private|protected) function /',
            $src,
            $m,
        )) {
            // Last method before end of class region — try until next method of any visibility or end.
            if (! preg_match(
                '/(?:public|private|protected) function '.$method.'\([^{]*\{([\s\S]*?)\n    (?:public|private|protected) function /',
                $src,
                $m,
            )) {
                self::fail('Could not extract method '.$method);
            }
        }

        return $m[1];
    }
}
