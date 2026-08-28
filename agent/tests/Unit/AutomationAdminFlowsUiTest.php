<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Tests\Support\ProjectRoot;

use Omnichannel\Addons\Agent\Automation\Presentation\AutomationFlowPresentationRegistry;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationFlowsPage;
use Omnichannel\Addons\Seo\Filament\Concerns\BelongsToAdminAutomationPanel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AutomationAdminFlowsUiTest extends TestCase
{
    public function test_flows_page_slug_and_no_parent_item(): void
    {
        $reflection = new ReflectionClass(AutomationFlowsPage::class);

        self::assertSame('automation/flows', $reflection->getStaticPropertyValue('slug'));
        self::assertNull(AutomationFlowsPage::getNavigationParentItem());
        self::assertSame('workflows', $reflection->getDefaultProperties()['viewMode'] ?? null);
    }

    public function test_admin_automation_only_middleware_is_removed(): void
    {
        $middlewarePath = ProjectRoot::path().'/app/Http/Middleware/Filament/RestrictAdminAutomationOnlyUsers.php';
        self::assertFileDoesNotExist($middlewarePath);

        $providerPath = ProjectRoot::path().'/app/Providers/Filament/AdminPanelProvider.php';
        $source = (string) file_get_contents($providerPath);
        self::assertStringNotContainsString('RestrictAdminAutomationOnlyUsers', $source);
    }

    public function test_presentation_fallback_label(): void
    {
        $registry = new AutomationFlowPresentationRegistry;

        self::assertSame(
            'Content Project Publish Now',
            $registry->fallbackLabel('content_project.publish_now'),
        );
    }

    public function test_admin_panel_provider_registers_automation_ui(): void
    {
        $providerPath = ProjectRoot::path().'/app/Providers/Filament/AdminPanelProvider.php';
        self::assertFileExists($providerPath);

        $source = (string) file_get_contents($providerPath);
        self::assertStringContainsString('AutomationFlowsPage::class', $source);
        self::assertStringContainsString('AutomationRuleResource::class', $source);
        self::assertStringContainsString('AutomationExecutionResource::class', $source);
        self::assertStringContainsString('AutomationSettings::class', $source);
        self::assertStringContainsString('AutomationWorkflowBuilder::class', $source);
        self::assertStringContainsString('AutomationOperationsDashboard::class', $source);
    }

    public function test_automation_admin_panel_id_is_admin(): void
    {
        $trait = new ReflectionClass(BelongsToAdminAutomationPanel::class);
        $method = $trait->getMethod('adminPanelId');
        // Trait method is public static on using class
        self::assertSame('admin', AutomationFlowsPage::adminPanelId());
    }

    public function test_custom_login_does_not_send_staff_to_admin_automation(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::path().'/app/Filament/Pages/Auth/CustomLogin.php'
        );

        self::assertStringNotContainsString('canAccessAdminAutomationPanel', $source);
        self::assertStringNotContainsString('/admin/automation/flows', $source);
    }
}
