<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectPlannerRunDetail;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class ContentProjectPlannerRunDetailTest extends TestCase
{
    public function test_page_scopes_run_to_draft_and_registers_no_nav(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ContentProjectPlannerRunDetail::class))->getFileName());

        self::assertStringContainsString('findForProject', $src);
        self::assertStringContainsString('abort_unless', $src);
        self::assertStringContainsString('shouldRegisterNavigation = false', $src);
        self::assertStringContainsString('canAccessSite', $src);
        self::assertStringContainsString('ContentProjectPlannerRunService', $src);
        self::assertTrue(class_exists(ContentProjectPlannerRunService::class));
    }

    public function test_planner_exposes_detail_url_helper(): void
    {
        $planner = (string) file_get_contents((new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName());
        self::assertStringContainsString('plannerRunDetailUrl', $planner);
        self::assertStringContainsString('ContentProjectPlannerRunDetail::urlFor', $planner);
    }

    public function test_detail_blade_renders_summary_and_candidates(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(ContentProjectPlannerRunDetail::class))->getFileName());
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/content-project-planner-run-detail.blade.php',
        );
        self::assertStringContainsString('planner_run_summary_line', $blade);
        self::assertStringContainsString('decision_label', $blade);
        self::assertStringContainsString('data-planner-run-candidates', $blade);
        self::assertStringContainsString('STATUS_DUPLICATE_IN_BATCH_KEYWORD', $page);
        self::assertStringContainsString('planner_decision_duplicate_in_batch_keyword', $page);
    }
}
