<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\WordPress\Jobs\SyncArticleToWordPressFromQueueJob;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Cutover invariants: no automatic WordPress outside Automation Engine.
 */
final class WordpressCutoverCouplingTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    /** @var list<string> */
    private const FORBIDDEN_AUTOMATIC = [
        'Services/CreateArticlesFromTaskService.php',
        'Services/SeoProjectWorkflowRunService.php',
        'Services/PromptTestPublishService.php',
        'Services/SeoProjectTaskLifecycleService.php',
        'Services/SeoProjectArchiveService.php',
        'Services/ArticleScheduleReconcileService.php',
        'Services/ScheduledArticlePublishRunner.php',
    ];

    /** @var list<string> */
    private const NEEDLES = [
        'WordPressArticleSyncService',
        'ArticleWpSyncQueueService',
        'SyncArticleToWordPressFromQueueJob',
        'WordPressManualSyncService',
        'publishForArticle',
        'syncForArticle',
        'ensureWordPressPostForArticle',
    ];

    public function test_automatic_production_callers_do_not_touch_wordpress_outbound(): void
    {
        foreach (self::FORBIDDEN_AUTOMATIC as $relative) {
            $path = $this->resolveLegacyOrMovedAddonPath($relative);
            self::assertFileExists($path, $relative);
            $source = (string) file_get_contents($path);
            foreach (self::NEEDLES as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $source,
                    "{$relative} must not reference {$needle}",
                );
            }
        }
    }

    public function test_execute_automation_rule_job_defaults_to_automation_critical_not_default(): void
    {
        $job = new ExecuteAutomationRuleJob(42);
        self::assertSame(AutomationQueueName::Critical->value, $job->queue);
        self::assertNotSame('default', $job->queue);
    }

    public function test_legacy_wp_queue_job_targets_seo_not_default(): void
    {
        $job = new SyncArticleToWordPressFromQueueJob(7);
        self::assertSame(ArticleWpSyncQueueService::QUEUE_NAME, $job->queue);
        self::assertSame('seo', $job->queue);
    }

    public function test_manual_and_automation_entry_points_exist(): void
    {
        self::assertFileExists(ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressManualSyncService.php');
        self::assertFileExists(ProjectRoot::addonsPath().'/wordpress/src/Services/ManualSyncContext.php');
        self::assertFileExists(ProjectRoot::addonsPath().'/wordpress/src/Jobs/ManualWordPressSyncJob.php');
        self::assertFileExists(ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php');
        self::assertFileExists(ProjectRoot::addonsPath().'/wordpress/src/Console/AutomationAuditWordpressCouplingCommand.php');

        $controller = (string) file_get_contents(ProjectRoot::addonsPath().'/content/src/Http/Controllers/ArticleEditorSyncController.php');
        self::assertStringContainsString('WordPressManualSyncService', $controller);
        self::assertStringNotContainsString('ArticleWpSyncQueueService', $controller);
        self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $controller);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $controller);
    }

    public function test_execution_service_cancels_when_rule_disabled(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Services/AutomationExecutionService.php',
        );
        self::assertStringContainsString('RuleDisabled', $source);
        self::assertStringContainsString('is_enabled', $source);
        self::assertStringContainsString('cancellation_requested_at', $source);
    }

    public function test_rule_disable_requests_cancellation_of_pending_executions(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Services/AutomationRuleService.php',
        );
        self::assertStringContainsString('cancellation_requested_at', $source);
        self::assertStringContainsString('findConflictingWordpressRules', $source);
    }

    public function test_wordpress_module_declares_automation_external_queue(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/Modules/WordPress/WordPressAutomationModuleProvider.php',
        );
        self::assertStringContainsString('AutomationQueueName::External', $source);
        self::assertSame('automation-external', AutomationQueueName::External->value);
        self::assertSame('automation-critical', AutomationQueueName::Critical->value);
        self::assertSame('automation-policy', AutomationQueueName::Policy->value);
    }
}
