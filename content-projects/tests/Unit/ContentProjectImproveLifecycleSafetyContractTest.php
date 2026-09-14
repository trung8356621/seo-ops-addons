<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ContentProjectImproveLifecycleSafetyContractTest extends TestCase
{
    public function test_generation_handler_blocks_improve_by_default(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/GenerateProjectItemsHandler.php',
        );

        self::assertStringContainsString('ContentProjectImproveManualOnlyGenerationGuard', $src);
        self::assertStringContainsString('filterItemIds', $src);

        $decision = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectBulkItemDecisionService.php',
        );
        self::assertStringContainsString('improve_manual_only', $decision);
        self::assertStringContainsString('TYPE_IMPROVE', $decision);
    }

    public function test_rerun_handler_blocks_improve_by_default(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/RerunProjectItemsHandler.php',
        );

        self::assertStringContainsString('ContentProjectImproveManualOnlyGenerationGuard', $src);
        self::assertStringContainsString('AI rerun is blocked', $src);
    }

    public function test_ui_bulk_preview_excludes_improve(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );

        self::assertStringContainsString('manual_only_improve', $src);
        self::assertStringContainsString('TYPE_IMPROVE', $src);
    }
}

