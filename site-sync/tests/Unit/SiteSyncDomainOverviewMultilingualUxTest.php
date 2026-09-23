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
 * Domain Overview multilingual UX contracts (tabs = status, modal = confirm).
 *
 * Run: vendor/bin/phpunit --filter=SiteSyncDomainOverviewMultilingualUxTest
 */
final class SiteSyncDomainOverviewMultilingualUxTest extends TestCase
{
    private function blade(string $relative): string
    {
        return dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/'.$relative;
    }

    public function test_rendered_sync_buttons_contain_no_literal_js_directive_in_alpine_or_wire(): void
    {
        $tabs = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        $actions = (string) file_get_contents($this->blade('domain-sync-actions.blade.php'));
        $modal = (string) file_get_contents($this->blade('site-sync-preflight-modal.blade.php'));

        self::assertStringNotContainsString('@js($tabKey)', $tabs);
        self::assertStringNotContainsString('@js($tabKey)', $actions);
        self::assertDoesNotMatchRegularExpression('/@(?:click|js).*@js\(/', $tabs);
        self::assertDoesNotMatchRegularExpression('/wire:click="[^"]*@js\(/', $tabs);
        // Blade-rendered language literals for Livewire actions.
        self::assertStringContainsString("wire:click=\"runScopedSiteSyncAction('{{ \$tabKey }}', false)\"", $tabs);
        self::assertStringContainsString("wire:click=\"runScopedSiteSyncAction('{{ \$tabKey }}', true)\"", $tabs);
        self::assertStringContainsString("select('{{ \$tabKey }}')", $tabs);
        self::assertStringContainsString('confirmSiteSyncConfirm', $modal);
        self::assertStringContainsString('cancelSiteSyncConfirm', $modal);
    }

    public function test_scoped_sync_opens_confirmation_without_immediate_dispatch(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $runScoped = $this->extractMethod($domainSrc, 'runScopedSiteSyncAction');
        self::assertStringContainsString('openSiteSyncConfirm', $runScoped);
        self::assertStringNotContainsString('dispatchSiteSyncBus', $runScoped);
        self::assertStringNotContainsString('ForceFullSiteSyncCommand', $runScoped);

        $open = $this->extractMethod($domainSrc, 'openSiteSyncConfirm');
        self::assertStringContainsString('buildConfirmPayload', $open);
        self::assertStringContainsString('siteSyncConfirmOpen = true', $open);
        self::assertStringNotContainsString('dispatchSiteSyncBus', $open);
    }

    public function test_confirm_dispatches_captured_language_role_mode_cancel_dispatches_nothing(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $confirm = $this->extractMethod($domainSrc, 'confirmSiteSyncConfirm');
        self::assertStringContainsString('dispatchCapturedSiteSync', $confirm);

        $dispatch = $this->extractMethod($domainSrc, 'dispatchCapturedSiteSync');
        self::assertStringContainsString("\$payload['language']", $dispatch);
        self::assertStringContainsString("\$payload['language_role']", $dispatch);
        self::assertStringContainsString("\$payload['mode']", $dispatch);
        self::assertStringNotContainsString('resolveForStart', $dispatch);

        $cancel = $this->extractMethod($domainSrc, 'cancelSiteSyncConfirm');
        self::assertStringContainsString('closeSiteSyncConfirm', $cancel);
        self::assertStringNotContainsString('dispatchSiteSyncBus', $cancel);
        self::assertStringNotContainsString('dispatchCapturedSiteSync', $cancel);
    }

    public function test_primary_tab_renders_scoped_health_panels_and_scoring(): void
    {
        $tabs = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        $panel = (string) file_get_contents($this->blade('domain-language-health-panel.blade.php'));

        self::assertStringContainsString('domain-language-health-panel', $tabs);
        self::assertStringContainsString('$isPrimary', $tabs);
        self::assertStringContainsString('Phủ bản dịch', $tabs);
        self::assertStringContainsString('WordPress vs SEO Ops', $panel);
        self::assertStringContainsString('Data Health', $panel);
        self::assertStringContainsString('SEO scoring', $panel);
        self::assertStringContainsString('Source absent', $panel);
        self::assertStringContainsString('Difference', $panel);
        self::assertStringContainsString('Applicable', $panel);
    }

