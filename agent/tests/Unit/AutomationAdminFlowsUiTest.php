<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Tests\Support\ProjectRoot;

use Omnichannel\Addons\Agent\Automation\Presentation\AutomationFlowPresentationRegistry;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationFlowsPage;
use App\Http\Middleware\Filament\RestrictAdminAutomationOnlyUsers;
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

    public function test_restrict_middleware_allows_automation_flows_prefix(): void
    {
        $reflection = new ReflectionClass(RestrictAdminAutomationOnlyUsers::class);
        /** @var list<string> $constant */
        $constant = $reflection->getConstant('ALLOWED_PREFIXES');

        self::assertContains('admin/automation', $constant);
        self::assertContains('admin/automation-rules', $constant);
        self::assertContains('admin/automation-executions', $constant);
    }

    public function test_presentation_fallback_label(): void
    {
        $registry = new AutomationFlowPresentationRegistry;

        self::assertSame(
            'Content Project Publish Now',
            $registry->fallbackLabel('content_project.publish_now'),
        );
    }

    public function test_admin_panel_provider_registers_flows_page(): void
    {
        $providerPath = ProjectRoot::path().'/app'.'/Providers/Filament/AdminPanelProvider.php';
        self::assertFileExists($providerPath);

        $source = (string) file_get_contents($providerPath);
        self::assertStringContainsString('AutomationFlowsPage::class', $source);
        self::assertStringContainsString("slug === 'seo-content-ai'", $source);
        self::assertStringContainsString('AutomationRuleResource::class', $source);
    }

    public function test_custom_login_allows_automation_staff(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::path().'/app'.'/Filament/Pages/Auth/CustomLogin.php'
        );

        self::assertStringContainsString('canAccessAdminAutomationPanel', $source);
        self::assertStringContainsString('/admin/automation/flows', $source);
    }
}
