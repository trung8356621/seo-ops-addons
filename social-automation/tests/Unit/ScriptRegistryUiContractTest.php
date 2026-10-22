<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\DispatchPackageGate;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptExecutionDigest;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetInvalidationPolicy;
use PHPUnit\Framework\TestCase;

final class ScriptRegistryUiContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_scripts_widgets_workflows_pages_use_registry_ui(): void
    {
        foreach ([
            'SocialAutomationScriptsPage.php' => 'listScriptsForUi',
            'SocialAutomationWidgetsPage.php' => 'listWidgetsForUi',
            'SocialAutomationWorkflowsPage.php' => 'listWorkflowsForUi',
        ] as $file => $method) {
            $src = (string) file_get_contents($this->root.'/src/Filament/Pages/'.$file);
            self::assertStringContainsString('UsesScriptRegistryUi', $src);
            self::assertStringContainsString($method === 'listScriptsForUi' ? 'scripts()' : ($method === 'listWidgetsForUi' ? 'widgets()' : 'workflows()'), $src);
        }
        $ui = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptRegistryUiService.php');
        self::assertStringContainsString('listScriptsForUi', $ui);
        self::assertStringContainsString('widget_dependencies', $ui);
        self::assertStringContainsString('setPackageEnabled', $ui);
    }

    public function test_architecture_documents_bidirectional_script_sync(): void
    {
        $arch = (string) file_get_contents($this->root.'/docs/ARCHITECTURE-RPA-INTEGRATION.md');
        self::assertStringContainsString('đồng bộ hai chiều', $arch);
        self::assertStringContainsString('Browser Sessions của RPA Admin luôn', $arch);
        self::assertStringNotContainsString('### Luồng 1: RPA Admin → SEO-OPS', $arch);
    }

    public function test_video_only_keeps_widget_enabled_contract(): void
    {
        $base = [
            'script_id' => 's',
            'variables' => [],
            'outputs' => [],
            'steps' => [['id' => '1']],
            'runtime_requirements' => [],
            'assets' => [],
        ];
        $video = $base + ['video_demo' => ['artifact_ref' => 'a.webm', 'test_run_id' => 't1']];
        self::assertFalse(ScriptExecutionDigest::executionChanged($base, $video));
        $actions = (new WidgetInvalidationPolicy)->widgetsToDisable(
            's', '1.0.1', str_repeat('1', 64), ScriptExecutionDigest::fromManifest($video),
            '1.0.0', str_repeat('0', 64), ScriptExecutionDigest::fromManifest($base),
            [['package_key' => 'w', 'is_enabled' => true, 'script_package_key' => 's']],
            false,
        );
        self::assertSame([], $actions);
    }

    public function test_disabled_workflow_dependency_blocks_dispatch_contract(): void
    {
        $gate = new DispatchPackageGate;
        $result = $gate->assertDispatchable(
            ['package_key' => 'wf', 'kind' => 'workflow', 'is_enabled' => true],
            [['package_key' => 'w1', 'kind' => 'widget', 'is_enabled' => false]],
        );
        self::assertFalse($result['ok']);
    }
}