    public function test_secondary_unsynced_and_synced_branches_are_isolated(): void
    {
        $tabs = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        self::assertStringContainsString('Có trên WordPress', $tabs);
        self::assertStringContainsString('Đã sync SEO Ops', $tabs);
        self::assertStringContainsString('không phải lỗi Data Health', $tabs);
        self::assertStringContainsString('$showFullHealth', $tabs);
        self::assertStringContainsString('$isPrimary || $syncedCount > 0', $tabs);
        // Each language panel is its own x-show block — no shared table across languages.
        self::assertStringContainsString("x-show=\"activeTab === '{{ \$tabKey }}'\"", $tabs);

        $panelSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncDomainLanguagePanelService::class))->getFileName()
        );
        self::assertStringContainsString('evaluateLocalOnly($site, $language', $panelSrc);
        self::assertStringContainsString('getWpBackedScoringProgress((int) $site->id, $language', $panelSrc);
    }

    public function test_remote_health_is_lazy_for_active_language_only_and_poll_skips_preflight(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $setTab = $this->extractMethod($domainSrc, 'setOverviewLanguageTab');
        self::assertStringContainsString('fetchRemote: false', $setTab);
        self::assertStringContainsString('fetchRemote: true', $setTab);
        self::assertStringContainsString('remote_fetched', $setTab);

        $mount = $this->extractMethod($domainSrc, 'mount');
        self::assertStringNotContainsString('ensureLanguageTabSnapshot', $mount);
        self::assertStringNotContainsString('withRemote', $mount);

        $refresh = $this->extractMethod($domainSrc, 'refreshSyncProgress');
        self::assertStringNotContainsString('ensureLanguageTabSnapshot', $refresh);
        self::assertStringNotContainsString('SiteSyncPreflightService', $refresh);
        self::assertStringNotContainsString('withRemote', $refresh);

        $progressBlade = (string) file_get_contents($this->blade('site-sync-progress.blade.php'));
        self::assertStringContainsString('wire:poll.3s="refreshSyncProgress"', $progressBlade);
    }

    public function test_sync_action_rendered_once_per_language_and_multilingual_hides_outer_full_site_idle(): void
    {
        $tabs = (string) file_get_contents($this->blade('domain-language-tabs.blade.php'));
        $actions = (string) file_get_contents($this->blade('domain-sync-actions.blade.php'));

        // One action marker in primary branch + one in secondary branch of the foreach template.
        self::assertSame(2, substr_count($tabs, 'data-domain-lang-sync-actions="{{ $tabKey }}"'));
        self::assertStringContainsString('$showOuterSyncButton = ! $isMultilingual', $actions);
        self::assertStringContainsString('@if ($showOuterSyncButton)', $actions);
        // Backend force-full command path preserved (hide UI only).
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        self::assertStringContainsString('ForceFullSiteSyncCommand', $domainSrc);
        self::assertStringContainsString('runForceFullSiteSyncAction', $domainSrc);
    }

    public function test_confirmation_modal_is_action_only_with_captured_scope_fields(): void
    {
        $modal = (string) file_get_contents($this->blade('site-sync-preflight-modal.blade.php'));
        self::assertStringContainsString('Chế độ:', $modal);
        self::assertStringContainsString('Phạm vi:', $modal);
        self::assertStringContainsString('Ước tính:', $modal);
        self::assertStringNotContainsString('WordPress vs SEO Ops', $modal);
        self::assertStringNotContainsString('Data Health', $modal);
        self::assertStringNotContainsString('SEO Ops data health', $modal);

        $panelSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncDomainLanguagePanelService::class))->getFileName()
        );
        self::assertStringContainsString("'scope_label' => \$scopeLabel", $panelSrc);
        self::assertStringContainsString("'estimated_count' => \$estimated", $panelSrc);
        self::assertSame(SiteSyncV3Schema::MODE_DELTA, 'delta');
        self::assertSame(SiteSyncV3Schema::MODE_FORCE_FULL, 'force_full');
    }

    public function test_generic_website_sync_resolves_to_primary_before_confirmation(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $open = $this->extractMethod($domainSrc, 'openSiteSyncPreflight');
        self::assertStringContainsString('primaryLanguage', $open);
        self::assertStringContainsString('openSiteSyncConfirm', $open);
        self::assertStringNotContainsString('dispatchSiteSyncBus', $open);
    }

    public function test_secondary_gate_still_blocks_confirm_open(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $open = $this->extractMethod($domainSrc, 'openSiteSyncConfirm');
        self::assertStringContainsString('evaluateSecondarySync', $open);
        self::assertStringContainsString('LANGUAGE_ROLE_SECONDARY', $open);

        $gateSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3SecondaryGateService::class))->getFileName()
        );
        self::assertStringContainsString('primary_incomplete', $gateSrc);
    }

    public function test_preflight_service_language_scoped_apis_reused(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncPreflightService::class))->getFileName()
        );
        self::assertStringContainsString('function evaluate(Site $site, ?string $language = null)', $src);
        self::assertStringContainsString('function evaluateLocalOnly(Site $site, ?string $language = null)', $src);
    }

    public function test_single_language_outer_sync_button_still_available(): void
    {
        $actions = (string) file_get_contents($this->blade('domain-sync-actions.blade.php'));
        self::assertStringContainsString('openSiteSyncPreflight', $actions);
        self::assertStringContainsString('$showOuterSyncButton = ! $isMultilingual', $actions);
        self::assertStringContainsString('Đồng bộ & kiểm tra website', $actions);
    }

    private function extractMethod(string $src, string $method): string
    {
        if (! preg_match(
            '/(?:public|private|protected) function '.$method.'\([^{]*\{([\s\S]*?)\n    (?:public|private|protected) function /',
            $src,
            $m,
        )) {
            self::fail('Could not extract method '.$method);
        }

        return $m[1];
    }
}
