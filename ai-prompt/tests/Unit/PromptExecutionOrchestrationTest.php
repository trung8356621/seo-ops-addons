<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Seo\Contracts\SeoProjectWorkflowStepCatalogContract;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\ContentProjects\Services\ArticlePipelineRerunStartStepResolver;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PromptExecutionOrchestrationTest extends TestCase
{
    public function test_rerun_resolver_maps_stale_node_to_semantic_kind(): void
    {
        $publish = new SeoTask;
        $publish->forceFill([
            'id' => 10,
            'flow_data' => [
                'nodes' => [
                    ['id' => 'node_current_outline', 'type' => 'prompt', 'title' => 'Táº¡o dÃ n Ã½ SEO'],
                    ['id' => 'node_current_content', 'type' => 'prompt', 'title' => 'Viáº¿t bÃ i theo dÃ n Ã½'],
                ],
                'edges' => [],
            ],
        ]);

        $catalog = $this->stubCatalog(
            seoTask: $publish,
            findStep: null,
            firstByKind: static fn (string $kind): ?string => $kind === 'outline'
                ? 'node_current_outline'
                : 'node_current_content',
            steps: [
                ['node_id' => 'node_current_outline', 'kind' => 'outline', 'title' => 'Outline', 'label' => 'x', 'prompt_id' => 1, 'depends_on_kinds' => []],
                ['node_id' => 'node_current_content', 'kind' => 'content', 'title' => 'Content', 'label' => 'y', 'prompt_id' => 2, 'depends_on_kinds' => ['outline']],
            ],
        );

        $resolver = new ArticlePipelineRerunStartStepResolver($catalog);
        $task = new SeoProjectTask;
        $task->id = 1;

        $resolved = $resolver->resolve($task, 'outline', 'node_1780563019334');

        self::assertTrue($resolved['ok']);
        self::assertSame('node_current_outline', $resolved['resolved_node_id']);
        self::assertSame('node_1780563019334', $resolved['source_node_id']);
        self::assertSame(ArticlePipelineRerunStartStepResolver::STRATEGY_SEMANTIC_KIND, $resolved['strategy']);
        self::assertSame('outline', $resolved['semantic_key']);
    }

    public function test_rerun_resolver_uses_direct_node_when_still_present(): void
    {
        $publish = new SeoTask;
        $publish->forceFill([
            'id' => 10,
            'flow_data' => [
                'nodes' => [
                    ['id' => 'node_1780563019334', 'type' => 'prompt', 'title' => 'Táº¡o dÃ n Ã½ SEO'],
                ],
                'edges' => [],
            ],
        ]);

        $catalog = $this->stubCatalog(
            seoTask: $publish,
            findStep: [
                'node_id' => 'node_1780563019334',
                'kind' => 'outline',
                'title' => 'Outline',
                'label' => 'x',
                'prompt_id' => 1,
                'depends_on_kinds' => [],
            ],
            firstByKind: static fn (): ?string => 'node_1780563019334',
            steps: [
                ['node_id' => 'node_1780563019334', 'kind' => 'outline', 'title' => 'Outline', 'label' => 'x', 'prompt_id' => 1, 'depends_on_kinds' => []],
            ],
        );

        $resolver = new ArticlePipelineRerunStartStepResolver($catalog);
        $resolved = $resolver->resolve(new SeoProjectTask, 'outline', 'node_1780563019334');

        self::assertTrue($resolved['ok']);
        self::assertSame('node_1780563019334', $resolved['resolved_node_id']);
        self::assertSame(ArticlePipelineRerunStartStepResolver::STRATEGY_DIRECT_NODE, $resolved['strategy']);
    }

    public function test_rerun_resolver_unresolved_has_user_message_not_raw_node(): void
    {
        $empty = new SeoTask;
        $empty->forceFill(['id' => 1, 'flow_data' => ['nodes' => [], 'edges' => []]]);

        $catalog = $this->stubCatalog(
            seoTask: $empty,
            findStep: null,
            firstByKind: static fn (): ?string => null,
            steps: [],
        );

        $resolver = new ArticlePipelineRerunStartStepResolver($catalog);
        $resolved = $resolver->resolve(new SeoProjectTask, 'article', 'node_1780563019334');

        self::assertFalse($resolved['ok']);
        self::assertStringContainsString('đã thay đổi', (string) $resolved['message']);
        self::assertStringNotContainsString('node_1780563019334', (string) $resolved['message']);
    }

    public function test_editor_rerun_aligns_start_step_resolver_via_command_bus(): void
    {
        $service = (string) file_get_contents(ProjectRoot::addonsPath().'/content-projects/src/Services/ArticlePipelineRerunService.php');
        $handler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/RerunProjectItemStepHandler.php',
        );

        self::assertStringContainsString('ArticlePipelineRerunStartStepResolver', $service);
        self::assertStringContainsString('RerunProjectItemStepCommand', $service);
        self::assertStringContainsString('commandBus->dispatch', $service);
        self::assertStringContainsString('runEngine->start', $handler);
        self::assertFileDoesNotExist(ProjectRoot::addonsPath().'/content/src/Jobs/RerunArticlePipelineJob.php');
    }

    public function test_step_retry_has_terminal_guards_and_discard_after_provider(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php'
        );

        self::assertStringContainsString('isExecutionTerminal', $source);
        self::assertStringContainsString('assertExecutionStillActive', $source);
        self::assertStringContainsString('seo.workflow_step.output_discarded', $source);
        self::assertStringContainsString('seo.workflow_step.stale_execution_ignored', $source);
        self::assertStringContainsString('seo.workflow_step.terminal_failure', $source);
        self::assertStringContainsString('stoppedTaskIds', $source);
        self::assertStringContainsString('ensureCancelledFailureState', $source);

        $ref = new ReflectionClass(SeoProjectWorkflowStepRetryService::class);
        self::assertTrue($ref->hasMethod('isExecutionTerminal'));
        self::assertTrue($ref->hasMethod('assertExecutionStillActive'));
    }

    public function test_fail_prepared_is_conditional_on_active_status(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php'
        );
        $failPos = strpos($source, 'private function failPrepared');
        self::assertNotFalse($failPos);
        $nextPos = strpos($source, 'private function isExecutionTerminal', (int) $failPos + 1);
        $chunk = $nextPos !== false
            ? substr($source, (int) $failPos, $nextPos - (int) $failPos)
            : substr($source, (int) $failPos, 6000);
        self::assertStringContainsString('whereIn(\'status\', ContentProjectExecutionStatus::activeStatuses())', $chunk);
        self::assertStringContainsString('ensureCancelledFailureState', $chunk);
    }

    public function test_is_execution_terminal_treats_cancel_marker_as_terminal(): void
    {
        $service = (new ReflectionClass(SeoProjectWorkflowStepRetryService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'isExecutionTerminal');
        $method->setAccessible(true);

        $item = new \Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
        $item->forceFill([
            'status' => 'processing',
            'error_message' => 'Cancelled by user.',
        ]);

        self::assertTrue($method->invoke($service, $item));
    }

    /**
     * @param  array<string, mixed>|null  $findStep
     * @param  callable(string): (?string)  $firstByKind
     * @param  list<array<string, mixed>>  $steps
     */
    private function stubCatalog(
        ?SeoTask $seoTask,
        ?array $findStep,
        callable $firstByKind,
        array $steps,
    ): SeoProjectWorkflowStepCatalogContract {
        return new class($seoTask, $findStep, $firstByKind, $steps) implements SeoProjectWorkflowStepCatalogContract
        {
            /**
             * @param  array<string, mixed>|null  $findStep
             * @param  callable(string): (?string)  $firstByKind
             * @param  list<array<string, mixed>>  $steps
             */
            public function __construct(
                private readonly ?SeoTask $seoTask,
                private readonly ?array $findStep,
                private readonly mixed $firstByKind,
                private readonly array $steps,
            ) {}

            public function resolveSeoTaskForStepRetry(SeoProjectTask $projectTask): ?SeoTask
            {
                return $this->seoTask;
            }

            public function firstPromptNodeIdForKind(SeoProjectTask $projectTask, string $kind): ?string
            {
                return ($this->firstByKind)($kind);
            }

            public function findStep(SeoProjectTask $projectTask, string $nodeId): ?array
            {
                return $this->findStep;
            }

            public function listRerunnableSteps(SeoProjectTask $projectTask): array
            {
                return $this->steps;
            }
        };
    }
}
