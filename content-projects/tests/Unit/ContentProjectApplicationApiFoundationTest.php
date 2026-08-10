<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\ContentProjectDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\ContentProjectItemDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\ContentProjectRuntimeDto;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectApplicationApiFoundationTest extends TestCase
{
    public function test_public_ref_round_trip(): void
    {
        $projectRef = ContentProjectPublicRef::project(42);
        $itemRef = ContentProjectPublicRef::item(99);
        $articleRef = ContentProjectPublicRef::article(7);
        $executionRef = ContentProjectPublicRef::execution(15);

        self::assertSame(42, ContentProjectPublicRef::resolveProjectId($projectRef));
        self::assertSame(99, ContentProjectPublicRef::resolveItemId($itemRef));
        self::assertSame(42, ContentProjectPublicRef::resolveProjectId(42));
        self::assertSame(99, ContentProjectPublicRef::resolveItemId('99'));
        self::assertStringStartsWith('cpj_', $projectRef);
        self::assertStringStartsWith('cpi_', $itemRef);
        self::assertStringStartsWith('cpa_', $articleRef);
        self::assertStringStartsWith('cpx_', $executionRef);
    }

    public function test_action_result_exposes_stable_refs(): void
    {
        $result = ContentProjectActionResult::ok(
            ContentProjectActionCodes::ITEMS_SCHEDULED,
            'ok',
            5,
            [10, 11],
        );

        $payload = $result->toArray();
        self::assertTrue($payload['success']);
        self::assertSame(ContentProjectActionCodes::ITEMS_SCHEDULED, $payload['code']);
        self::assertSame(5, $payload['project_id']);
        self::assertSame(ContentProjectPublicRef::project(5), $payload['project_ref']);
        self::assertSame([ContentProjectPublicRef::item(10), ContentProjectPublicRef::item(11)], $payload['affected_item_refs']);
    }

    public function test_dto_does_not_leak_run_internals(): void
    {
        $projectDto = new ContentProjectDto(
            projectRef: ContentProjectPublicRef::project(1),
            name: 'Demo',
            siteId: 2,
            month: '2026-07-01',
            archived: false,
            stats: ['waiting_publish' => 1],
            createdAt: null,
            archivedAt: null,
        );
        $itemDto = new ContentProjectItemDto(
            itemRef: ContentProjectPublicRef::item(3),
            projectRef: ContentProjectPublicRef::project(1),
            articleRef: ContentProjectPublicRef::article(4),
            lifecycle: 'approved',
            publishQueueStatus: 'waiting',
            scheduledPublishAt: null,
            publishRetryCount: 0,
            lastPublishAttemptAt: null,
            lastPublishError: null,
            publishPublishedAt: null,
            title: 'Title',
        );
        $runtime = new ContentProjectRuntimeDto(
            ContentProjectPublicRef::project(1),
            'idle',
            ['has_active_execution' => false, 'execution_ref' => null],
        );

        $projectJson = json_encode($projectDto->toArray(), JSON_THROW_ON_ERROR);
        $itemJson = json_encode($itemDto->toArray(), JSON_THROW_ON_ERROR);
        $runtimeJson = json_encode($runtime->toArray(), JSON_THROW_ON_ERROR);

        foreach ([$projectJson, $itemJson, $runtimeJson] as $json) {
            self::assertStringNotContainsString('seo_project_runs', $json);
            self::assertStringNotContainsString('queue_token', $json);
            self::assertStringNotContainsString('lock_key', $json);
            self::assertStringNotContainsString('stop_token', $json);
            self::assertStringNotContainsString('serialized', $json);
        }
    }

    public function test_capability_registry_points_to_commands(): void
    {
        $registry = new ContentProjectCapabilityRegistry();
        $all = $registry->all();
        self::assertNotEmpty($all);

        $publish = $registry->get('content_project.publish_now');
        self::assertNotNull($publish);
        self::assertTrue($publish['confirmation_requirement']);
        self::assertStringContainsString('Command', (string) $publish['handler']);

        $archive = $registry->get('content_project.archive');
        self::assertNotNull($archive);
        self::assertTrue($archive['confirmation_requirement']);
    }

    public function test_command_bus_registrar_covers_core_commands(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar::class
            ))->getFileName()
        );

        foreach ([
            'CreateContentProjectCommand',
            'GenerateProjectItemsCommand',
            'ScheduleProjectItemsCommand',
            'PublishProjectItemsNowCommand',
            'ArchiveContentProjectCommand',
            'ArchiveProjectItemsCommand',
            'RestoreContentProjectCommand',
            'ApproveProjectItemsCommand',
        ] as $command) {
            self::assertStringContainsString($command, $source);
        }
    }

    public function test_api_controller_does_not_expose_seo_project_run(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Http/Controllers/Api/V1/ContentProjectApiController.php';
        $source = (string) file_get_contents($path);
        self::assertDoesNotMatchRegularExpression('/\buse\s+[^;]*SeoProjectRun\b/', $source);
        self::assertDoesNotMatchRegularExpression('/\bSeoProjectRun::/', $source);
        self::assertStringNotContainsString('run-history', $source);
        self::assertStringContainsString('ContentProjectCommandBus', $source);
    }

    public function test_wordpress_publisher_reconcile_before_create(): void
    {
        $path = ProjectRoot::addonsPath().'/wordpress/src/Extension/WordPressPublisher.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('findByExternalReference', $source);
        self::assertStringContainsString('deliveryRequested', $source);
        self::assertStringContainsString('Publish update delivery requested', $source);
        self::assertStringContainsString('publishForArticle', $source);
        self::assertStringNotContainsString('Already published (wp_post_id present)', $source);
    }

    public function test_publish_handler_uses_publisher_resolver_not_wordpress_impl(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/ProcessScheduledProjectItemPublishHandler.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('PublisherResolver', $source);
        self::assertStringNotContainsString('WordPressContentPublisher', $source);
        self::assertStringNotContainsString('WordPressPublisher', $source);
        self::assertStringNotContainsString('ContentPublisher $publisher', $source);
    }
}
