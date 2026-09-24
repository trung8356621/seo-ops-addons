<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3LanguageScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Active Site Sync V3 run must expose language_scope and content-scoped progress.
 *
 * Run: vendor/bin/phpunit --filter=SiteSyncV3ScopedStatusPresenterTest
 */
final class SiteSyncV3ScopedStatusPresenterTest extends TestCase
{
    public function test_overview_generic_sync_resolves_and_dispatches_primary_language(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $open = $this->extractMethod($domainSrc, 'openSiteSyncPreflight');
        self::assertStringContainsString('primaryLanguage', $open);
        self::assertStringContainsString('openSiteSyncConfirm', $open);

        $dispatch = $this->extractMethod($domainSrc, 'dispatchCapturedSiteSync');
        self::assertStringContainsString("\$payload['language']", $dispatch);
        self::assertStringContainsString('languageRole:', $dispatch);
        self::assertStringContainsString('RunSiteSyncCommand', $dispatch);

        $scopeSrc = (string) file_get_contents((new ReflectionClass(SiteSyncV3LanguageScope::class))->getFileName());
        self::assertStringContainsString("'language_scope' => \$primary", $scopeSrc);
    }

    public function test_orchestrator_persists_language_scope_on_start(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        self::assertStringContainsString('META_LANGUAGE_SCOPE', $src);
        self::assertStringContainsString('META_LANGUAGE_ROLE', $src);
        self::assertStringContainsString("\$runMeta[SiteSyncV3Schema::META_LANGUAGE_SCOPE] = \$languageScope", $src);
        self::assertSame('language_scope', SiteSyncV3Schema::META_LANGUAGE_SCOPE);
        self::assertSame('language_role', SiteSyncV3Schema::META_LANGUAGE_ROLE);
    }

