<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use PHPUnit\Framework\TestCase;

/**
 * Batch B freeze â€” BulkRerunService removed; enum maps legacy regenerate_* strings.
 */
final class ContentProjectBulkRerunPhase20Test extends TestCase
{
    public function test_bulk_rerun_service_file_removed(): void
    {
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProjectBulkRerunService.php',
        );
    }

    public function test_rerun_from_step_try_from_mixed_accepts_regenerate_strings(): void
    {
        self::assertSame(
            ContentProjectRerunFromStep::Outline,
            ContentProjectRerunFromStep::tryFromMixed('regenerate_outline'),
        );
        self::assertSame(
            ContentProjectRerunFromStep::Article,
            ContentProjectRerunFromStep::tryFromMixed('regenerate_article'),
        );
        self::assertNull(ContentProjectRerunFromStep::tryFromMixed('regenerate_outline_and_article'));
    }

    public function test_filament_uses_step_command(): void
    {
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString('RerunProjectItemStepCommand', $view);
        self::assertStringContainsString('dispatchBulkStep', $view);
        self::assertStringContainsString('ContentProjectRerunFromStep', $view);
    }
}
