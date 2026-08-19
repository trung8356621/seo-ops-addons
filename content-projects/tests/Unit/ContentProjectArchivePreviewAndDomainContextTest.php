<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\CreateSeoProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\UnassignedContentProjectStaffWidget;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guard: archive preview route, global domain ≠ auth, unassigned staff, modal counts.
 */
final class ContentProjectArchivePreviewAndDomainContextTest extends TestCase
{
    public function test_archive_preview_route_uses_archive_id_parameter(): void
    {
        $pages = SeoProjectResource::getPages();
        self::assertArrayHasKey('archive-preview', $pages);

        $source = (string) file_get_contents((new ReflectionClass(SeoProjectResource::class))->getFileName());
        self::assertStringContainsString("Pages\\ContentProjectArchivePreview::route('/archive/{archive}/preview')", $source);
    }

    public function test_archive_preview_page_keeps_route_param_scalar_not_model_property(): void
    {
        $ref = new ReflectionClass(ContentProjectArchivePreview::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString('public int|string $archive', $source);
        self::assertStringContainsString('public ?SeoProjectArchive $archiveRecord', $source);
        self::assertStringNotContainsString('public ?SeoProjectArchive $archive =', $source);
        self::assertStringContainsString('findOrFail', $source);
        self::assertStringContainsString('ModelNotFoundException', $source);
        self::assertStringContainsString('RuntimeLogger::report', $source);
        self::assertStringContainsString('archive_id', $source);
        self::assertStringContainsString('source_project_id', $source);
    }

    public function test_preview_action_url_uses_archive_key(): void
    {
        $viewPath = LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php');
        $view = (string) file_get_contents($viewPath);

        self::assertStringContainsString("getUrl('archive-preview', ['archive' => \$archive->id])", $view);
    }

    public function test_project_record_binding_skips_global_site_scope(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'getRecordRouteBindingEloquentQuery');
        $source = $this->readMethodSource($method);

        self::assertStringNotContainsString('applyGlobalSiteScopeToProjectQuery', $source);
        self::assertStringNotContainsString('activeProjects()', $source);
        self::assertStringContainsString('applyAccessibleSiteScope', $source);
    }

