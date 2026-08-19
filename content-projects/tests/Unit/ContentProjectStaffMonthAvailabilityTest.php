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
        self::assertStringContainsString('lockForUpdate', $source);
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

    public function test_list_page_uses_planning_toolbar_not_static_widget_card(): void
    {
        $listSource = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString('planningMonth', $listSource);
        self::assertStringContainsString('list-seo-projects', $listSource);
        self::assertStringContainsString('return [];', $listSource); // getHeaderWidgets empty

        $viewPath = dirname((string) (new ReflectionClass(ListSeoProjects::class))->getFileName(), 5)
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'seo-project-resource'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'list-seo-projects.blade.php';

        self::assertFileExists($viewPath);
        $view = (string) file_get_contents($viewPath);
        self::assertStringContainsString('wire:model.live="planningMonth"', $view);
        self::assertStringContainsString('staffSearch', $view);
        self::assertStringContainsString('max-h-72 overflow-y-auto', $view);
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
        self::assertStringContainsString('assertUnassignedForMonth', $source);
        self::assertStringContainsString('withAssignmentLock', $source);
        self::assertStringContainsString('shouldEnforceStaffMonthUniqueness', $source);
    }

    public function test_form_staff_options_react_to_month(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('groupedWriterSelectOptions(', $source);
        self::assertStringContainsString('groupedSelectOptions($month)', $source);
        self::assertStringContainsString('unassigned_staff_already_assigned', $source);
        self::assertStringContainsString('afterStateUpdated', $source);
    }

    public function test_translations_cover_month_scoped_copy(): void
    {
        $en = (string) file_get_contents(
            dirname((string) (new ReflectionClass(SeoProjectResource::class))->getFileName(), 3)
            .DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'filament.php',
        );
        $vi = (string) file_get_contents(
            dirname((string) (new ReflectionClass(SeoProjectResource::class))->getFileName(), 3)
            .DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'vi'.DIRECTORY_SEPARATOR.'filament.php',
        );

        foreach (['planning_month', 'unassigned_staff_view', 'unassigned_staff_search', 'unassigned_staff_badge', 'unassigned_staff_already_assigned', 'staff_availability', 'project_month'] as $key) {
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
