<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\AiModelInventory;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use PHPUnit\Framework\TestCase;

final class AiModelInventoryContractTest extends TestCase
{
    public function test_inventory_service_exists_and_avoids_openrouter_discovery_in_routing_options(): void
    {
        $path = (new \ReflectionClass(AiModelInventory::class))->getFileName();
        $this->assertNotFalse($path);
        $src = (string) file_get_contents((string) $path);
        $this->assertStringContainsString('liveCompatibleCandidates', $src);
        $this->assertStringContainsString('executionOptions', $src);
        $this->assertStringContainsString('enabledRows', $src);
        $this->assertStringContainsString('Does not include OpenRouter discovered-but-disabled inventory', $src);
        $this->assertStringNotContainsString('Http::', $src);
        $this->assertStringNotContainsString('/api/v1/models', $src);

        $targets = (string) file_get_contents((new \ReflectionClass(AiRoutingTargetService::class))->getFileName());
        $this->assertStringContainsString('areaEnabledModels', $targets);
        $this->assertStringContainsString('forgetMemo', $targets);
    }

    public function test_ai_center_uses_shared_inventory_and_client_tabs(): void
    {
        $page = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Pages/SeoSettingsAiCenter.php'
        );
        $this->assertStringContainsString('AiModelInventory', $page);
        $this->assertStringContainsString('loadPanel', $page);
        $this->assertStringContainsString('openPanel', $page);
        $this->assertStringContainsString('setModelArea', $page);
        $this->assertStringNotContainsString('syncUiTab', $page);
        $this->assertStringNotContainsString('syncUiArea', $page);
        $this->assertStringNotContainsString("queryString = ['tab', 'modelArea']", $page);
        $this->assertStringContainsString('routingSavedSnapshot', $page);
        $this->assertStringContainsString('reconcileRoutingDraftAgainstInventory', $page);
        $this->assertStringContainsString('bustInventoryCache', $page);
    }
}