    public function test_project_list_query_still_applies_global_site_scope(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'getEloquentQuery');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('applyGlobalSiteScopeToProjectQuery', $source);
        self::assertStringContainsString('currentArchive', $source);
        self::assertStringContainsString('activeGenerated()', $source);
        self::assertStringNotContainsString('activeProjects()', $source);
    }

    public function test_project_record_url_routes_archived_to_archive_preview(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'projectRecordUrl');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('isProjectArchived()', $source);
        self::assertStringContainsString("getUrl('archive-preview'", $source);
        self::assertStringContainsString('currentArchiveIdFor', $source);

        $resourceSource = (string) file_get_contents((new ReflectionClass(SeoProjectResource::class))->getFileName());
        self::assertStringContainsString('ViewAction::make()', $resourceSource);
        self::assertStringContainsString(
            '->url(fn (SeoProject $record): string => static::projectRecordUrl($record))',
            $resourceSource,
        );
    }

    public function test_project_can_view_does_not_use_global_scoped_eloquent_query(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'canView');
        $source = $this->readMethodSource($method);

        self::assertStringNotContainsString('getEloquentQuery()', $source);
        self::assertStringContainsString('canAccessSite', $source);
    }

    public function test_article_record_binding_skips_global_site_scope(): void
    {
        $method = new ReflectionMethod(ArticleResource::class, 'getRecordRouteBindingEloquentQuery');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('includeGlobalSiteScope: false', $source);
    }

    public function test_article_resolve_record_route_binding_uses_unscoped_query(): void
    {
        $method = new ReflectionMethod(ArticleResource::class, 'resolveRecordRouteBinding');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('getRecordRouteBindingEloquentQuery()', $source);
        self::assertStringNotContainsString('static::getEloquentQuery()', $source);
    }

    public function test_project_resolve_record_route_binding_uses_unscoped_query(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'resolveRecordRouteBinding');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('getRecordRouteBindingEloquentQuery()', $source);
        self::assertStringNotContainsString('static::getEloquentQuery()', $source);
    }

    public function test_edit_article_does_not_force_switch_global_domain(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(EditArticle::class))->getFileName());

        self::assertStringContainsString('recordDomainDiffersFromGlobal', $source);
        self::assertStringNotContainsString('SeoAccessControl::setGlobalSiteId($articleSiteId)', $source);
        self::assertStringNotContainsString('syncGlobalSiteForArticle($this->record)', $source);
    }

    public function test_archive_modal_summary_omits_approved_line(): void
    {
        $viewPath = LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/partials/archive-project-modal-summary.blade.php');
        $view = (string) file_get_contents($viewPath);

        self::assertStringNotContainsString('Đã duyệt', $view);
        self::assertStringNotContainsString('archive_col_approved', $view);
        self::assertStringContainsString('archive_col_total', $view);
        self::assertStringContainsString('archive_col_completed', $view);
        self::assertStringContainsString('archive_col_synced', $view);
        self::assertStringContainsString('archive_modal_incomplete', $view);
        self::assertStringContainsString('archive_modal_unapproved', $view);
        self::assertStringContainsString('archive_modal_unsynced', $view);
        self::assertStringContainsString('archive_modal_failed', $view);
    }

    public function test_archive_summary_counts_only_active_tasks(): void
    {
        $method = new ReflectionMethod(ArchiveContentProjectService::class, 'articleTasksForProject');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('->active()', $source);
        self::assertStringContainsString("where('article_id', '>', 0)", $source);
    }

    public function test_unassigned_staff_service_filters_role_staff_and_active_projects(): void
    {
        $ref = new ReflectionClass(ContentProjectStaffAvailabilityService::class);
        self::assertTrue($ref->hasMethod('unassignedStaffQuery'));
        self::assertTrue($ref->hasMethod('isUnassigned'));
        self::assertTrue($ref->hasMethod('widgetPayload'));
        self::assertTrue($ref->hasMethod('assignedStaffIdsForMonth'));

        $source = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString('ROLE_STAFF', $source);
        self::assertStringContainsString('SEO_ROLE_CONTENT_MANAGER', $source);
        self::assertStringContainsString('activeProjects()', $source);
        self::assertStringContainsString('assignedStaffIdsForMonth', $source);
        self::assertStringContainsString('whereDate(\'month\'', $source);
        self::assertStringContainsString('writer_id', $source);
    }

    public function test_list_registers_unassigned_staff_widget(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ListSeoProjects::class))->getFileName());

        self::assertStringContainsString('planningMonth', $source);
        self::assertStringContainsString('list-seo-projects', $source);
        self::assertTrue(class_exists(UnassignedContentProjectStaffWidget::class));
        self::assertFalse(UnassignedContentProjectStaffWidget::canView());
    }

    public function test_create_project_validates_unassigned_race_in_transaction(): void
    {
        $createSource = (string) file_get_contents((new ReflectionClass(CreateSeoProject::class))->getFileName());

        self::assertStringContainsString('withAssignmentLock', $createSource);
        self::assertStringContainsString('assertUnassignedForMonth', $createSource);
        self::assertStringContainsString('writer_id', $createSource);
        self::assertStringContainsString("request()->query('staff'", $createSource);

        $serviceSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectStaffAvailabilityService::class))->getFileName(),
        );
        self::assertStringContainsString('unassigned_staff_race', $serviceSource);
    }

    public function test_should_apply_global_site_scope_helper_exists(): void
    {
        self::assertTrue(method_exists(SeoAccessControl::class, 'shouldApplyGlobalSiteScope'));
        self::assertTrue(method_exists(SeoAccessControl::class, 'globalSiteId'));
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
