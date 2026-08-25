<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class AiCenterHealthTabTest extends TestCase
{
    public function test_health_tab_is_separate_from_resilience(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsAiCenter::class))->getFileName());
        $view = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-ai-center.blade.php');

        $this->assertStringContainsString("'models', 'routing', 'resilience', 'health'", $page);
        $this->assertStringContainsString("'models', 'routing', 'resilience', 'health'", $view);
        $this->assertStringContainsString('id="ai-center-resilience"', $view);
        $this->assertStringContainsString('id="ai-center-health"', $view);
        $this->assertStringContainsString('saveResilienceSettings', $view);
        $this->assertStringContainsString('connectionHealthRows', $view);
        $this->assertStringContainsString('modelHealthRows', $view);
        $this->assertStringContainsString('healthSummary', $view);
        $this->assertStringContainsString('health_page_title', $view);
        $this->assertStringContainsString('seo-ai-health-badge', $view);
        $this->assertStringContainsString('seo-ai-health-stats', $view);
        $this->assertStringContainsString('filter_health_all', $view);
        $this->assertStringContainsString('connection_health', $view);
        $this->assertStringContainsString('model_health', $view);
        $this->assertStringContainsString('enablePaidRoutes', $view);
        $this->assertStringNotContainsString("last_failure_class'] ??", $view);

        $presenter = (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt/src/Services/AiHealthUiPresenter.php');
        $this->assertStringContainsString('failure_class_', $presenter);
        $this->assertStringContainsString('action_enable_paid_routes', $presenter);
        $this->assertStringContainsString('InsufficientBudgetForRequest', $presenter);

        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $this->assertStringContainsString("'health_status_budget_limited' => 'Budget limited'", $en);
        $this->assertStringContainsString("'failure_class_insufficient_budget_for_request' => 'Insufficient request budget'", $en);
        $this->assertStringContainsString("'action_enable_paid_routes' => 'Enable paid routes'", $en);

        $resiliencePos = strpos($view, 'id="ai-center-resilience"');
        $healthPos = strpos($view, 'id="ai-center-health"');
        $this->assertNotFalse($resiliencePos);
        $this->assertNotFalse($healthPos);
        $this->assertLessThan($healthPos, $resiliencePos);

        $resilienceBlock = substr($view, $resiliencePos, $healthPos - $resiliencePos);
        $this->assertStringContainsString('maxAiAttempts', $resilienceBlock);
        $this->assertStringNotContainsString('connectionHealthRows', $resilienceBlock);
        $this->assertStringNotContainsString('modelHealthRows', $resilienceBlock);
    }
}
