<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\CreateSeoProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\EditSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectWorkspaceSaveService;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectCommandBusCutoverTest extends TestCase
{
    public function test_create_seo_project_uses_command_bus_not_eloquent_create(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(CreateSeoProject::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectCommandBus', $source);
        self::assertStringContainsString('CreateContentProjectCommand', $source);
        self::assertStringNotContainsString('static::getModel()::create($data)', $source);
        self::assertStringNotContainsString('SeoProjectTaskSyncService::class)->sync($project', $source);
    }

    public function test_edit_seo_project_does_not_call_task_sync_service_directly(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(EditSeoProject::class))->getFileName(),
        );

        self::assertStringContainsString('SyncContentProjectItemsCommand', $source);
        self::assertStringContainsString('UpdateContentProjectCommand', $source);
        self::assertDoesNotMatchRegularExpression('/SeoProjectTaskSyncService::class\)->sync\(/', $source);
    }

    public function test_wordpress_manual_sync_publish_now_cp_branch_uses_command_or_avoids_scheduled_publish_at(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressManualSyncService::class))->getFileName(),
        );

        $publishPos = strpos($source, 'function publishNow');
        self::assertNotFalse($publishPos);

        $cpBranch = substr($source, $publishPos, 800);
        $usesCommand = str_contains($cpBranch, 'PublishProjectItemsNowCommand');
        $avoidsScheduledField = ! str_contains($cpBranch, 'scheduled_publish_at');

        self::assertTrue(
            $usesCommand || $avoidsScheduledField,
            'Content Project publishNow branch must use PublishProjectItemsNowCommand or avoid scheduled_publish_at.',
        );
    }

    public function test_seo_project_resource_create_workflow_run_uses_generate_command(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('GenerateProjectItemsCommand', $source);
        self::assertStringContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('->startRun($project', $source);
    }

    public function test_publishing_queue_runner_still_processes_scheduled_items(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueueRunner::class))->getFileName(),
        );

        $usesRunner = str_contains($source, 'PublishDueItemService')
            || str_contains($source, 'ProcessScheduledProjectItemPublish')
            || str_contains($source, 'ContentProjectCommandBus');
        self::assertTrue($usesRunner, 'Publishing queue runner must process scheduled items via PublishDueItemService.');
    }

    public function test_workspace_save_does_not_touch_publish_schedule(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectWorkspaceSaveService::class))->getFileName(),
        );
        self::assertStringContainsString('last_synced_at', $source);
        self::assertStringContainsString('schedule_touched', $source);
        self::assertStringNotContainsString("'scheduled_publish_at' => \$article->published_at", $source);
    }

    public function test_capability_registry_declares_risk_level(): void
    {
        $registry = new ContentProjectCapabilityRegistry();
        $all = $registry->all();

        self::assertNotEmpty($all);

        foreach ($all as $capability) {
            self::assertArrayHasKey('risk_level', $capability);
            self::assertContains($capability['risk_level'], ['read', 'write', 'publish', 'destructive']);
            self::assertArrayHasKey('idempotency_support', $capability);
            self::assertArrayHasKey('dry_run_support', $capability);
            self::assertArrayHasKey('input_schema', $capability);
            self::assertIsArray($capability['input_schema']);
        }

        self::assertNotNull($registry->get('content_project.rerun_items') ?? $registry->get('content_project.rerun'));
        self::assertNotNull($registry->get('content_project.unschedule'));
        self::assertNotNull($registry->get('content_project.move_schedule'));
        self::assertNotNull($registry->get('content_project.retry_publish'));
        self::assertNotNull($registry->get('content_project.skip_publish'));
        self::assertNotNull($registry->get('content_project.cancel_publish'));
        self::assertNull($registry->get('content_project.process_scheduled_publish'));
    }
}
