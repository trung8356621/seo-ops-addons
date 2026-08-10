<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use Omnichannel\Addons\WordPress\Jobs\SyncArticleToWordPressFromQueueJob;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\WordPress\Services\ManualSyncContext;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Manual WordPress = dedicated service + ManualSyncContext; automatic = Automation Rule.
 */
final class ManualAutomationCutoverTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_manual_sync_context_and_job_exist(): void
    {
        self::assertFileExists(ProjectRoot::addonsPath().'/wordpress/src/Services/ManualSyncContext.php');
        self::assertFileExists(ProjectRoot::addonsPath().'/wordpress/src/Jobs/ManualWordPressSyncJob.php');
        self::assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);
        self::assertTrue(class_exists(ManualSyncContext::class));
        self::assertTrue(class_exists(WordPressManualSyncService::class));
        self::assertTrue(class_exists(ManualWordPressSyncJob::class));
    }

    public function test_manual_sync_service_uses_domain_job_not_automation_dispatcher(): void
    {
        $source = $this->readLegacyOrMovedAddonFile('Services/WordPressManualSyncService.php');
        self::assertStringContainsString('ManualWordPressSyncJob', $source);
        self::assertStringContainsString('ManualSyncContext', $source);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $source);
        self::assertStringNotContainsString('AutomationAvailabilityGate', $source);
        self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $source);
        self::assertStringNotContainsString('article.completed', $source);
    }

    public function test_manual_job_emits_wordpress_synced_with_manual_origin(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Jobs/ManualWordPressSyncJob.php',
        );
        self::assertStringContainsString('toSideEffectContext', $source);
        self::assertStringContainsString('wordpressSynced', $source);
        self::assertStringContainsString("'origin' => 'manual'", $source);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $source);
        self::assertStringNotContainsString('ProductReviewPostSyncReconciler', $source);
    }

    public function test_editor_controller_uses_manual_service(): void
    {
        $controller = $this->readLegacyOrMovedAddonFile('Http/Controllers/ArticleEditorSyncController.php');
        self::assertStringContainsString('WordPressManualSyncService', $controller);
        self::assertStringContainsString('article_editor.sync_wordpress', $controller);
        self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $controller);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $controller);
    }

    public function test_legacy_seo_wp_job_is_deprecated_shell(): void
    {
        $job = $this->readLegacyOrMovedAddonFile('Jobs/SyncArticleToWordPressFromQueueJob.php');
        self::assertStringContainsString('DEPRECATED', $job);
        self::assertStringNotContainsString('syncFromEditorBundle', $job);

        $queued = new SyncArticleToWordPressFromQueueJob(1);
        self::assertSame(ArticleWpSyncQueueService::QUEUE_NAME, $queued->queue);
    }

    public function test_queue_service_blocks_legacy_dispatch(): void
    {
        $source = $this->readLegacyOrMovedAddonFile('Services/ArticleWpSyncQueueService.php');
        self::assertStringContainsString('Legacy seo queue orchestration removed', $source);
        self::assertStringContainsString('dispatch_blocked', $source);
    }

    public function test_wordpress_action_is_automatic_only(): void
    {
        $provider = $this->readLegacyOrMovedAddonFile('Automation/Modules/WordPress/WordPressAutomationModuleProvider.php');
        self::assertStringContainsString('supportsManualTrigger: false', $provider);
        self::assertStringContainsString('manualEnabled: false', $provider);
        self::assertStringContainsString('AutomationQueueName::External', $provider);
        self::assertStringContainsString('WordPressManualSyncService', $provider);
    }

    public function test_side_effect_guard_allows_manual_context(): void
    {
        $guard = $this->readLegacyOrMovedAddonFile('Services/SideEffect/WordPressSideEffectGuard.php');
        self::assertStringContainsString('assertManual', $guard);
        self::assertStringNotContainsString('ManualWordPressContext deprecated', $guard);
        self::assertStringContainsString('automation or manual', $guard);
    }

    public function test_execute_rule_job_default_queue_critical(): void
    {
        $job = new ExecuteAutomationRuleJob(99);
        self::assertSame(AutomationQueueName::Critical->value, $job->queue);
    }
}
