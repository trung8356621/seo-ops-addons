<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Filament\Facades\Filament;
use Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource\Pages\CreateTask;
use Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource\Pages\EditTask;
use Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource\Pages\EditTaskWorkflow;
use Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource\Pages\ListTasks;
use Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource\Pages\TaskWorkflowBuilder;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Seo\Support\SeoUserNavigation;
use ReflectionClass;
use Tests\TestCase;

final class TaskCanonicalOwnershipContractTest extends TestCase
{
    public function test_task_resource_is_canonical_in_ai_prompt(): void
    {
        self::assertSame('Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource', TaskResource::class);
        self::assertSame(SeoTask::class, TaskResource::getModel());
    }

    public function test_default_panel_id_is_admin(): void
    {
        self::assertSame('admin', TaskResource::panelId());
        Filament::setCurrentPanel(null);
        $default = TaskResource::getUrl('index');
        self::assertStringContainsString('/admin/tasks', $default);
        self::assertStringNotContainsString('/seo/tasks', $default);
    }

    public function test_seo_compatibility_urls_still_generate_when_panel_forced(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('seo-main'));
        self::assertStringContainsString('/seo/tasks', TaskResource::getUrl('index'));
        self::assertStringContainsString('/seo/tasks/1/builder', TaskResource::getUrl('builder', ['record' => 1]));
        self::assertFalse(TaskResource::shouldRegisterNavigation());
    }

    public function test_admin_nav_shown_seo_nav_hidden(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        self::assertTrue(TaskResource::shouldRegisterNavigation());
        self::assertSame(SeoUserNavigation::systemGroup(), TaskResource::getNavigationGroup());

        Filament::setCurrentPanel(Filament::getPanel('seo-main'));
        self::assertFalse(TaskResource::shouldRegisterNavigation());
    }

    public function test_both_panels_register_canonical_resource(): void
    {
        $admin = Filament::getPanel('admin')->getResources();
        $seo = Filament::getPanel('seo-main')->getResources();

        self::assertContains(TaskResource::class, $admin);
        self::assertContains(TaskResource::class, $seo);
    }

    public function test_no_duplicate_task_resource_registered_in_panel(): void
    {
        $adminTasks = array_filter(
            Filament::getPanel('admin')->getResources(),
            static fn (string $res): bool => str_ends_with($res, 'TaskResource'),
        );
        $seoTasks = array_filter(
            Filament::getPanel('seo-main')->getResources(),
            static fn (string $res): bool => str_ends_with($res, 'TaskResource'),
        );

        self::assertCount(1, $adminTasks, 'Admin panel must register exactly one TaskResource');
        self::assertCount(1, $seoTasks, 'SEO panel must register exactly one TaskResource');
        self::assertSame([TaskResource::class], array_values($adminTasks));
        self::assertSame([TaskResource::class], array_values($seoTasks));
    }

    public function test_legacy_content_projects_task_resource_resolves_to_canonical(): void
    {
        $legacyClass = 'Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource';
        self::assertTrue(class_exists($legacyClass));

        $ref = new ReflectionClass($legacyClass);
        self::assertSame(TaskResource::class, $ref->getName());
    }

    public function test_pages_link_to_canonical_task_resource(): void
    {
        foreach ([
            ListTasks::class,
            CreateTask::class,
            EditTask::class,
            EditTaskWorkflow::class,
            TaskWorkflowBuilder::class,
        ] as $page) {
            $ref = new ReflectionClass($page);
            $props = $ref->getDefaultProperties();
            self::assertSame(TaskResource::class, $props['resource'] ?? null);
        }
    }

    public function test_content_projects_no_longer_contains_generic_task_resource_files(): void
    {
        $contentProjectsTaskResource = dirname(__DIR__, 2).'/content-projects/src/Filament/Resources/TaskResource.php';
        $contentProjectsTaskResourceDir = dirname(__DIR__, 2).'/content-projects/src/Filament/Resources/TaskResource';

        self::assertFileDoesNotExist($contentProjectsTaskResource);
        self::assertDirectoryDoesNotExist($contentProjectsTaskResourceDir);
    }
}
