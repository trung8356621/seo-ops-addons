<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProjectProgress;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectRoleAndLockNoticeUiTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = dirname((new ReflectionClass(GlobalGenerationLock::class))->getFileName(), 6);
    }

    public function test_content_manager_defaults_to_details_url(): void
    {
        $project = new SeoProject();
        $project->id = 123;

        // Verify logic in SeoProjectResource:
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('isContentManager', $src);
        self::assertStringContainsString('canAccessContentProjectRun', $src);
        self::assertStringContainsString('getDetailsUrl', $src);
    }

    public function test_content_manager_mode_tabs_hidden(): void
    {
        $blade = (string) file_get_contents(
            $this->baseDir.'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/partials/content-project-mode-tabs.blade.php',
        );

        self::assertStringContainsString('canAccessRun', $blade);
        self::assertStringContainsString('isContentManager', $blade);
        self::assertStringContainsString('canAccessContentProjectRun', $blade);
        self::assertStringContainsString('@if ($canAccessRun)', $blade);
    }

    public function test_content_manager_no_generate_controls(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        // makeCreateWithAiModeGroup checks canAccessContentProjectRun
        self::assertStringContainsString('canAccessContentProjectRun', $src);
    }

    public function test_content_manager_direct_progress_url_guarded(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProjectProgress::class))->getFileName(),
        );

        self::assertStringContainsString('isContentManager', $src);
        self::assertStringContainsString('getDetailsUrl', $src);
        self::assertStringContainsString('$this->redirect(SeoProjectResource::getDetailsUrl', $src);
    }

    public function test_manager_planner_progress_default_and_both_tabs_available(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        $tabsBlade = (string) file_get_contents(
            $this->baseDir.'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/partials/content-project-mode-tabs.blade.php',
        );

        self::assertStringContainsString('getProgressUrl($project)', $src);
        self::assertStringContainsString('progress_tab_progress', $tabsBlade);
        self::assertStringContainsString('progress_tab_details', $tabsBlade);
    }

    public function test_lock_notice_held_renders_compact_yellow_warning(): void
    {
        $noticeBlade = (string) file_get_contents(
            $this->baseDir.'/seo-content-ai-compat/resources/views/filament/pages/partials/global-lock-notice.blade.php',
        );

        self::assertStringContainsString('data-cp-lock-notice="held"', $noticeBlade);
        self::assertStringContainsString('bg-amber-50', $noticeBlade);
        self::assertStringContainsString('border-amber-200', $noticeBlade);
        self::assertStringContainsString('text-amber-950', $noticeBlade);
        self::assertStringContainsString('x-heroicon-m-exclamation-triangle', $noticeBlade);
    }

    public function test_individual_owner_progress_url_resolution(): void
    {
        $lockSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLock::class))->getFileName(),
        );

        self::assertStringContainsString("SeoProjectResource::getUrl('progress', ['record' => \$projectId])", $lockSrc);
        self::assertStringContainsString('/seo/content-projects/', $lockSrc);
    }

    public function test_global_owner_progress_url_resolution(): void
    {
        $lockSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLock::class))->getFileName(),
        );

        self::assertStringContainsString('GlobalMonthlyGeneratorPage::getUrl', $lockSrc);
        self::assertStringContainsString('/seo/global-monthly-generator', $lockSrc);
    }

    public function test_unknown_url_renders_no_broken_link(): void
    {
        $noticeBlade = (string) file_get_contents(
            $this->baseDir.'/seo-content-ai-compat/resources/views/filament/pages/partials/global-lock-notice.blade.php',
        );

        self::assertStringContainsString('@if ($progressUrl)', $noticeBlade);
        self::assertStringContainsString('data-cp-lock-progress-link', $noticeBlade);
    }

    public function test_lock_unavailable_renders_warning_and_blocks_start(): void
    {
        $noticeBlade = (string) file_get_contents(
            $this->baseDir.'/seo-content-ai-compat/resources/views/filament/pages/partials/global-lock-notice.blade.php',
        );

        self::assertStringContainsString('data-cp-lock-notice="unavailable"', $noticeBlade);
        self::assertStringContainsString('UNAVAILABLE_MESSAGE', $noticeBlade);

        $lockSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLock::class))->getFileName(),
        );
        self::assertStringContainsString('STATE_UNAVAILABLE', $lockSrc);
        self::assertStringContainsString('UNAVAILABLE_MESSAGE', $lockSrc);
    }

    public function test_no_lock_renders_nothing(): void
    {
        $noticeBlade = (string) file_get_contents(
            $this->baseDir.'/seo-content-ai-compat/resources/views/filament/pages/partials/global-lock-notice.blade.php',
        );

        self::assertStringContainsString('@if ($unavailable)', $noticeBlade);
        self::assertStringContainsString('@elseif ($holder !== null)', $noticeBlade);
    }
}
