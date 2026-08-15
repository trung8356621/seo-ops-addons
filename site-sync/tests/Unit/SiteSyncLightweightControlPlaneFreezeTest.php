<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\Seo\Services\ArticleSeoSnapshotService;
use Omnichannel\Addons\SiteSync\Jobs\ProcessLinkHealthBatchJob;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RunLinkHealthCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteLinksCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Fallback\WorkspaceFallbackRegistry;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncLightweightControlPlaneFreezeTest extends TestCase
{
    public function test_hot_path_requests_metadata_only_fields(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());
        self::assertStringContainsString('FIELDS_METADATA', $src);
        self::assertStringContainsString("'fields' => SiteSyncSchema::FIELDS_METADATA", $src);
        self::assertSame('metadata', SiteSyncSchema::FIELDS_METADATA);
    }

    public function test_site_sync_import_does_not_persist_body_without_force_overwrite(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SyncDomainContentService::class))->getFileName());
        self::assertStringContainsString('$shouldPersistBody = $forceOverwrite || $isTaxonomy;', $src);
        self::assertStringContainsString('ArticleSeoSnapshotService', $src);
        self::assertStringNotContainsString('validate_changed_links', $src);
    }

    public function test_rewrite_hydrates_from_wordpress_on_demand(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(WordPressArticleContentService::class))->getFileName());
        self::assertStringContainsString('fetchFromWordPress', $src);
        self::assertStringContainsString('resolveEditorHtml', $src);
        self::assertTrue(method_exists(WordPressArticleContentService::class, 'resolveEditorHtml'));
        self::assertTrue(method_exists(ArticleSeoSnapshotService::class, 'isAnalysisStale'));
    }

    public function test_workspace_fallback_does_not_http_head_during_site_sync(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(WorkspaceFallbackRegistry::class))->getFileName());
        self::assertStringContainsString('deferred_to', $src);
        self::assertStringContainsString('link_health_run', $src);
        self::assertStringNotContainsString("\$result = \$this->runHttp404Checker(\$site);", $src);
    }

    public function test_link_health_job_uses_seo_audit_queue_not_seo(): void
    {
        $job = new ProcessLinkHealthBatchJob(1);
        self::assertSame(AuditLinkStatusJob::QUEUE_NAME, $job->queue);
        self::assertSame('seo-audit', $job->queue);
        self::assertNotSame('seo', $job->queue);
    }

    public function test_sync_site_links_command_starts_link_health_not_site_sync_head(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncCommandHandler::class))->getFileName());
        self::assertStringContainsString('startLinkHealth', $src);
        self::assertStringContainsString('SyncSiteLinksCommand', $src);
        self::assertStringContainsString('RunLinkHealthCommand', $src);
        self::assertSame('site.link_health', (new RunLinkHealthCommand(1))->name());
        self::assertSame('site.sync_links', (new SyncSiteLinksCommand(1))->name());
    }

    public function test_client_exposes_heartbeat_and_link_health_batch(): void
    {
        self::assertTrue(method_exists(WordPressSiteSyncClient::class, 'fetchHeartbeat'));
        self::assertTrue(method_exists(WordPressSiteSyncClient::class, 'fetchLinkHealthBatch'));
    }

    public function test_local_engine_capability_keys_are_optional(): void
    {
        self::assertContains('heartbeat', SiteSyncSchema::LOCAL_ENGINE_CAPABILITY_KEYS);
        self::assertContains('metadata_only_articles', SiteSyncSchema::LOCAL_ENGINE_CAPABILITY_KEYS);
        self::assertContains('broken_links_v2', SiteSyncSchema::LOCAL_ENGINE_CAPABILITY_KEYS);
        self::assertNotContains('heartbeat', SiteSyncSchema::CAPABILITY_KEYS);
    }
}
