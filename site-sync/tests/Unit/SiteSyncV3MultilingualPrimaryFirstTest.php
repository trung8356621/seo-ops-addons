<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3CheckpointStore;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3LanguageScope;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3SecondaryGateService;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;
use Omnichannel\Addons\WordPress\Services\ArticlePolylangSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Primary-language-first Site Sync V3 multilingual contracts (source-structure).
 * Runtime Polylang persistence covered by ArticlePolylangLanguageContractTest when MySQL is available.
 *
 * Run: vendor/bin/phpunit --filter=SiteSyncV3MultilingualPrimaryFirstTest
 */
final class SiteSyncV3MultilingualPrimaryFirstTest extends TestCase
{
    public function test_schema_exposes_language_scope_constants(): void
    {
        self::assertSame('language_scope', SiteSyncV3Schema::META_LANGUAGE_SCOPE);
        self::assertSame('language_role', SiteSyncV3Schema::META_LANGUAGE_ROLE);
        self::assertSame('primary', SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY);
        self::assertSame('secondary', SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY);
        self::assertSame('seo_site_sync_v3_language_checkpoints', SiteSyncV3Schema::META_LANGUAGE_CHECKPOINTS);
    }

    public function test_importer_wires_polylang_apply_from_sync_item_without_auto_creating_secondaries(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );
        self::assertStringContainsString('ArticlePolylangSyncService', $src);
        self::assertStringContainsString('applyFromSyncItem', $src);

        $polylangSrc = (string) file_get_contents(
            (new ReflectionClass(ArticlePolylangSyncService::class))->getFileName()
        );
        // bindTranslationGroup only updates existing siblings — never creates rows.
        self::assertStringContainsString('function bindTranslationGroup', $polylangSrc);
        self::assertStringNotContainsString('SeoArticle::query()->create', $polylangSrc);
        self::assertStringContainsString('META_TRANSLATION_MAP', $polylangSrc);
    }

    public function test_checkpoint_store_is_language_keyed(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3CheckpointStore::class))->getFileName()
        );
        self::assertStringContainsString('META_LANGUAGE_CHECKPOINTS', $src);
        self::assertStringContainsString('resolveDeltaCheckpoint', $src);
        self::assertStringContainsString('hasSuccessfulBaseline', $src);
        self::assertStringContainsString('isPrimary', $src);
    }

    public function test_secondary_gate_requires_primary_health_and_freshness(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3SecondaryGateService::class))->getFileName()
        );
        self::assertStringContainsString('evaluateSecondarySync', $src);
        self::assertStringContainsString('checkPrimaryFreshness', $src);
        self::assertStringContainsString('primary_incomplete', $src);
        self::assertStringContainsString('primary_stale', $src);
        self::assertStringContainsString('translationCoverageSummary', $src);
        self::assertStringContainsString('site_sync_busy', $src);
    }

    public function test_language_scope_resolver_defaults_primary(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3LanguageScope::class))->getFileName()
        );
        self::assertStringContainsString('SitePrimaryLanguageService', $src);
        self::assertStringContainsString('resolveForStart', $src);
        self::assertStringContainsString('LANGUAGE_ROLE_PRIMARY', $src);
        self::assertStringContainsString('LANGUAGE_ROLE_SECONDARY', $src);
    }

    public function test_orchestrator_scopes_membership_sensitive_phases(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        self::assertStringContainsString('META_LANGUAGE_SCOPE', $src);
        self::assertStringContainsString("body['language']", $src);
        self::assertStringContainsString('checkpointStore', $src);
        self::assertStringContainsString('secondaryGate', $src);
        self::assertStringContainsString('runLanguageScope', $src);
        self::assertStringContainsString('never soft-delete other languages', $src);
    }

    public function test_scoring_queue_accepts_language_filter(): void
    {
        $method = new ReflectionMethod(SeoArticleScoringQueueService::class, 'domainWpBackedProgress');
        self::assertSame(2, $method->getNumberOfParameters());

        $src = (string) file_get_contents(
            (new ReflectionClass(SeoArticleScoringQueueService::class))->getFileName()
        );
        self::assertStringContainsString("where('articles.language'", $src);
        self::assertStringContainsString("context['language']", $src);
    }

    public function test_wp_v3_contract_requires_multilingual_on_content_upsert(): void
    {
        $wpProvider = dirname(__DIR__, 4).'/wp-seo-ai/includes/class-site-sync-v3-provider.php';
        if (! is_file($wpProvider)) {
            // Workspace may not mount wp-seo-ai beside addons — skip cross-repo path.
            $wpProvider = 'D:/work/wp-seo-ai/includes/class-site-sync-v3-provider.php';
        }
        if (! is_file($wpProvider)) {
            self::markTestSkipped('wp-seo-ai provider not on disk');
        }
        $src = (string) file_get_contents($wpProvider);
        self::assertStringContainsString('multilingual_field_for_post', $src);
        self::assertStringContainsString('by_language', $src);
        self::assertStringContainsString('language_scope', $src);
    }
}
