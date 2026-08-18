<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages\ListAiConnections;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class SeoSettingsAiCenterNavigationTest extends TestCase
{
    public function test_ai_center_has_models_and_routing_tabs_only(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsAiCenter::class))->getFileName());
        $this->assertStringContainsString("protected static bool \$shouldRegisterNavigation = false", $page);
        $this->assertStringContainsString("settings/ai-center", $page);
        $this->assertStringContainsString("public string \$tab = 'models'", $page);
        $this->assertStringContainsString("'connections' => 'models'", $page);
        $this->assertStringContainsString("'models', 'routing'", $page);
        $this->assertStringNotContainsString("'connections', 'models', 'routing'", $page);
        $this->assertStringContainsString("PromptResource::getUrl()", $page);
        $this->assertStringContainsString('isSeo(', $page);
        $this->assertStringContainsString('openImportModal', $page);
        $this->assertStringContainsString('exportTemplate', $page);
        $this->assertStringContainsString('downloadTemplate', $page);
        $this->assertStringContainsString('syncAllModels', $page);
        $this->assertStringContainsString('syncConnection', $page);

        $view = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-ai-center.blade.php');
        $this->assertStringContainsString("'models', 'routing'", $view);
        $this->assertStringNotContainsString("'connections', 'models', 'routing'", $view);
        $this->assertStringNotContainsString("@if (\$tab === 'connections')", $view);
        $this->assertStringContainsString('seoAiCenter', $view);
        $this->assertStringContainsString('loadPanel', $view);
        $this->assertStringContainsString('activeMainTab', $view);
        $this->assertStringContainsString('activeCapability', $view);
        $this->assertStringContainsString('wire:ignore.self', $view);
        $this->assertStringContainsString('AiModelArea::uiCases()', $view);
        $this->assertStringContainsString('tab_fast_text', (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php'));
        $this->assertStringContainsString('filter_cost_free', $view);
        $this->assertStringNotContainsString('allow_paid_fallback', $view);
        $this->assertStringContainsString('modelCost', $page);
        $this->assertStringNotContainsString('allowPaidFallback', $page);
        $this->assertStringContainsString('sortable-ai-model-list', $view);
        $this->assertStringContainsString('openModelPicker', $view);
        $this->assertStringContainsString('modelArea', $view);
        $this->assertTrue(strpos($view, 'seo-ai-toolbar') < strpos($view, 'areaModelRowsFor'));
        $this->assertStringNotContainsString('seo-ai-conn-block__title', $view);
        $this->assertStringNotContainsString('seo-ai-group-head', $view);
        $this->assertTrue(strpos($view, 'wire:model="templateFile"') > strpos($view, 'seo-ai-modal'));
        $this->assertStringNotContainsString('tab_prompts', $view);

        $menu = (string) file_get_contents(ProjectRoot::addonsPath().'/seo/src/Support/SeoSettingsMenu.php');
        $this->assertStringContainsString('SeoSettingsAiCenter', $menu);
        $this->assertStringContainsString('AiConnectionResource::getUrl()', $menu);
        $this->assertStringContainsString("'id' => 'api'", $menu);

        $list = (string) file_get_contents((new \ReflectionClass(ListAiConnections::class))->getFileName());
        $this->assertStringNotContainsString('SeoSettingsAiCenter::getUrl()', $list);
    }
}
