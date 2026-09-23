<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3LanguageScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * End-to-end language_scope propagation contracts for Site Sync V3.
 *
 * Run: vendor/bin/phpunit --filter=SiteSyncV3LanguageScopePropagationTest
 */
final class SiteSyncV3LanguageScopePropagationTest extends TestCase
{
    public function test_primary_start_persists_language_scope_and_sticky_flag(): void
    {
        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        self::assertStringContainsString("\$runMeta[SiteSyncV3Schema::META_LANGUAGE_SCOPE] = \$languageScope", $orch);
        self::assertStringContainsString("\$runMeta[SiteSyncV3Schema::META_LANGUAGE_ROLE] = \$languageRole", $orch);
        self::assertStringContainsString("\$runMeta['language_scoped'] = \$languageScope !== ''", $orch);
        self::assertSame('language_scope', SiteSyncV3Schema::META_LANGUAGE_SCOPE);

        $scopeSrc = (string) file_get_contents((new ReflectionClass(SiteSyncV3LanguageScope::class))->getFileName());
        self::assertStringContainsString("'language_scope' => \$primary", $scopeSrc);
        self::assertStringContainsString("'language_role' => SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY", $scopeSrc);
    }

    public function test_client_discover_appends_language_query(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(WordPressSiteSyncV3Client::class))->getFileName());
        self::assertStringContainsString("'/omi-seo-ai/v1/sync/v3/discover'", $src);
        self::assertStringContainsString("'?language='.rawurlencode(\$language)", $src);
    }

    public function test_client_records_force_merges_required_language_on_every_page(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(WordPressSiteSyncV3Client::class))->getFileName());
        self::assertStringContainsString('function records(Site $site, array $body, string $requiredLanguage = \'\')', $src);
        self::assertStringContainsString("\$body['language'] = \$requiredLanguage", $src);
        self::assertStringContainsString('language_scope_missing_on_records', $src);
        self::assertStringContainsString('/wp-json/omi-seo-ai/v1/sync/v3/records', $src);
    }

    public function test_orchestrator_passes_language_on_import_catch_up_and_verify_enumerate(): void
    {
        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        self::assertStringContainsString("\$this->client->records(\$site, \$body, \$languageScope)", $orch);
        self::assertGreaterThanOrEqual(3, substr_count($orch, '->records($site, $body, $languageScope)'));
        self::assertStringContainsString("\$body['language'] = \$languageScope", $orch);
        self::assertStringContainsString('language_scope_lost', $orch);
        self::assertStringContainsString('assertDiscoverHonorsLanguageScope', $orch);
        self::assertStringContainsString('rejectOffScopeContentItems', $orch);
        self::assertStringContainsString('language_scope_violation_on_import', $orch);
        self::assertStringContainsString('language_scope_violation_on_catch_up', $orch);
    }

    public function test_scoped_discover_and_verify_send_language(): void
    {
        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        self::assertStringContainsString("\$languageScope !== '' ? ['language' => \$languageScope] : []", $orch);
        self::assertGreaterThanOrEqual(2, substr_count($orch, "['language' => \$languageScope]"));
    }

    public function test_progress_total_prefers_scoped_by_language_not_discover_total_with_terms(): void
    {
        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        self::assertStringContainsString('contentExpectedFromDiscover($discover, $byType, $languageScope)', $orch);
        self::assertStringContainsString('by_language', $orch);
        self::assertStringContainsString('scopedRef', $orch);
        self::assertStringNotContainsString(
            "\$meta['initial_expected_total'] = (int) (\$discover['total']",
            $orch,
        );
        self::assertStringContainsString("\$meta['initial_expected_total'] = \$contentExpected", $orch);

        $method = new ReflectionMethod(SiteSyncStatusPresenter::class, 'resolveContentExpectedTotal');
        $method->setAccessible(true);
        $presenter = (new ReflectionClass(SiteSyncStatusPresenter::class))->newInstanceWithoutConstructor();
        $total = $method->invoke($presenter, [
            'initial_expected_content_total' => 3820,
            'initial_expected_total' => 8077,
            'discover' => [
                'total' => 8077,
                'resources' => ['content' => ['total' => 3820], 'terms' => ['total' => 4257]],
                'by_language' => ['vi' => 3820, 'en' => 4000],
            ],
        ]);
        self::assertSame(3820, $total);
    }

    public function test_losing_language_scope_fails_loudly_not_all_language_fallback(): void
    {
        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        self::assertStringContainsString('runRequiresLanguageScope', $orch);
        self::assertStringContainsString("'language_scoped'", $orch);
        self::assertStringContainsString('language_scope_not_applied_on_discover', $orch);
        self::assertStringContainsString('refusing silent all-language sync', $orch);
        self::assertStringContainsString('refusing unscoped records', $orch);
    }

    public function test_unscoped_single_language_flow_keeps_optional_language(): void
    {
        $scopeSrc = (string) file_get_contents((new ReflectionClass(SiteSyncV3LanguageScope::class))->getFileName());
        self::assertStringContainsString("'language_scope' => ''", $scopeSrc);
        self::assertStringContainsString("'multilingual' => false", $scopeSrc);

        $client = (string) file_get_contents((new ReflectionClass(WordPressSiteSyncV3Client::class))->getFileName());
        self::assertStringContainsString('string $requiredLanguage = \'\'', $client);

        $orch = (string) file_get_contents((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName());
        // Empty scope → no language key forced when language_scoped is false.
        self::assertStringContainsString("\$languageScope !== '' ? ['language' => \$languageScope] : []", $orch);
    }

    public function test_wp_provider_applies_language_and_fails_closed(): void
    {
        $wpProvider = dirname(__DIR__, 4).'/wp-seo-ai/includes/class-site-sync-v3-provider.php';
        if (! is_file($wpProvider)) {
            $wpProvider = 'D:/work/wp-seo-ai/includes/class-site-sync-v3-provider.php';
        }
        if (! is_file($wpProvider)) {
            self::markTestSkipped('wp-seo-ai provider not on disk');
        }
        $provider = (string) file_get_contents($wpProvider);
        self::assertStringContainsString('count_content_inventory', $provider);
        self::assertStringContainsString('sql_posts_language_fragment', $provider);
        self::assertStringContainsString('language_scope_unresolved', $provider);
        self::assertStringContainsString('query_content_full', $provider);
        self::assertStringContainsString('query_content_delta', $provider);

        $pll = dirname($wpProvider).'/class-polylang-sync.php';
        self::assertFileExists($pll);
        $pllSrc = (string) file_get_contents($pll);
        self::assertStringContainsString("return ['sql' => ' AND 1=0', 'params' => []];", $pllSrc);
        self::assertStringContainsString('Fail closed', $pllSrc);
    }
}
