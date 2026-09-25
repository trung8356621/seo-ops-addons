<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Filament\Facades\Filament;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\EditTaskWorkflow;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\TestTask;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\Seo\Support\SeoUserNavigation;
use ReflectionClass;
use Tests\TestCase;

/**
 * STEP 2C.2 — Admin is canonical Task/Workflow management; SEO routes stay compatibility.
 */
final class TaskAdminCanonicalCutoverContractTest extends TestCase
{
    public function test_default_get_url_is_admin(): void
    {
        self::assertSame('admin', TaskResource::panelId());
        Filament::setCurrentPanel(null);
        $default = TaskResource::getUrl('index');
        self::assertStringContainsString('/admin/tasks', $default);
        self::assertStringNotContainsString('/seo/tasks', $default);
    }

    public function test_seo_current_panel_stays_on_seo_compatibility_urls(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('seo-main'));
        self::assertStringContainsString('/seo/tasks', TaskResource::getUrl('index'));
        self::assertStringContainsString('/seo/tasks/1/builder', TaskResource::getUrl('builder', ['record' => 1]));
        self::assertFalse(TaskResource::shouldRegisterNavigation());
    }

    public function test_admin_nav_visible_and_routes_registered_on_both_panels(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        self::assertTrue(TaskResource::shouldRegisterNavigation());
        self::assertSame(SeoUserNavigation::systemGroup(), TaskResource::getNavigationGroup());
        self::assertStringContainsString('/admin/tasks', TaskResource::getUrl('index'));
        self::assertStringContainsString('/admin/tasks/1/edit', TaskResource::getUrl('edit', ['record' => 1]));
        self::assertStringContainsString('/admin/tasks/1/builder', TaskResource::getUrl('builder', ['record' => 1]));
        self::assertStringContainsString('/admin/tasks/1/test', TaskResource::getUrl('test', ['record' => 1]));

        self::assertContains(TaskResource::class, Filament::getPanel('admin')->getResources());
        self::assertContains(TaskResource::class, Filament::getPanel('seo-main')->getResources());
    }

    public function test_settings_workflows_builder_links_target_admin(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SeoSettingsWorkflows::class))->getFileName());
        self::assertSame(2, substr_count($src, "TaskResource::getUrl('builder'"));
        self::assertSame(2, substr_count($src, "panel: 'admin'"));
        self::assertStringNotContainsString("panel: 'seo-main'", $src);
    }

    public function test_builder_assets_and_test_runner_unchanged(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/task-resource/pages/task-workflow-builder.blade.php'
        );
        self::assertStringContainsString("task-builder.jsx", $blade);
        self::assertStringContainsString('TaskResource::getUrl(\'index\')', $blade);
        self::assertStringContainsString('save-task-flow', $blade);

        $jsx = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/task-builder.jsx');
        self::assertStringContainsString('ArticleFlowBuilder', $jsx);
        self::assertStringNotContainsString('admin-task-builder', $jsx);

        $vite = (string) file_get_contents(base_path('vite.config.js'));
        self::assertSame(1, substr_count($vite, 'addons/content-projects/resources/js/task-builder.jsx'));

        $test = (string) file_get_contents((new ReflectionClass(TestTask::class))->getFileName());
        self::assertStringContainsString('SystemWorkflowClient', $test);
        self::assertStringContainsString('WorkflowExecutionMode::FullRun', $test);
        self::assertStringContainsString('WorkflowExecutionMode::SingleStep', $test);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\AiPrompt\\Services\\TaskWorkflowTestRunner', $test);

        $workflow = (string) file_get_contents((new ReflectionClass(EditTaskWorkflow::class))->getFileName());
        self::assertStringContainsString('function persistTaskFlow', $workflow);
        self::assertStringContainsString("'flow_data'", $workflow);
    }

    public function test_help_matches_task_management_routes_only(): void
    {
        $php = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo/src/Support/SeoHelpRegistry.php'
        );
        self::assertStringContainsString('filament.admin.resources.tasks.*', $php);
        self::assertStringContainsString('filament.seo-main.resources.tasks.*', $php);
    }
}
