<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
use PHPUnit\Framework\TestCase;

/**
 * Contract: Sync preflight audits required Article data without heavy sync.
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
        self::assertStringNotContainsString('ForceFullSiteSyncCommand', $src);
        self::assertStringNotContainsString('RunSiteSyncOrchestrator', $src);
    }

    public function test_ui_wires_preflight_modal_before_sync(): void
    {
        $actions = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php';
        $modal = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/site-sync-preflight-modal.blade.php';
        $domain = dirname(__DIR__, 3)
            .'/search-foundation/src/Filament/Resources/DomainResource/Pages/GeneralDomain.php';

        self::assertFileExists($actions);
        self::assertFileExists($modal);
        self::assertFileExists($domain);

        $actionsSrc = (string) file_get_contents($actions);
        $modalSrc = (string) file_get_contents($modal);
        $domainSrc = (string) file_get_contents($domain);

        self::assertStringContainsString('openSiteSyncPreflight', $actionsSrc);
        self::assertStringContainsString('site-sync-preflight-modal', $actionsSrc);
        self::assertStringContainsString('SEO Ops data health', $modalSrc);
        self::assertStringContainsString('WordPress vs SEO Ops', $modalSrc);
        self::assertStringContainsString('Difference', $modalSrc);
        self::assertStringContainsString('Đồng bộ thay đổi', $modalSrc);
        self::assertStringContainsString('Đồng bộ toàn bộ', $modalSrc);
        self::assertStringContainsString('Site sync preflight', $modalSrc);
        self::assertStringContainsString('confirmSiteSyncPreflightNormal', $modalSrc);
        self::assertStringContainsString('confirmSiteSyncPreflightFull', $modalSrc);
        self::assertStringContainsString('site-sync-preflight__shell', $modalSrc);
        self::assertStringContainsString('max-height: calc(100dvh - 24px)', $modalSrc);
        self::assertStringContainsString('site-sync-preflight__body', $modalSrc);
        self::assertStringContainsString('site-sync-preflight__footer-actions', $modalSrc);
        self::assertStringContainsString('justify-content: flex-end', $modalSrc);
        self::assertStringContainsString('overscroll-behavior: contain', $modalSrc);
        self::assertStringNotContainsString('max-h-[85vh]', $modalSrc);
        self::assertStringNotContainsString(">Hủy</", $modalSrc);
        self::assertStringNotContainsString('>Hủy</x-filament::button>', $modalSrc);
        self::assertDoesNotMatchRegularExpression('/wire:click="closeSiteSyncPreflight"[\s\S]{0,120}>Hủy</', $modalSrc);
        self::assertStringContainsString('function openSiteSyncPreflight', $domainSrc);

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
