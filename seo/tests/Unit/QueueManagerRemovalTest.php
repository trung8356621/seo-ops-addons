<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;






use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\AutomationOperationsDashboard;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Queue Manager UI removed: Laravel Queue stays backend-only.
 */
final class QueueManagerRemovalTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_queue_manager_page_and_service_are_gone(): void
    {
        $base = ProjectRoot::addonsPath().'/seo-content-ai-compat';

        self::assertFileDoesNotExist($base.'/Filament/Pages/SeoQueueManager.php');
        self::assertFileDoesNotExist($base.'/resources/views/filament/pages/seo-queue-manager.blade.php');
        self::assertFileDoesNotExist($base.'/resources/views/components/global-queue-worker-alert.blade.php');
        self::assertFileDoesNotExist($base.'/Services/SeoQueueControlService.php');
        self::assertFalse(class_exists(\Omnichannel\Addons\Seo\Filament\Pages\SeoQueueManager::class));
        self::assertFalse(class_exists(\App\Addons\SeoContentAi\Services\SeoQueueControlService::class));
    }

    public function test_automation_nav_targets_remain_registered(): void
    {
        self::assertTrue(class_exists(AutomationRuleResource::class));
        self::assertTrue(class_exists(AutomationExecutionResource::class));
        self::assertTrue(class_exists(AutomationOperationsDashboard::class));
        self::assertTrue(class_exists(\Omnichannel\Addons\Agent\Filament\Pages\AutomationFlowsPage::class));

        $rule = $this->readLegacyOrMovedAddonFile('Filament/Resources/AutomationRuleResource.php');
        $execution = $this->readLegacyOrMovedAddonFile('Filament/Resources/AutomationExecutionResource.php');
        $ops = $this->readLegacyOrMovedAddonFile('Filament/Pages/AutomationOperationsDashboard.php');
        $flows = $this->readLegacyOrMovedAddonFile('Filament/Pages/AutomationFlowsPage.php');

        self::assertStringContainsString('BelongsToAdminAutomationPanel', $rule);
        self::assertStringContainsString('BelongsToAdminAutomationPanel', $execution);
        self::assertStringContainsString('BelongsToAdminAutomationPanel', $ops);
        self::assertStringContainsString('BelongsToAdminAutomationPanel', $flows);
        self::assertStringContainsString("slug = 'automation-rules'", $rule);
        self::assertStringContainsString("slug = 'automation-executions'", $execution);
        self::assertStringContainsString("slug = 'automation/operations'", $ops);
        self::assertStringContainsString("slug = 'automation/flows'", $flows);
        self::assertStringNotContainsString('extends SeoPanelResource', $rule);
        self::assertStringNotContainsString('extends SeoPanelPage', $ops);

        $trait = $this->readLegacyOrMovedAddonFile('Filament/Concerns/BelongsToAdminAutomationPanel.php');
        self::assertStringContainsString("'Automation'", $trait);
        self::assertStringContainsString("=== 'admin'", $trait);
        self::assertStringContainsString('getNavigationGroup', $trait);
    }

    public function test_panel_provider_has_no_queue_worker_banner_hook(): void
    {
        $source = (string) file_get_contents(
            LegacyAddonPath::resolve('Providers/SeoPanelProvider.php')
        );

        self::assertStringNotContainsString('SeoQueueManager', $source);
        self::assertStringNotContainsString('SeoQueueControlService', $source);
        self::assertStringNotContainsString('global-queue-worker-alert', $source);
        self::assertStringNotContainsString('shouldShowOfflineAlert', $source);
        self::assertStringNotContainsString('CONTENT_START', $source);
    }

    public function test_user_facing_queue_manager_strings_removed_from_locales(): void
    {
        $base = LegacyAddonPath::resolve('lang');

        foreach (['en/filament.php', 'vi/filament.php'] as $relative) {
            $source = (string) file_get_contents($base.'/'.$relative);

            self::assertStringNotContainsString("'queue_manager'", $source, $relative);
            self::assertStringNotContainsString("'global_queue_alert'", $source, $relative);
            self::assertStringNotContainsString('Queue manager', $source, $relative);
            self::assertStringNotContainsString('Open Queue manager', $source, $relative);
            self::assertStringNotContainsString('Queue worker is offline', $source, $relative);
            self::assertStringNotContainsString('Pause audit queue', $source, $relative);
            self::assertStringNotContainsString('Stop pending audits', $source, $relative);
            self::assertStringNotContainsString('queue:work', $source, $relative);
        }
    }

    public function test_audit_and_link_map_no_longer_depend_on_queue_pause_controls(): void
    {
        $job = $this->readLegacyOrMovedAddonFile('Jobs/AuditLinkStatusJob.php');
        $service = $this->readLegacyOrMovedAddonFile('Services/LinkMapStatusAuditService.php');

        self::assertStringNotContainsString('SeoQueueControlService', $job);
        self::assertStringNotContainsString('isPausedForSite', $job);
        self::assertStringNotContainsString('SeoQueueControlService', $service);
        self::assertStringNotContainsString('isPausedForSite', $service);
        self::assertStringContainsString('AuditLinkStatusJob::dispatch', $service);
    }

    public function test_automation_operations_dashboard_keeps_execution_ops(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-foundation/src/Filament/Pages/AutomationOperationsDashboard.php'
        );

        self::assertStringContainsString('recoverStale', $source);
        self::assertStringContainsString('retry', strtolower($source));
        self::assertStringNotContainsString('queue:work', $source);
        self::assertStringNotContainsString('SeoQueueControlService', $source);
        self::assertStringNotContainsString('worker_status', $source);
        self::assertStringNotContainsString('pending_work_total', $source);
    }
}
