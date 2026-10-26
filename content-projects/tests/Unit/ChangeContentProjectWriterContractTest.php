<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ChangeContentProjectWriterCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ChangeContentProjectWriterHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ChangeContentProjectWriterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ChangeContentProjectWriterContractTest extends TestCase
{
    public function test_action_code_and_command_name_are_stable(): void
    {
        self::assertSame('project.writer_changed', ContentProjectActionCodes::PROJECT_WRITER_CHANGED);
        self::assertSame('content_project.change_writer', (new ChangeContentProjectWriterCommand(1, 2))->name());
    }

    public function test_command_bus_registers_change_writer_handler(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName(),
        );

        self::assertStringContainsString('ChangeContentProjectWriterCommand::class => ChangeContentProjectWriterHandler::class', $source);
    }

    public function test_handler_enforces_mutate_permission(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ChangeContentProjectWriterHandler::class))->getFileName(),
        );

        self::assertStringContainsString('canMutateContentProjects', $source);
        self::assertStringContainsString('ChangeContentProjectWriterService', $source);
        self::assertStringContainsString('PROJECT_WRITER_CHANGED', $source);
    }

    public function test_service_preserves_assignment_only_semantics(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ChangeContentProjectWriterService::class))->getFileName(),
        );

        self::assertStringContainsString('user_id = $newWriterId', $source);
        self::assertStringContainsString('syncProjectArticles', $source);
        self::assertStringContainsString('WriterMonthlyCapacityGate', $source);
        self::assertStringContainsString('STATUS_RUNNING', $source);
        self::assertStringContainsString('Processing', $source);
        // Assignment-only: do not rewrite queue status or delete rows.
        self::assertStringNotContainsString("'publish_queue_status' =>", $source);
        self::assertStringNotContainsString('->delete()', $source);
    }

    public function test_list_action_is_under_open_project_items(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        $openPos = strpos($source, "Action::make('open_project_items')");
        $changePos = strpos($source, "Action::make('change_writer')");
        $archivePos = strpos($source, "Action::make('archive_project')");

        self::assertNotFalse($openPos);
        self::assertNotFalse($changePos);
        self::assertNotFalse($archivePos);
        self::assertGreaterThan($openPos, $changePos);
        self::assertGreaterThan($changePos, $archivePos);
        self::assertStringContainsString('ChangeContentProjectWriterCommand', $source);
        self::assertStringContainsString('canMutateContentProjects', $source);
        self::assertStringContainsString('new_writer_id', $source);
    }
}
