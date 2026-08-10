<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use Omnichannel\Addons\SearchFoundation\Services\DomainLinkListKeywordSyncService;
use Omnichannel\Addons\SiteSync\Services\Contracts\CapabilityManifestData;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncBatchData;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Architecture freeze for WordPress Site Sync V2.
 */
final class SiteSyncV2ArchitectureFreezeTest extends TestCase
{
    public function test_schema_version_frozen(): void
    {
        self::assertSame('site_sync.v1', SiteSyncSchema::VERSION);
        self::assertSame('1.0.64', SiteSyncSchema::MIN_BRIDGE_VERSION);
        self::assertCount(9, SiteSyncSchema::ORCHESTRATOR_STEPS);
    }

    public function test_capability_manifest_requires_schema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CapabilityManifestData::fromArray(['schema' => 'nope', 'capabilities' => []]);
    }

    public function test_batch_decoder_accepts_delta(): void
    {
        $batch = SiteSyncBatchData::fromArray([
            'schema' => SiteSyncSchema::VERSION,
            'mode' => 'delta',
            'articles' => [['wp_id' => 1]],
            'links' => [['url' => 'https://example.com']],
            'provider_keywords' => [['phrase' => 'a', 'source' => 'provider']],
            'scores' => [['wordpress_id' => 1, 'source' => 'rank_math', 'score' => 80]],
        ]);

        self::assertSame('delta', $batch->mode);
        self::assertCount(1, $batch->articles);
        self::assertCount(1, $batch->links);
    }

    public function test_keyword_priority_manual_first(): void
    {
        self::assertSame(
            ['manual', 'provider', 'workspace'],
            SiteSyncSchema::KEYWORD_PRIORITY,
        );
    }

    public function test_domain_save_does_not_call_keyword_sync_service(): void
    {
        $path = (new ReflectionClass(PersistsDomainPromptContext::class))->getFileName();
        self::assertNotFalse($path);
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('DomainLinkListKeywordSyncService', $src);
        self::assertStringContainsString('SiteLinkCatalogReconciler', $src);
        self::assertTrue(class_exists(DomainLinkListKeywordSyncService::class));
    }

    public function test_feature_flags_class_exists(): void
    {
        self::assertTrue(class_exists(SiteSyncFeatureFlags::class));
    }

    public function test_orchestrator_steps_order(): void
    {
        self::assertSame([
            'detect_capability',
            'request_snapshot_delta',
            'sync_site_profile',
            'sync_url_catalog',
            'sync_provider_keywords',
            'missing_capability_fallback',
            'validate_changed_links',
            'score_missing_articles',
            'finalize',
        ], SiteSyncSchema::ORCHESTRATOR_STEPS);
    }
}
