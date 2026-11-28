<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SmartDraftPublishPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerDistributionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class SmartDraftPublishPlannerTest extends TestCase
{
    public function test_remaining_capacity_calculation_and_draft_slicing(): void
    {
        $approvedIds = range(101, 120); // 20 approved drafts
        $planner = new SmartDraftPublishPlanner;

        // Case 1: existing 22 + approved 20 + limit 30 => publish 8, keep 12
        $res22 = $planner->planFromCounts(7, '2026-09', 30, 22, $approvedIds);
        self::assertSame(22, $res22['existing']);
        self::assertSame(8, $res22['remaining']);
        self::assertSame(20, $res22['approved_count']);
        self::assertSame(8, $res22['publish_count']);
        self::assertSame(12, $res22['kept_in_draft']);
        self::assertSame(range(101, 108), $res22['task_ids']);
        self::assertTrue($res22['can_publish']);

        // Case 2: existing 30 + limit 30 => publish 0
        $res30 = $planner->planFromCounts(7, '2026-09', 30, 30, $approvedIds);
        self::assertSame(0, $res30['remaining']);
        self::assertSame(0, $res30['publish_count']);
        self::assertSame(20, $res30['kept_in_draft']);
        self::assertSame([], $res30['task_ids']);
        self::assertFalse($res30['can_publish']);

        // Case 3: existing 35 + limit 30 => publish 0
        $res35 = $planner->planFromCounts(7, '2026-09', 30, 35, $approvedIds);
        self::assertSame(0, $res35['remaining']);
        self::assertSame(0, $res35['publish_count']);
        self::assertSame([], $res35['task_ids']);
        self::assertFalse($res35['can_publish']);
    }

    public function test_auto_approve_enabled_only_for_idea_manager_generate_ideas(): void
    {
        $defaultOpts = NewContentSuggestionOptions::normalize([]);
        self::assertFalse($defaultOpts['auto_approve']);

        $autoOpts = NewContentSuggestionOptions::normalize(['auto_approve' => true]);
        self::assertTrue($autoOpts['auto_approve']);

        $snapshot = NewContentSuggestionOptions::snapshot(['auto_approve' => true], 'vi', 7);
        $restored = NewContentSuggestionOptions::fromSnapshot($snapshot);
        self::assertTrue($restored['auto_approve']);

        $distSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName(),
        );
        self::assertStringContainsString("'auto_approve' => true", $distSrc);

        $plannerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString('planning_reviewed_at', $plannerSrc);
        self::assertStringContainsString('bool $autoApprove = false', $plannerSrc);
    }

    public function test_planner_ignores_checkboxes_uses_lock_and_exposes_sticky_limit_ui(): void
    {
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('public int $publishMonthlyLimit = 30;', $pageSrc);
        self::assertStringContainsString('public string $smartPublishPreviewHash', $pageSrc);
        self::assertStringContainsString('SmartDraftPublishPlanner', $pageSrc);
        self::assertStringContainsString('smart_draft_publish:', $pageSrc);
        self::assertStringContainsString('function resolvePublishEligibleTaskIds', $pageSrc);

        $plannerBlade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        self::assertStringContainsString('publishMonthlyLimit', $plannerBlade);
        self::assertStringContainsString('Giới hạn/tháng', $plannerBlade);

        $modalBlade = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        self::assertStringContainsString('data-smart-publish-preview="1"', $modalBlade);
        self::assertStringContainsString('Sẽ Publish', $modalBlade);
        self::assertStringContainsString('Draft còn lại', $modalBlade);
    }

    public function test_split_draft_stamps_target_planning_month_and_syncs_attribution(): void
    {
        $splitSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );

        self::assertStringContainsString("\$payload['planning_month'] = \$targetMonthDate;", $splitSrc);
        self::assertStringContainsString('SeoContentProjectTaskPlanningAttribution::query()', $splitSrc);
        self::assertStringContainsString("'planning_month' => \$targetMonthYm", $splitSrc);
    }

    public function test_sticky_bar_target_month_picker_required_and_modal_is_readonly(): void
    {
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        self::assertStringContainsString("\$this->draftSplitTargetMonth = '';", $pageSrc);
        self::assertStringContainsString('Vui lòng chọn Tháng Publish trước khi Publish.', $pageSrc);
        self::assertStringContainsString('ContentProjectMonthContext::parseOrNull($this->draftSplitTargetMonth ?: null)', $pageSrc);

        $plannerBlade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        self::assertStringContainsString('Tháng Publish', $plannerBlade);
        self::assertStringContainsString('Chọn tháng', $plannerBlade);
        self::assertStringContainsString('cp-month-picker', $plannerBlade);
        self::assertStringContainsString('@disabled(! $canPublishFromBar)', $plannerBlade);

        $modalBlade = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        self::assertStringContainsString('data-split-field="target_month_readonly"', $modalBlade);
    }

    public function test_auto_monthly_limit_formula_and_zero_division_guard(): void
    {
        $planner = new SmartDraftPublishPlanner;

        // 7 Writer × 30 ÷ 5 Site = 42
        $r42 = $planner->computeMonthlySiteLimit(7, 30, 5);
        self::assertTrue($r42['valid']);
        self::assertSame(42, $r42['limit']);
        self::assertSame('7 Writer × 30 bài ÷ 5 Site = 42 bài/site/tháng', $r42['formula_text']);

        // 6 Writer × 30 ÷ 5 Site = 36
        $r36 = $planner->computeMonthlySiteLimit(6, 30, 5);
        self::assertTrue($r36['valid']);
        self::assertSame(36, $r36['limit']);

        // 7 Writer × 30 ÷ 6 Site = 35
        $r35 = $planner->computeMonthlySiteLimit(7, 30, 6);
        self::assertTrue($r35['valid']);
        self::assertSame(35, $r35['limit']);

        // Zero site / zero writer guards (no division by zero)
        $zeroSite = $planner->computeMonthlySiteLimit(7, 30, 0);
        self::assertFalse($zeroSite['valid']);
        self::assertNull($zeroSite['limit']);

        $zeroWriter = $planner->computeMonthlySiteLimit(0, 30, 5);
        self::assertFalse($zeroWriter['valid']);
        self::assertNull($zeroWriter['limit']);

        // 7 Writer / 5 Site => limit 42; existing 30 + approved 18 => publish 12, keep 6
        $plan12 = $planner->planFromCounts(7, '2026-09', (int) $r42['limit'], 30, range(1, 18));
        self::assertSame(12, $plan12['publish_count']);
        self::assertSame(6, $plan12['kept_in_draft']);

        $plannerBlade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        self::assertStringContainsString('data-draft-monthly-limit-formula="1"', $plannerBlade);
        self::assertStringContainsString('Hạn mức tùy chỉnh', $plannerBlade);
        self::assertStringContainsString('Còn thiếu:', $plannerBlade);
        self::assertStringContainsString('data-draft-total-missing="1"', $plannerBlade);

        $modalBlade = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        self::assertStringContainsString('Đã phân phối', $modalBlade);
        self::assertStringContainsString('Draft còn lại', $modalBlade);

        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('function planAllSites', (string) file_get_contents(
            (string) (new ReflectionClass(SmartDraftPublishPlanner::class))->getFileName(),
        ));
        self::assertStringContainsString('smart_draft_publish:all:', $pageSrc);
        self::assertStringContainsString('getTotalMissingForPublishMonthProperty', $pageSrc);
    }
}
