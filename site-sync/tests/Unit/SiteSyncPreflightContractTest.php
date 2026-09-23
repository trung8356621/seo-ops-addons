<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
use PHPUnit\Framework\TestCase;

/**
 * Contract: Sync preflight audits required Article data without heavy sync.
 * Domain Overview: health lives in language tabs; modal is confirmation-only.
 */
final class SiteSyncPreflightContractTest extends TestCase
{
    public function test_preflight_service_uses_lightweight_manifest_not_force_full(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/Preflight/SiteSyncPreflightService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('fetchLightweightManifest', $src);
        self::assertStringContainsString('WordPressSiteSyncV3Client', $src);
        self::assertStringContainsString('protocolV3Enabled', $src);
        self::assertStringContainsString('fetchRemoteCountsViaV3', $src);
        self::assertStringContainsString('summary', $src);
        self::assertStringContainsString('ArticleRequiredDataHealthAuditor', $src);
        self::assertStringContainsString('SiteSyncPreflightContentComparison', $src);
        self::assertStringContainsString('normalizeRemoteDiscover', $src);
        self::assertStringContainsString('countLocal', $src);
        self::assertStringContainsString('evaluateLocalOnly', $src);
        self::assertStringNotContainsString('ForceFullSiteSyncCommand', $src);
        self::assertStringNotContainsString('RunSiteSyncOrchestrator', $src);
    }

    public function test_ui_wires_confirmation_modal_not_full_preflight_inspect(): void
    {
        $actions = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php';
        $modal = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/site-sync-preflight-modal.blade.php';
        $tabs = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/domain-language-tabs.blade.php';
        $health = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/domain-language-health-panel.blade.php';
        $domain = dirname(__DIR__, 3)
            .'/search-foundation/src/Filament/Resources/DomainResource/Pages/GeneralDomain.php';

        self::assertFileExists($actions);
        self::assertFileExists($modal);
        self::assertFileExists($tabs);
        self::assertFileExists($health);
        self::assertFileExists($domain);

        $actionsSrc = (string) file_get_contents($actions);
        $modalSrc = (string) file_get_contents($modal);
        $tabsSrc = (string) file_get_contents($tabs);
        $healthSrc = (string) file_get_contents($health);
        $domainSrc = (string) file_get_contents($domain);

        self::assertStringContainsString('openSiteSyncPreflight', $actionsSrc);
        self::assertStringContainsString('site-sync-preflight-modal', $actionsSrc);
        self::assertStringContainsString('domain-language-tabs', $actionsSrc);

        // Health inspect lives in language tab panels, not the modal.
        self::assertStringContainsString('WordPress vs SEO Ops', $healthSrc);
        self::assertStringContainsString('Data Health', $healthSrc);
        self::assertStringContainsString('Source absent', $healthSrc);
        self::assertStringContainsString('domain-language-health-panel', $tabsSrc);

        // Modal = action confirmation only.
        self::assertStringContainsString('siteSyncConfirm', $modalSrc);
        self::assertStringContainsString('cancelSiteSyncConfirm', $modalSrc);
        self::assertStringContainsString('confirmSiteSyncConfirm', $modalSrc);
        self::assertStringContainsString('Hủy', $modalSrc);
        self::assertStringContainsString('Xác nhận đồng bộ', $modalSrc);
        self::assertStringNotContainsString('WordPress vs SEO Ops (WP-backed)', $modalSrc);
        self::assertStringNotContainsString('SEO Ops data health', $modalSrc);
        self::assertStringNotContainsString('Site sync preflight', $modalSrc);

        self::assertStringContainsString('function openSiteSyncPreflight', $domainSrc);
        self::assertStringContainsString('function openSiteSyncConfirm', $domainSrc);
        self::assertStringContainsString('function confirmSiteSyncConfirm', $domainSrc);
        self::assertStringContainsString('function cancelSiteSyncConfirm', $domainSrc);
        self::assertStringContainsString('dispatchCapturedSiteSync', $domainSrc);

        $serviceSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/Preflight/SiteSyncPreflightService.php',
        );
        self::assertStringContainsString('Khuyến nghị: Đồng bộ toàn bộ', $serviceSrc);
        self::assertStringContainsString('Khuyến nghị: Đồng bộ thay đổi', $serviceSrc);
        self::assertStringContainsString('Dữ liệu đang đồng bộ', $serviceSrc);
        self::assertSame(SiteSyncPreflightService::RECOMMEND_FULL, 'full_sync');
        self::assertSame(SiteSyncPreflightService::RECOMMEND_SYNCED, 'synced');
        self::assertSame(ArticleRequiredDataRegistry::MISSING_YELLOW_MAX, 500);
    }

    public function test_site_health_card_includes_data_health_section(): void
    {
        $presenter = dirname(__DIR__, 3)
            .'/content-projects/src/Services/ContentProject/Operations/SiteHealthCardPresenter.php';
        $src = (string) file_get_contents($presenter);

        self::assertStringContainsString('seo_ops_data', $src);
        self::assertStringContainsString('evaluateLocalOnly', $src);
        self::assertStringContainsString('SEO Ops data health', $src);
        self::assertStringContainsString('DomainLinkInventoryReadModel', $src);
        self::assertStringContainsString('ArticleSeoInventoryPolicy', (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/LinkAnalysis/DomainLinkInventoryReadModel.php',
        ));
        self::assertStringContainsString('not_applicable', (string) file_get_contents(
            dirname(__DIR__, 3).'/content/src/Services/Health/ArticleRequiredDataHealthAuditor.php',
        ));
        self::assertStringContainsString('source_absent', (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/site-health-card.blade.php',
        ));
    }
}
