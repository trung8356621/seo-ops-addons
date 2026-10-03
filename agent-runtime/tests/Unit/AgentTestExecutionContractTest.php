<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Testing\AgentTestExecutionService;
use Omnichannel\Addons\AgentRuntime\Testing\AgentTestInputResolver;
use Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource;
use ReflectionClass;
use Tests\TestCase;

final class AgentTestExecutionContractTest extends TestCase
{
    public function test_execution_reuses_canonical_prompt_and_workflow_boundaries(): void
    {
        $source = $this->source(AgentTestExecutionService::class);

        self::assertStringContainsString('AgentTestInputResolver', $source);
        self::assertStringContainsString('TaskTestInputResolver', $this->source(AgentTestInputResolver::class));
        self::assertStringContainsString('$this->prompts->run', $source);
        self::assertStringContainsString('$prompt->is_flow_prompt', $source);
        self::assertStringContainsString('$this->workflows->run', $source);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $source);
        self::assertStringContainsString("'source' => 'agent_test'", $source);
        self::assertStringContainsString("'capability' => 'workflow.agent_test'", $source);
        self::assertStringNotContainsString('TaskWorkflowTestRunner', $source);
    }

    public function test_execution_scopes_owner_and_article_site(): void
    {
        $source = $this->source(AgentTestExecutionService::class);
        self::assertGreaterThanOrEqual(2, substr_count($source, "where('user_id', \$ownerId)"));
        self::assertStringContainsString("where('site_id', \$siteId)", $this->source(AgentTestInputResolver::class));

        $controller = $this->source(AgentRuntimeController::class);
        self::assertStringContainsString('isSiteVisible($siteId, $userId)', $controller);
        self::assertStringContainsString('AgentTurnPersistence', $controller);
        self::assertStringContainsString("'test_result'", $controller);
    }

    public function test_old_task_test_route_is_retired(): void
    {
        self::assertArrayNotHasKey('test', TaskResource::getPages());
        self::assertFileDoesNotExist(dirname(__DIR__, 3).'/ai-prompt/src/Filament/Resources/TaskResource/Pages/TestTask.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 3).'/ai-prompt/src/Models/TaskTestResult.php');
    }

    private function source(string $class): string
    {
        return (string) file_get_contents((new ReflectionClass($class))->getFileName());
    }
}
