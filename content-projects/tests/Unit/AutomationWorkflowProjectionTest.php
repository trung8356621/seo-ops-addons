<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Presentation\AutomationFlowPresentationRegistry;
use Omnichannel\Addons\Agent\Automation\Presentation\Workflow\AutomationWorkflowMapDefinitions;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationFlowsPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AutomationWorkflowProjectionTest extends TestCase
{
    public function test_round1_workflows_exist_with_multi_node_maps(): void
    {
        $defs = AutomationWorkflowMapDefinitions::all();
        $ids = array_map(static fn (array $d): string => $d['id'], $defs);

        self::assertContains('wf:generate-article', $ids);
        self::assertContains('wf:review', $ids);
        self::assertContains('wf:publishing', $ids);
        self::assertContains('wf:archive-restore', $ids);
        self::assertContains('wf:automation-runtime', $ids);

        foreach ($defs as $definition) {
            self::assertGreaterThanOrEqual(2, count($definition['nodes']), $definition['id']);
            self::assertNotEmpty($definition['edges'], $definition['id']);
            self::assertNotEmpty($definition['definition_sources'], $definition['id']);
            foreach ($definition['edges'] as $edge) {
                self::assertNotSame('', (string) ($edge['evidence'] ?? ''), $definition['id']);
                self::assertContains(
                    (string) $edge['type'],
                    ['next', 'success', 'failure', 'retry', 'optional', 'manual', 'queued'],
                    $definition['id'],
                );
            }
        }
    }

    public function test_publishing_workflow_includes_wordpress_success_failure_branch(): void
    {
        $publishing = null;
        foreach (AutomationWorkflowMapDefinitions::all() as $definition) {
            if ($definition['id'] === 'wf:publishing') {
                $publishing = $definition;
                break;
            }
        }

        self::assertNotNull($publishing);
        $edgeTypes = array_column($publishing['edges'], 'type');
        self::assertContains('success', $edgeTypes);
        self::assertContains('failure', $edgeTypes);
        self::assertContains('queued', $edgeTypes);
        self::assertContains('retry', $edgeTypes);

        $canonicals = array_column($publishing['nodes'], 'canonical');
        self::assertContains('article.publish_requested', $canonicals);
        self::assertContains('wordpress.article.sync', $canonicals);
        self::assertContains('wordpress.synced', $canonicals);
        self::assertContains('wordpress.sync_failed', $canonicals);
    }

    public function test_flows_page_defaults_to_workflows_tab(): void
    {
        $reflection = new ReflectionClass(AutomationFlowsPage::class);
        self::assertSame('workflows', $reflection->getDefaultProperties()['viewMode'] ?? null);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        self::assertStringContainsString('AutomationWorkflowProjectionService', $source);
        self::assertStringContainsString('AutomationWorkflowViewerPayload', $source);
    }

    public function test_presentation_has_mapping_and_edge_helpers(): void
    {
        $registry = new AutomationFlowPresentationRegistry;
        $source = (string) file_get_contents((string) (new ReflectionClass($registry))->getFileName());
        self::assertStringContainsString('function mappingLabel', $source);
        self::assertStringContainsString('function edgeTypeLabel', $source);
    }

    public function test_definitions_are_presentation_only_no_runtime_imports(): void
    {
        $path = (string) (new ReflectionClass(AutomationWorkflowMapDefinitions::class))->getFileName();
        $source = (string) file_get_contents($path);

        self::assertDoesNotMatchRegularExpression('/^use\s+.+BusinessEventDispatcher/m', $source);
        self::assertDoesNotMatchRegularExpression('/^use\s+.+ExecuteAutomationRuleJob/m', $source);
        self::assertDoesNotMatchRegularExpression('/^use\s+.+CommandBus/m', $source);
        self::assertStringNotContainsString('namespace App\\Addons\\SeoContentAi\\Automation\\BusinessHook\\Services', $source);
    }
}
