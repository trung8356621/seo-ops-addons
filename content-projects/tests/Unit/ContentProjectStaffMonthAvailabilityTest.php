<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\CreateSeoProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\UnassignedContentProjectStaffWidget;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Guard: staff availability theo tháng (không toàn lịch sử active).
 */
final class ContentProjectStaffMonthAvailabilityTest extends TestCase
{
    public function test_month_context_normalizes_yyyy_mm_and_defaults_current(): void
    {
        self::assertSame('2026-08', ContentProjectMonthContext::normalize('2026-08'));
        self::assertSame('2026-08', ContentProjectMonthContext::normalize('2026-08-15'));
        self::assertSame('2026-08-01', ContentProjectMonthContext::toDateString('2026-08'));
        self::assertSame('08/2026', ContentProjectMonthContext::display('2026-08'));
        self::assertNull(ContentProjectMonthContext::parseOrNull('not-a-month'));
        self::assertNull(ContentProjectMonthContext::parseOrNull('2026-13'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', ContentProjectMonthContext::current());
    }

    public function test_service_filters_assigned_staff_by_month_not_all_history(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectStaffAvailabilityService::class))->getFileName(),
        );

        self::assertStringContainsString('assignedStaffIdsForMonth', $source);
        self::assertStringContainsString('whereDate(\'month\'', $source);
        self::assertStringContainsString('activeProjects()', $source);
        self::assertStringContainsString('KIND_MONTHLY', $source);
        self::assertStringContainsString('getUnassignedStaffForMonth', $source);
        self::assertStringContainsString('assertUnassignedForMonth', $source);
        self::assertStringContainsString('Month uniqueness retired', $source);
        self::assertStringContainsString("pluck('user_id')", $source);
        self::assertStringNotContainsString('nickname', $source);
        self::assertStringNotContainsString('display_name', $source);
        // Không scope domain mặc định.
        self::assertStringNotContainsString("->where('site_id'", $source);
    }

    public function test_create_url_includes_staff_and_month(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectStaffAvailabilityService::class))->getFileName(),
        );

        self::assertStringContainsString("\$params['month']", $source);
        self::assertStringContainsString("\$params['staff']", $source);
        self::assertStringContainsString('writer_id', $source);
    }

    public function test_list_page_uses_planning_month_toolbar_without_staff_without_project_ui(): void
    {
        $listSource = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString('planningMonth', $listSource);
        self::assertStringContainsString('list-seo-projects', $listSource);
        self::assertStringContainsString('getHeaderWidgets', $listSource);
        self::assertStringNotContainsString('getUnassignedStaffPayload', $listSource);
        self::assertStringNotContainsString('staffSearch', $listSource);

        $view = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php');
        self::assertStringContainsString('wire:model.live="planningMonth"', $view);
        self::assertStringNotContainsString('unassigned_staff_badge', $view);
        self::assertStringNotContainsString('staffSearch', $view);
        self::assertStringNotContainsString('fi-wi-widget', $view);

        self::assertFalse(UnassignedContentProjectStaffWidget::canView());
    }

    public function test_create_page_reads_month_and_staff_query_and_validates_race(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(CreateSeoProject::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectMonthContext', $source);
        self::assertStringContainsString("request()->query('month'", $source);
        self::assertStringContainsString("request()->query('staff'", $source);
        self::assertStringContainsString('withAssignmentLock', $source);
        self::assertStringContainsString('shouldEnforceStaffMonthUniqueness', $source);
        self::assertStringContainsString('return false;', $source);
        self::assertStringNotContainsString('assertUnassignedForMonth($userId', $source);
    }

    public function test_form_staff_options_react_to_month(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('groupedWriterSelectOptions(', $source);
        self::assertStringContainsString('groupedSelectOptions($month)', $source);
        self::assertStringContainsString('eligible_staff_heading', $source);
        self::assertStringContainsString('afterStateUpdated', $source);
    }

    public function test_translations_cover_month_scoped_copy(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');

        // Legacy one-project-per-user keys may remain in lang; list UI no longer surfaces them.
        foreach (['planning_month', 'project_month', 'eligible_staff_heading'] as $key) {
            self::assertStringContainsString("'{$key}'", $en);
            self::assertStringContainsString("'{$key}'", $vi);
        }

        self::assertStringContainsString(':month', $en);
        self::assertStringContainsString(':month', $vi);
    }

    public function test_assigned_staff_ids_for_month_method_signature(): void
    {
        $method = new ReflectionMethod(ContentProjectStaffAvailabilityService::class, 'assignedStaffIdsForMonth');
        self::assertSame(1, $method->getNumberOfParameters());

        $unassigned = new ReflectionMethod(ContentProjectStaffAvailabilityService::class, 'unassignedStaffQuery');
        self::assertGreaterThanOrEqual(1, $unassigned->getNumberOfParameters());
    }
}