    public function test_presenter_exposes_active_run_language_and_role(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName());
        self::assertStringContainsString("'language_scope'", $src);
        self::assertStringContainsString("'language_role'", $src);
        self::assertStringContainsString("'scope_label'", $src);
        self::assertStringContainsString('buildRunScopeLabel', $src);
        self::assertStringContainsString('META_LANGUAGE_SCOPE', $src);
        self::assertStringContainsString('META_LANGUAGE_ROLE', $src);
        self::assertStringContainsString("Đang đồng bộ '.\$subject.'", $src);
        self::assertStringNotContainsString("'Đang đồng bộ website · '.\$phaseLabel", $src);
    }

    public function test_primary_progress_denominator_is_content_scoped_not_discover_total_with_terms(): void
    {
        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        self::assertStringContainsString('contentExpectedFromDiscover', $orch);
        self::assertStringContainsString('initial_expected_content_total', $orch);
        self::assertStringContainsString('initial_expected_terms_total', $orch);
        // Must NOT assign discover.total directly as user-facing denom.
        self::assertStringNotContainsString(
            "\$meta['initial_expected_total'] = (int) (\$discover['total']",
            $orch,
        );
        self::assertStringContainsString("\$meta['initial_expected_total'] = \$contentExpected", $orch);
        self::assertStringContainsString('content_fetched', $orch);

        $presenter = (string) file_get_contents((new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName());
        self::assertStringContainsString('resolveContentExpectedTotal', $presenter);
        self::assertStringContainsString('contentTotalFromDiscoverPayload', $presenter);
        self::assertStringContainsString("resources']['content']['total']", $presenter);
        self::assertStringContainsString('by_language', $presenter);
        self::assertStringContainsString('content_fetched', $presenter);
    }

    public function test_secondary_progress_uses_same_content_scoped_path(): void
    {
        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        // Discover always passes run language_scope — secondary included.
        self::assertStringContainsString("['language' => \$languageScope]", $orch);
        self::assertStringContainsString('runLanguageScope', $orch);

        $presenter = (string) file_get_contents((new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName());
        self::assertStringContainsString('LANGUAGE_ROLE_SECONDARY', $presenter);
        self::assertStringContainsString("'Phụ'", $presenter);
    }

    public function test_overview_tab_shows_scoped_active_run_label_from_run_meta(): void
    {
        $progress = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/site-sync-progress.blade.php';
        $actions = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php';
        $progressSrc = (string) file_get_contents($progress);
        $actionsSrc = (string) file_get_contents($actions);
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());

        self::assertStringContainsString('siteSyncScopeLabel', $progressSrc);
        self::assertStringContainsString("'Đang đồng bộ '.\$siteSyncScopeLabel", $progressSrc);
        self::assertStringContainsString('never the selected language tab', $progressSrc);
        self::assertStringContainsString('Đang đồng bộ {{ $siteSyncScopeLabel }}', $actionsSrc);
        self::assertStringContainsString('siteSyncLanguageScope', $domainSrc);
        self::assertStringContainsString("\$status['language_scope']", $domainSrc);
        self::assertStringContainsString("\$status['scope_label']", $domainSrc);
    }

    public function test_switching_tabs_does_not_change_active_run_identity(): void
    {
        $domainSrc = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $setTab = $this->extractMethod($domainSrc, 'setOverviewLanguageTab');
        self::assertStringNotContainsString('siteSyncLanguageScope', $setTab);
        self::assertStringNotContainsString('siteSyncScopeLabel', $setTab);
        self::assertStringContainsString('ensureLanguageTabSnapshot', $setTab);

        $refresh = $this->extractMethod($domainSrc, 'refreshSiteSyncV2Progress');
        self::assertStringContainsString("\$status['language_scope']", $refresh);
        self::assertStringContainsString("\$status['scope_label']", $refresh);
        // Identity comes from presenter/run — not overviewLanguageTab.
        self::assertStringNotContainsString('overviewLanguageTab', $refresh);
    }

    public function test_non_polylang_keeps_generic_website_presentation(): void
    {
        $scopeSrc = (string) file_get_contents((new ReflectionClass(SiteSyncV3LanguageScope::class))->getFileName());
        self::assertStringContainsString("'language_scope' => ''", $scopeSrc);
        self::assertStringContainsString('hasPolylang', $scopeSrc);

        $presenter = (string) file_get_contents((new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName());
        self::assertStringContainsString("\$languageScope === ''", $presenter);
        self::assertStringContainsString(": 'website'", $presenter);

        $actions = (string) file_get_contents(dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php');
        self::assertStringContainsString('site_sync_running_button', $actions);
    }

    public function test_presenter_resolve_content_expected_prefers_resources_content_total(): void
    {
        $method = new ReflectionMethod(SiteSyncStatusPresenter::class, 'resolveContentExpectedTotal');
        self::assertTrue($method->isPrivate());

        $contentMethod = new ReflectionMethod(SiteSyncStatusPresenter::class, 'contentTotalFromDiscoverPayload');
        self::assertTrue($contentMethod->isPrivate());

        $presenter = (new ReflectionClass(SiteSyncStatusPresenter::class))->newInstanceWithoutConstructor();
        $contentMethod->setAccessible(true);
        $total = $contentMethod->invoke($presenter, [
            'total' => 8247,
            'resources' => [
                'content' => ['total' => 3820],
                'terms' => ['total' => 4427],
            ],
            'by_content_type' => ['post' => 3000, 'page' => 800, 'product' => 20],
        ]);
        self::assertSame(3820, $total);

        $method->setAccessible(true);
        $resolved = $method->invoke($presenter, [
            'initial_expected_total' => 8247,
            'discover' => [
                'total' => 8247,
                'resources' => [
                    'content' => ['total' => 3820],
                    'terms' => ['total' => 4427],
                ],
            ],
        ]);
        self::assertSame(3820, $resolved);
    }

    public function test_presenter_scoped_run_prefers_by_language_over_unscoped_content_total(): void
    {
        $contentMethod = new ReflectionMethod(SiteSyncStatusPresenter::class, 'contentTotalFromDiscoverPayload');
        $resolveMethod = new ReflectionMethod(SiteSyncStatusPresenter::class, 'resolveContentExpectedTotal');
        $contentMethod->setAccessible(true);
        $resolveMethod->setAccessible(true);

        $presenter = (new ReflectionClass(SiteSyncStatusPresenter::class))->newInstanceWithoutConstructor();
        $discover = [
            'language' => null,
            'total' => 8247,
            'resources' => [
                'content' => ['total' => 8077],
                'terms' => ['total' => 170],
            ],
            'by_language' => [
                'vi' => 3820,
                'en' => 4257,
            ],
        ];

        self::assertSame(
            3820,
            $contentMethod->invoke($presenter, $discover, 'vi'),
            'Scoped discover fallback must use by_language[vi], not unscoped 8077',
        );
        self::assertSame(
            8077,
            $contentMethod->invoke($presenter, $discover, ''),
            'Unscoped runs keep resources.content.total',
        );

        $resolved = $resolveMethod->invoke($presenter, [
            'language_scope' => 'vi',
            'language_role' => 'primary',
            'initial_expected_total' => 8247,
            // Legacy / stale-worker: no initial_expected_content_total.
            'discover' => $discover,
        ]);
        self::assertSame(3820, $resolved);
    }

    /**
     * @return string
     */
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
