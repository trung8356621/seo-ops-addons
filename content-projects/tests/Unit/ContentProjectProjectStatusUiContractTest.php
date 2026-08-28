<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProjectStatusPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\TestCase;

/**
 * Projects list cleanup: no bulk select, status colors, delete guards, action visibility.
 */
final class ContentProjectProjectStatusUiContractTest extends TestCase
{
    public function test_exact_project_status_constants(): void
    {
        self::assertSame('draft', SeoProject::STATUS_DRAFT);
        self::assertSame('pending', SeoProject::STATUS_PENDING);
        self::assertSame('manual', SeoProject::STATUS_MANUAL);
        self::assertSame('running', SeoProject::STATUS_RUNNING);
        self::assertSame('completed', SeoProject::STATUS_COMPLETED);
        self::assertSame('paused', SeoProject::STATUS_PAUSED);
        self::assertSame('approved', SeoProject::STATUS_APPROVED);
        self::assertFalse(defined(SeoProject::class.'::STATUS_ARCHIVED'));
    }

    public function test_presenter_maps_statuses_to_distinct_colors(): void
    {
        $map = ContentProjectProjectStatusPresenter::colorMap();
        self::assertSame('warning', $map[SeoProject::STATUS_DRAFT]);
        self::assertSame('info', $map[SeoProject::STATUS_PENDING]);
        self::assertSame('primary', $map[SeoProject::STATUS_MANUAL]);
        self::assertSame('success', $map[SeoProject::STATUS_RUNNING]);
        self::assertSame('gray', $map['archived']);

        $draft = new SeoProject([
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
        ]);
        $pending = new SeoProject([
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
        ]);
        $manual = new SeoProject([
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
        ]);
        $archived = new SeoProject([
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => '2026-08-01 12:00:00',
        ]);

        $draftP = ContentProjectProjectStatusPresenter::present($draft);
        $pendingP = ContentProjectProjectStatusPresenter::present($pending);
        $manualP = ContentProjectProjectStatusPresenter::present($manual);
        $archivedP = ContentProjectProjectStatusPresenter::present($archived);

        self::assertSame('warning', $draftP['color']);
        self::assertSame('info', $pendingP['color']);
        self::assertSame('primary', $manualP['color']);
        self::assertSame('gray', $archivedP['color']);
        self::assertNotSame($draftP['color'], $pendingP['color']);
        self::assertNotSame($pendingP['color'], $manualP['color']);
        self::assertNotSame($pendingP['color'], $archivedP['color']);
        self::assertNotSame($draftP['color'], $archivedP['color']);
        self::assertSame('draft', $draftP['key']);
        self::assertSame('pending', $pendingP['key']);
        self::assertSame('manual', $manualP['key']);
        self::assertSame('archived', $archivedP['key']);
    }

    public function test_list_has_no_bulk_select_or_bulk_delete(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('->bulkActions([])', $resource);
        self::assertStringNotContainsString('DeleteBulkAction::make()', $resource);
        self::assertStringNotContainsString('seoPanelBulkActions([', $resource);
        self::assertStringNotContainsString('BulkActionGroup::make([', $resource);
    }

    public function test_list_and_delete_guards_wire_draft_and_archived(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString('isDraftPlanning()', $resource);
        self::assertStringContainsString('hasListOverflowActions', $resource);
        self::assertStringContainsString('ContentProjectProjectStatusPresenter::label', $resource);
        self::assertStringContainsString('ContentProjectProjectStatusPresenter::color', $resource);
        self::assertStringContainsString('! $record->isDraftPlanning()', $resource);

        $move = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTaskMoveService::class))->getFileName(),
        );
        self::assertStringContainsString('delete_draft_forbidden', $move);
        self::assertStringContainsString('delete_archived_forbidden', $move);
        self::assertStringContainsString('isDraftPlanning()', $move);
        self::assertStringContainsString('isProjectArchived()', $move);
        self::assertStringContainsString('isRestorableUnstartedExecution', $move);
    }

    public function test_can_delete_rejects_draft_and_archived_without_auth_context(): void
    {
        $draft = new SeoProject([
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
        ]);
        self::assertTrue($draft->isDraftPlanning());
        self::assertFalse(SeoProjectResource::canDelete($draft));

        $archived = new SeoProject([
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => '2026-08-01 12:00:00',
        ]);
        self::assertTrue($archived->isProjectArchived());
        self::assertFalse(SeoProjectResource::canDelete($archived));
    }

    public function test_has_list_overflow_actions_false_for_draft_and_archived(): void
    {
        $draft = new SeoProject([
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
        ]);
        self::assertFalse(SeoProjectResource::hasListOverflowActions($draft));

        $archived = new SeoProject([
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => '2026-08-01 12:00:00',
        ]);
        self::assertFalse(SeoProjectResource::hasListOverflowActions($archived));

        $pending = new SeoProject([
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
        ]);
        self::assertTrue(SeoProjectResource::hasListOverflowActions($pending));
    }

    public function test_writer_display_uses_safe_dash_not_unassigned_copy(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectWriterAssignment::class))->getFileName(),
        );
        self::assertStringContainsString("return '—';", $src);
        self::assertStringNotContainsString('project_no_assignee_badge', $src);

        $empty = new SeoProject(['user_id' => null]);
        self::assertSame('—', ContentProjectWriterAssignment::displayLabel($empty));
    }

    public function test_list_page_retired_staff_without_project_ui(): void
    {
        $listSource = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );
        self::assertStringNotContainsString('getUnassignedStaffPayload', $listSource);
        self::assertStringNotContainsString('canViewUnassignedStaff', $listSource);
        self::assertStringNotContainsString('staffSearch', $listSource);

        $view = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php');
        self::assertStringContainsString('wire:model.live="planningMonth"', $view);
        self::assertStringNotContainsString('unassigned_staff_badge', $view);
        self::assertStringNotContainsString('unassigned_staff_view', $view);
        self::assertStringNotContainsString('getUnassignedStaffPayload', $view);
        self::assertStringNotContainsString('staff without a project', $view);
        self::assertStringNotContainsString('staff chưa có project', $view);
    }
}
