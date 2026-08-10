<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationNodeJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\ContentProjects\Jobs\DispatchContentProjectAutomationPoliciesJob;
use PHPUnit\Framework\TestCase;

/**
 * Queue routing isolation: WP publish worker must not drain SEO audit / policy / default backlog.
 */
final class QueueRoutingContractTest extends TestCase
{
    public function test_execute_automation_rule_job_resolves_to_automation_critical(): void
    {
        $job = new ExecuteAutomationRuleJob(1);

        self::assertSame(AutomationQueueName::Critical->value, $job->queue);
        self::assertSame('automation-critical', $job->queue);
        self::assertNotSame('default', $job->queue);
        self::assertNotSame(AutomationQueueName::External->value, $job->queue);
    }

    public function test_execute_automation_node_job_defaults_to_automation_not_default(): void
    {
        $job = new ExecuteAutomationNodeJob(1);

        self::assertSame(AutomationQueueName::Automation->value, $job->queue);
        self::assertSame('automation', $job->queue);
        self::assertNotSame('default', $job->queue);
    }

    public function test_wordpress_external_node_queue_name_is_automation_external(): void
    {
        self::assertSame('automation-external', AutomationQueueName::External->value);

        $wpProvider = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/Modules/WordPress/WordPressAutomationModuleProvider.php',
        );
        self::assertStringContainsString('AutomationQueueName::External', $wpProvider);

        $graph = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Services/AutomationGraphExecutionService.php',
        );
        self::assertStringContainsString('->onQueue($this->queueForNode(', $graph);
        self::assertStringContainsString('defaultQueue', $graph);
    }

    public function test_dispatch_content_project_automation_policies_job_resolves_to_automation_policy(): void
    {
        $job = new DispatchContentProjectAutomationPoliciesJob;

        self::assertSame(AutomationQueueName::Policy->value, $job->queue);
        self::assertSame('automation-policy', $job->queue);
        self::assertNotSame('default', $job->queue);
        self::assertNotSame(AutomationQueueName::External->value, $job->queue);
    }

    public function test_audit_link_status_job_resolves_to_seo_audit_not_automation_external(): void
    {
        $job = new AuditLinkStatusJob(10, 20);

        self::assertSame(AuditLinkStatusJob::QUEUE_NAME, $job->queue);
        self::assertSame('seo-audit', $job->queue);
        self::assertNotSame('default', $job->queue);
        self::assertNotSame(AutomationQueueName::External->value, $job->queue);
        self::assertNotSame(AutomationQueueName::Critical->value, $job->queue);
    }

    public function test_schedule_registers_policy_job_on_automation_policy_queue(): void
    {
        $provider = (string) file_get_contents(
            LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'),
        );

        self::assertStringContainsString('DispatchContentProjectAutomationPoliciesJob', $provider);
        self::assertStringContainsString('AutomationQueueName::Policy->value', $provider);
        // Schedule::job($job, $queue) â€” CallbackEvent has no onQueue().
        self::assertStringNotContainsString('->onQueue(\\App\\Addons\\SeoContentAi\\Automation\\BusinessHook\\Enums\\AutomationQueueName::Policy', $provider);
        self::assertStringNotContainsString('->onQueue(AutomationQueueName::Policy', $provider);
        self::assertStringContainsString(
            'new \\Omnichannel\\Addons\\ContentProjects\\Jobs\\DispatchContentProjectAutomationPoliciesJob,',
            $provider,
        );
    }

    public function test_filament_queued_notifications_resolve_to_connection_default_not_wp_queues(): void
    {
        // Filament DB notifications in-app use sendToDatabase (sync). Any Laravel
        // ShouldQueue notification without onQueue() lands on connection default queue.
        $queueConfig = (string) file_get_contents(dirname(__DIR__, 4).'/config/queue.php');
        self::assertStringContainsString("env('DB_QUEUE', 'default')", $queueConfig);

        $notificationSources = [
            ProjectRoot::addonsPath().'/seo/src/Services/SeoNotificationService.php',
            ProjectRoot::addonsPath().'/content-projects/src/Support/CreateArticleWorkflowNotification.php',
            ProjectRoot::addonsPath().'/content/src/Services/TeamChatNotificationService.php',
        ];

        foreach ($notificationSources as $path) {
            self::assertFileExists($path);
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('automation-external', $source, $path);
            self::assertStringNotContainsString('automation-critical', $source, $path);
            self::assertStringNotContainsString("onQueue('seo-audit')", $source, $path);
            self::assertStringNotContainsString('AutomationQueueName::External', $source, $path);
        }
    }

    public function test_worker_isolation_invariants_for_wp_publish_path(): void
    {
        self::assertNotSame(AuditLinkStatusJob::QUEUE_NAME, AutomationQueueName::External->value);
        self::assertNotSame(AutomationQueueName::Policy->value, AutomationQueueName::External->value);
        self::assertNotSame('default', AutomationQueueName::External->value);
        self::assertNotSame('seo-audit', 'automation-external');
    }
}
