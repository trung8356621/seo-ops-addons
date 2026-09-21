<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use App\System\Workflow\Dto\WorkflowGraphScope;
use App\System\Workflow\Dto\WorkflowRunRequest;
use Omnichannel\Addons\AiPrompt\System\LegacySeoTaskWorkflowRuntimePort;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionScope;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 3C.6 — generic System execution_scope + domain adapter mapping.
 */
final class SystemWorkflowGraphScopeContractTest extends TestCase
{
    public function test_system_graph_scope_is_generic_wire_contract(): void
    {
        self::assertSame('full', WorkflowGraphScope::Full->value);
        self::assertSame('outline_vocabulary', WorkflowGraphScope::OutlineVocabulary->value);
        self::assertSame(WorkflowGraphScope::OutlineVocabulary, WorkflowGraphScope::tryParse('outline_vocabulary'));
        self::assertSame(WorkflowGraphScope::Full, WorkflowGraphScope::tryParse('full'));
        self::assertNull(WorkflowGraphScope::tryParse('not_a_scope'));
        self::assertNull(WorkflowGraphScope::tryParse(null));
        self::assertNull(WorkflowGraphScope::tryParse(''));

        $src = (string) file_get_contents((new ReflectionClass(WorkflowGraphScope::class))->getFileName());
        self::assertStringNotContainsString('Omnichannel\\Addons\\ContentProjects', $src);
        self::assertStringNotContainsString('WorkflowExecutionScope', $src);
    }

    public function test_workflow_run_request_serializes_execution_scope(): void
    {
        $req = WorkflowRunRequest::fromArray([
            'definition_id' => 9,
            'execution_mode' => 'from_node',
            'start_node_id' => 'n_outline',
            'execution_scope' => 'outline_vocabulary',
        ]);
        self::assertSame('outline_vocabulary', $req->executionScope);
        self::assertSame('outline_vocabulary', $req->toArray()['execution_scope']);

        $default = WorkflowRunRequest::fromArray(['definition_id' => 1]);
        self::assertNull($default->executionScope);
    }

    public function test_adapter_maps_outline_vocabulary_and_defaults_to_full(): void
    {
        $port = (new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(LegacySeoTaskWorkflowRuntimePort::class, 'resolveDomainExecutionScope');
        $method->setAccessible(true);

        self::assertSame(
            WorkflowExecutionScope::Full,
            $method->invoke($port, null),
        );
        self::assertSame(
            WorkflowExecutionScope::Full,
            $method->invoke($port, ''),
        );
        self::assertSame(
            WorkflowExecutionScope::Full,
            $method->invoke($port, 'full'),
        );
        self::assertSame(
            WorkflowExecutionScope::OutlineVocabulary,
            $method->invoke($port, 'outline_vocabulary'),
        );
    }

    public function test_adapter_unknown_scope_fails_closed(): void
    {
        $port = (new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(LegacySeoTaskWorkflowRuntimePort::class, 'resolveDomainExecutionScope');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported workflow execution_scope');
        $method->invoke($port, 'pre_content');
    }

    public function test_adapter_execute_from_node_passes_mapped_scope(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->getFileName());
        self::assertStringContainsString('resolveDomainExecutionScope', $src);
        self::assertStringContainsString('WorkflowGraphScope::OutlineVocabulary', $src);
        self::assertStringContainsString('WorkflowExecutionScope::OutlineVocabulary', $src);
        self::assertStringContainsString('executionScope: $executionScope', $src);
        self::assertStringContainsString("'execution_scope' => \$executionScope", $src);
    }
}
