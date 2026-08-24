<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\RelationManagers\ProjectItemsRelationManager;
use Omnichannel\Addons\ContentProjects\Http\Controllers\Api\V1\ContentProjectApiController;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentCommandFactory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Batch A — Generate parity: one CommandBus path creates, prepares, and starts engine once.
 */
final class ContentProjectGenerateParityTest extends TestCase
{
    public function test_generate_handler_owns_full_orchestration(): void
    {
        $handler = $this->source(GenerateProjectItemsHandler::class);

        self::assertStringContainsString('workflowRunService->startRun', $handler);
        self::assertStringContainsString('prepareRunQueue', $handler);
        self::assertStringContainsString('runEngine->start', $handler);
        self::assertStringContainsString("'use_php_engine' => true", $handler);
        self::assertStringContainsString("'engine_started' => true", $handler);
        self::assertStringContainsString('content_project.generate_started', $handler);
        self::assertSame(1, substr_count($handler, 'runEngine->start'));
    }

    public function test_filament_does_not_start_engine_separately(): void
    {
        $resource = $this->source(SeoProjectResource::class);

        self::assertStringContainsString('function startGeneratePendingItems', $resource);
        self::assertStringContainsString('createProjectWorkflowRun', $resource);
        self::assertStringContainsString('GenerateProjectItemsCommand', $resource);
        self::assertStringNotContainsString('ContentProjectRunEngine', $resource);
        self::assertStringNotContainsString('->start($run)', $resource);
        self::assertStringNotContainsString('updateRunSettings', $resource);
    }

    public function test_api_mcp_agent_and_relation_manager_all_dispatch_same_command(): void
    {
        $api = $this->source(ContentProjectApiController::class);
        self::assertStringContainsString('GenerateProjectItemsCommand', $api);
        self::assertStringContainsString('function generate', $api);

        $factory = $this->source(ContentProjectAgentCommandFactory::class);
        self::assertStringContainsString("'content_project.generate' => new GenerateProjectItemsCommand", $factory);

        $relation = $this->source(ProjectItemsRelationManager::class);
        self::assertStringContainsString('GenerateProjectItemsCommand', $relation);
        self::assertStringContainsString('dispatchGenerate', $relation);
        self::assertStringNotContainsString('ContentProjectRunEngine', $relation);
    }

    public function test_command_accepts_settings_for_filament_parity(): void
    {
        $ref = new ReflectionClass(GenerateProjectItemsCommand::class);
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        $params = [];
        foreach ($ctor->getParameters() as $param) {
            $params[$param->getName()] = true;
        }
        self::assertArrayHasKey('settings', $params);

        $cmd = new GenerateProjectItemsCommand(1, [2], 'full', false, ['generate_post_images' => true]);
        self::assertTrue((bool) ($cmd->settings['generate_post_images'] ?? false));
    }

    public function test_run_engine_start_is_idempotent_for_live_work(): void
    {
        $engine = $this->source(ContentProjectRunEngine::class);
        $pos = strpos($engine, 'function start(');
        self::assertNotFalse($pos);
        // Docblock (Idempotent) sits above the signature — include preceding comment block.
        $chunkStart = max(0, $pos - 280);
        $nextFn = strpos($engine, "\n    public function ", $pos + 1);
        $chunk = $nextFn !== false
            ? substr($engine, $chunkStart, $nextFn - $chunkStart)
            : substr($engine, $chunkStart, 4000);

        self::assertStringContainsString('Idempotent', $chunk);
        self::assertStringContainsString('already_running', $chunk);
        self::assertStringContainsString('dispatch_fresh', $chunk);
        self::assertStringContainsString('dispatchNextArticle', $chunk);
        self::assertSame(1, substr_count($chunk, 'dispatchNextArticle($run)'));
    }

    public function test_generate_and_rerun_both_start_engine_once_in_handler(): void
    {
        $generate = $this->source(GenerateProjectItemsHandler::class);
        $rerun = $this->source(RerunProjectItemsHandler::class);

        self::assertSame(1, substr_count($generate, 'runEngine->start'));
        self::assertSame(1, substr_count($rerun, 'runEngine->start'));
    }

    public function test_filament_create_passes_settings_into_command(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'createProjectWorkflowRun');
        $src = $this->methodBody($method);

        self::assertStringContainsString('GenerateProjectItemsCommand', $src);
        self::assertStringContainsString('$runSettings', $src);
        self::assertStringContainsString('unset($runSettings[\'task_ids\']', $src);
        self::assertStringContainsString('resolvePrimaryExecutionRef', $src);
        self::assertStringNotContainsString('RunEngine', $src);
        self::assertStringNotContainsString('updateRunSettings', $src);
    }

    public function test_generate_handler_returns_primary_execution_ref(): void
    {
        $handler = $this->source(GenerateProjectItemsHandler::class);
        self::assertStringContainsString("'execution_ref' => \$runsStarted[0] ?? null", $handler);
        self::assertStringContainsString("'execution_refs' => \$runsStarted", $handler);
    }

    public function test_public_ref_resolves_primary_execution_ref_from_metadata(): void
    {
        $primary = \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::execution(42);

        self::assertSame(
            $primary,
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::resolvePrimaryExecutionRef([
                'execution_refs' => [$primary, 'cpx_999'],
            ]),
        );

        self::assertSame(
            $primary,
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::resolvePrimaryExecutionRef([
                'execution_ref' => $primary,
                'execution_refs' => ['cpx_999'],
            ]),
        );
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return $contents;
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $file = (string) file_get_contents((string) $method->getFileName());
        $lines = explode("\n", $file);
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return implode("\n", array_slice($lines, $start, $end - $start));
    }
}
