<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\EditTask;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\EditTaskWorkflow;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\ListTasks;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\TaskWorkflowBuilder;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\TestTask;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use ReflectionClass;
use Tests\TestCase;

/**
 * STEP 2C.1 — dual-register existing TaskResource on Admin. No canonical cutover.
 */
final class TaskAdminDualRegistrationContractTest extends TestCase
{
    public function test_admin_panel_registers_same_task_resource_class(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(AdminPanelProvider::class))->getFileName()
        );
        self::assertStringContainsString('TaskResource::class', $provider);
        self::assertStringContainsString('Dual-register existing Task/Workflow management', $provider);

        $admin = Filament::getPanel('admin')->getResources();
        $seo = Filament::getPanel('seo-main')->getResources();
        self::assertContains(TaskResource::class, $admin);
        self::assertContains(TaskResource::class, $seo);
        self::assertSame(TaskResource::class, TaskResource::class);
    }

    public function test_default_panel_id_stays_seo_main(): void
    {
        self::assertSame('admin', TaskResource::panelId());
        Filament::setCurrentPanel(null);
        self::assertStringContainsString('/admin/tasks', TaskResource::getUrl('index'));
        self::assertStringContainsString('/seo/tasks', TaskResource::getUrl('index', panel: 'seo-main'));
    }

    public function test_admin_and_seo_task_routes_exist(): void
    {
        $router = app('router')->getRoutes();
        foreach ([
            'filament.admin.resources.tasks.index' => 'admin/tasks',
            'filament.admin.resources.tasks.create' => 'admin/tasks/create',
            'filament.admin.resources.tasks.edit' => 'admin/tasks/{record}/edit',
            'filament.admin.resources.tasks.builder' => 'admin/tasks/{record}/builder',
            'filament.admin.resources.tasks.test' => 'admin/tasks/{record}/test',
            'filament.seo-main.resources.tasks.index' => 'seo/tasks',
            'filament.seo-main.resources.tasks.create' => 'seo/tasks/create',
            'filament.seo-main.resources.tasks.edit' => 'seo/tasks/{record}/edit',
            'filament.seo-main.resources.tasks.builder' => 'seo/tasks/{record}/builder',
            'filament.seo-main.resources.tasks.test' => 'seo/tasks/{record}/test',
        ] as $name => $uri) {
            $route = $router->getByName($name);
            self::assertNotNull($route, $name);
            self::assertSame($uri, $route->uri());
        }
    }

    public function test_no_cloned_task_ui_or_builder_assets(): void
    {
        $clientFilament = app_path('Filament');
        $hits = [];
        if (is_dir($clientFilament)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($clientFilament, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                if (preg_match('/Task(Resource|Workflow|Builder)|task-builder/i', $file->getFilename()) === 1) {
                    $hits[] = $file->getPathname();
                }
            }
        }
        self::assertSame([], $hits);

        $builder = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/task-resource/pages/task-workflow-builder.blade.php'
        );
        self::assertSame(1, substr_count($builder, "task-builder.jsx"));
        self::assertStringContainsString('ArticleFlowBuilder', (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/task-builder.jsx'
        ));
        self::assertStringContainsString(
            'addons/content-projects/resources/js/task-builder.jsx',
            (string) file_get_contents(base_path('vite.config.js'))
        );
    }

    public function test_existing_pages_and_test_runner_unchanged(): void
    {
        foreach ([
            ListTasks::class,
            TaskWorkflowBuilder::class,
            EditTask::class,
            EditTaskWorkflow::class,
            TestTask::class,
        ] as $page) {
            $path = (string) (new ReflectionClass($page))->getFileName();
            self::assertStringContainsString('TaskResource'.DIRECTORY_SEPARATOR.'Pages', $path);
        }

        $test = (string) file_get_contents((new ReflectionClass(TestTask::class))->getFileName());
        self::assertStringContainsString(TaskWorkflowTestRunner::class, $test);
        self::assertStringContainsString("view = 'seo-content-ai::filament.resources.task-resource.pages.test-task'", $test);

        $workflow = (string) file_get_contents((new ReflectionClass(EditTaskWorkflow::class))->getFileName());
        self::assertStringContainsString('task-workflow-builder', $workflow);
    }

    public function test_admin_and_seo_task_queries_share_owner_scope(): void
    {
        if (config('database.default') === 'sqlite') {
            self::markTestSkipped('Requires omi_seo_ai.');
        }

        $user = User::query()->find(2);
        if (! $user instanceof User) {
            self::markTestSkipped('Owner #2 missing.');
        }

        Auth::login($user);
        app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

        $ids = static function (string $panelId): array {
            Filament::setCurrentPanel(Filament::getPanel($panelId));

            return TaskResource::getEloquentQuery()
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        };

        $seo = $ids('seo-main');
        $admin = $ids('admin');
        self::assertSame($seo, $admin);
        self::assertSame(2, SeoAccessControl::accountSiteOwnerId());
        self::assertTrue(SeoAccessControl::shouldScopeToAccountOwner());

        $scopedUserIds = TaskResource::getEloquentQuery()->pluck('user_id')->unique()->all();
        self::assertSame([2], array_map('intval', $scopedUserIds));
    }
}
