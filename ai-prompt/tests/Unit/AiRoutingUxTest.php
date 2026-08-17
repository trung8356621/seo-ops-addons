<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class AiRoutingUxTest extends TestCase
{
    public function test_global_strategy_keys_and_no_raw_priority_ui(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsAiCenter::class))->getFileName());
        $this->assertStringContainsString("globalUsageMode", $page);
        $this->assertStringContainsString('economy', $page);
        $this->assertStringContainsString('AiUsageMode', $page);
        $this->assertStringContainsString('selection_mode', $page);
        $this->assertStringContainsString('setSelectionMode', $page);
        $this->assertStringNotContainsString('Add fallback', $page);

        $view = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-ai-center.blade.php');
        $this->assertStringContainsString('value="economy"', $view);
        $this->assertStringContainsString('value="quality_first"', $view);
        $this->assertStringContainsString('strategy_short', $view);
        $this->assertStringContainsString('seoAiSortableList', $view);
        $this->assertStringNotContainsString('manage_priority_', $view);
        $this->assertStringNotContainsString('automatic_help_', $view);
        $this->assertStringContainsString('heroicon-o-pencil-square', $view);
        $this->assertStringContainsString('seo-capability-matrix-backdrop', $view);
        $this->assertStringContainsString('picker_empty_available', $view);
        $this->assertStringContainsString('picker_added_label', $view);
        $this->assertStringContainsString('pickerEnabledRows', $page);
        $this->assertStringContainsString('reorderCapabilityModels', $view);
        $this->assertStringContainsString('toggleFamily', $view);
        $this->assertStringContainsString('seoAiCenter', $view);
        $this->assertStringContainsString('setSelectionMode', $view);
        $this->assertStringContainsString('save_model_order', $view);
        $this->assertStringContainsString('markModelsOrder', $view);
        $this->assertStringContainsString('activeMainTab', $view);
        $this->assertStringContainsString('wire:ignore.self', $view);
        $this->assertStringContainsString('ai-center-models', $view);
        $this->assertStringContainsString('ai-center-routing', $view);
        $this->assertStringNotContainsString('__seoAiCenterUi', $view);
        $this->assertStringNotContainsString('seo-ai-panel-hidden', $view);
        $this->assertStringNotContainsString('syncUiTab', $view);
        $this->assertStringContainsString('canReorderModels', $page);
        $this->assertStringContainsString('AiModelInventory', $page);
        $this->assertStringContainsString('#[Renderless]', $page);
        $this->assertStringNotContainsString('{{ $this->form }}', $view);
        $this->assertStringNotContainsString('add_fallback', $view);
        $this->assertStringContainsString('showRoutingTechnical', $view);
        $this->assertStringContainsString('seo-ai-toolbar', $view);
        $this->assertStringContainsString('seo-ai-switch', $view);
    }
}
