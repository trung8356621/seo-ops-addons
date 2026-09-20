<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Contract: after Run / Create with AI, runtime UI remorphs from backend SSOT — no F5 / same-URL redirect.
 */
final class ContentProjectRuntimeUiSyncContractTest extends TestCase
{
    public function test_idle_header_visibility_is_derived_from_backend_runtime(): void
    {
        $src = $this->viewSrc();

        self::assertStringContainsString('running_items_indicator', $src);
        self::assertStringContainsString('$this->runningCount > 0', $src);
        self::assertStringContainsString('emergency_stop_generation', $src);
        self::assertStringContainsString('hasRunningExecution()', $src);
        self::assertStringContainsString("STATUS_RUNNING", $src);
        self::assertStringContainsString("function getRunningCountProperty(): int", $src);
        self::assertStringContainsString("operationsPayload['stats']['running']", $src);
    }

    public function test_run_and_create_with_ai_notify_runtime_without_redirect(): void
    {
        $view = $this->viewSrc();
        $resource = $this->resourceSrc();

        self::assertStringContainsString('function notifyProjectRuntimeChanged(): array', $view);
        self::assertStringContainsString('function refreshProjectRuntimeState(): array', $view);
        self::assertStringContainsString("dispatch('project-runtime-changed')", $view);

        $dispatchPos = strpos($view, 'function dispatchGenerate');
        self::assertNotFalse($dispatchPos);
        $dispatchChunk = substr($view, $dispatchPos, 4500);
        self::assertStringContainsString('notifyProjectRuntimeChanged()', $dispatchChunk);
        self::assertStringNotContainsString("redirect(SeoProjectResource::getUrl('view'", $dispatchChunk);
        self::assertStringNotContainsString('window.location.reload', $dispatchChunk);

        $actionPos = strpos($resource, 'function makeGeneratePendingItemsAction');
        self::assertNotFalse($actionPos);
        $actionChunk = substr($resource, $actionPos, 5500);
        self::assertStringContainsString('notifyProjectRuntimeChanged()', $actionChunk);
        self::assertStringContainsString("dispatch('project-runtime-changed')", $actionChunk);
        self::assertStringNotContainsString('navigate: false', $actionChunk);
    }

    public function test_refresh_project_runtime_state_is_shared_by_manual_and_poll_force_path(): void
    {
        $view = $this->viewSrc();
        $blade = $this->opsBlade();

        $manualPos = strpos($view, 'function manualRefreshOps(): array');
        self::assertNotFalse($manualPos);
        $manualChunk = substr($view, $manualPos, 400);
        self::assertStringContainsString('return $this->refreshProjectRuntimeState();', $manualChunk);

        $refreshPos = strpos($view, 'function refreshProjectRuntimeState(): array');
        self::assertNotFalse($refreshPos);
        $refreshChunk = substr($view, $refreshPos, 500);
        self::assertStringContainsString('invalidateOpsCache()', $refreshChunk);
        self::assertStringContainsString('fetchOpsSummary()', $refreshChunk);

        // Alpine force path (post-event / manual) uses the same Livewire loader.
        self::assertStringContainsString('$wire.manualRefreshOps()', $blade);
        self::assertStringContainsString('await $wire.lazyRefreshOps()', $blade);
        self::assertStringContainsString('x-on:project-runtime-changed.window="onProjectRuntimeChanged()"', $blade);
        self::assertSame(1, substr_count($blade, 'x-on:project-runtime-changed.window'));
        self::assertSame(1, substr_count($blade, 'async onProjectRuntimeChanged()'));
    }

    public function test_active_runtime_banner_and_stop_clear_through_same_payload(): void
    {
        $blade = $this->opsBlade();
        $readModel = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('active_runtime', $readModel);
        self::assertStringContainsString('activeRuntimeRow', $readModel);
        self::assertStringContainsString('@if (is_array($activeRuntime))', $blade);
        self::assertStringContainsString("\$activeRuntime['title']", $blade);

        $view = $this->viewSrc();
        self::assertStringContainsString('notifyProjectRuntimeChanged()', $view);
        // Emergency stop also remorphs via the same notifier (clears Stop when run ends).
        $stopPos = strpos($view, "Action::make('emergency_stop_generation')");
        self::assertNotFalse($stopPos);
        $stopChunk = substr($view, $stopPos, 2200);
        self::assertStringContainsString('notifyProjectRuntimeChanged()', $stopChunk);
    }

    public function test_generation_bus_commands_use_runtime_notifier(): void
    {
        $view = $this->viewSrc();
        self::assertStringContainsString('function commandAffectsGenerationRuntime', $view);
        self::assertStringContainsString('RerunProjectItemsCommand', $view);
        self::assertStringContainsString('RerunProjectItemStepCommand', $view);
        self::assertStringContainsString('ResumeProjectItemFromFailedStepCommand', $view);
        self::assertStringContainsString('RestartGenerationWithKeywordCommand', $view);

        $busPos = strpos($view, 'function dispatchBus(object $command): void');
        self::assertNotFalse($busPos);
        $busChunk = substr($view, $busPos, 1800);
        self::assertStringContainsString('commandAffectsGenerationRuntime($command)', $busChunk);
        self::assertStringContainsString('notifyProjectRuntimeChanged()', $busChunk);
    }

    public function test_no_duplicate_poll_registration_hooks(): void
    {
        $blade = $this->opsBlade();

        self::assertSame(1, substr_count($blade, 'startRuntimePoll() {'));
        self::assertSame(1, substr_count($blade, 'async runRuntimePoll(attempt = 0)'));
        self::assertSame(1, substr_count($blade, 'x-on:project-runtime-changed.window'));
        self::assertStringNotContainsString('window.location.reload()', $blade);
        self::assertStringNotContainsString('runGenerationTablePoll', $blade);
    }

    private function viewSrc(): string
    {
        return (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());
    }

    private function resourceSrc(): string
    {
        return (string) file_get_contents((string) (new ReflectionClass(SeoProjectResource::class))->getFileName());
    }

    private function opsBlade(): string
    {
        return LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php',
        );
    }
}
