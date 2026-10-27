<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProjectProgress;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProgressReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectProgressPageContractTest extends TestCase
{
    public function test_progress_route_registered_and_workspace_defaults_to_progress(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString("Pages\\ViewSeoProjectProgress::route('/{record}/progress')", $resource);
        self::assertStringContainsString('function getProgressUrl', $resource);
        self::assertStringContainsString('function getDetailsUrl', $resource);

        $workspace = (string) (new ReflectionClass(SeoProjectResource::class))
            ->getMethod('getProjectWorkspaceUrl')
            ->getFileName();
        unset($workspace);

        $src = $resource;
        $pos = strpos($src, 'function getProjectWorkspaceUrl');
        self::assertNotFalse($pos);
        $slice = substr($src, $pos, 280);
        self::assertStringContainsString('getProgressUrl', $slice);
        self::assertStringNotContainsString("getUrl('view'", $slice);
    }

    public function test_progress_page_reuses_stop_command_and_lightweight_read_model(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProjectProgress::class))->getFileName(),
        );
        $readModel = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectProgressReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectProgressReadModel', $page);
        self::assertStringContainsString('StopProjectExecutionCommand', $page);
        self::assertStringContainsString('function emergencyStop', $page);
        self::assertStringContainsString('makeCreateWithAiAction', $page);
        self::assertStringContainsString('makeCreateWithAiModeGroup', $page);
        self::assertStringContainsString('GlobalGenerationLock', $page);
        self::assertStringContainsString('generateControlsLocked', $page);
        self::assertStringContainsString('wire:poll', (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-progress.blade.php'),
        ));

        self::assertStringContainsString("articleExecution()", $readModel);
        self::assertStringContainsString("orderBy('id')", $readModel);
        self::assertStringContainsString('ITEM_COLUMNS', $readModel);
        self::assertStringContainsString('->get(self::ITEM_COLUMNS)', $readModel);
        self::assertDoesNotMatchRegularExpression("/ITEM_COLUMNS\\s*=\\s*\\[[^\\]]*output_snapshot/s", $readModel);
        self::assertDoesNotMatchRegularExpression("/ITEM_COLUMNS\\s*=\\s*\\[[^\\]]*input_snapshot/s", $readModel);
        self::assertStringContainsString("'completed'", $readModel);
        self::assertStringContainsString("'processing'", $readModel);
        self::assertStringContainsString("'failed'", $readModel);
        self::assertStringContainsString("'pending'", $readModel);
        self::assertStringContainsString("'skipped'", $readModel);
        self::assertStringContainsString("'cancelled'", $readModel);
    }

    public function test_details_page_keeps_ops_table_and_gains_mode_tabs(): void
    {
        $view = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );

        self::assertStringContainsString('view-seo-project-operations', $view);
        self::assertStringContainsString('ContentProjectItemOperationsReadModel', $view);
        self::assertStringContainsString('content-project-mode-tabs', $blade);
        self::assertStringContainsString("'activeMode' => 'details'", $blade);
    }

    public function test_duration_formatter(): void
    {
        self::assertSame('45 giây', ContentProjectProgressReadModel::formatDuration(45));
        self::assertSame('4 phút 32 giây', ContentProjectProgressReadModel::formatDuration(272));
        self::assertSame('1 giờ 2 phút 3 giây', ContentProjectProgressReadModel::formatDuration(3723));
    }
}
