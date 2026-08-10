<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\Seo\Support\ExcelFormulaEscaper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract tests (reflection/source) cho archive theo đơn vị Content Project.
 * Không cần DB — bổ sung integration khi môi trường remote sẵn sàng.
 */
final class ArchiveContentProjectServiceTest extends TestCase
{
    public function test_archive_service_public_api_and_constructor_dependencies(): void
    {
        $ref = new ReflectionClass(ArchiveContentProjectService::class);
        $ctor = $ref->getConstructor();

        self::assertNotNull($ctor);
        self::assertGreaterThanOrEqual(4, count($ctor->getParameters()));

        foreach (['buildSummary', 'archive', 'restore', 'getCurrentArchive', 'previewStats', 'archiveGate', 'assertCanArchive'] as $method) {
            self::assertTrue($ref->hasMethod($method), "Missing method {$method}");
        }
    }

    public function test_archive_uses_transaction_lock_and_does_not_touch_task_lifecycle(): void
    {
        $method = (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('archive');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString("DB::connection('omi_seo_ai')->transaction", $source);
        self::assertStringContainsString('lockProject', $source);
        self::assertStringContainsString('archived_at', $source);
        self::assertStringContainsString('content_project_archived', $source);
        self::assertStringContainsString('workspaceDestroyer', $source);
        self::assertStringContainsString('destroyInTransaction', $source);
        self::assertStringContainsString('resetProjectTasksForFreshFlow', $source);
        self::assertStringNotContainsString('taskLifecycle', $source);
        self::assertStringNotContainsString('content_archived_at', $source);
        self::assertStringNotContainsString('SeoProjectTaskLifecycleService', $source);
    }

    public function test_project_archive_resets_tasks_for_a_new_generation_flow(): void
    {
        $method = (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('resetProjectTasksForFreshFlow');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString("'status' => SeoProjectTask::STATUS_PENDING", $source);
        self::assertStringContainsString("'article_id' => null", $source);
        self::assertStringContainsString("'completed_at' => null", $source);
        self::assertStringContainsString("'publishing_queued_at' => null", $source);
        self::assertStringContainsString("'publish_queue_status' => ContentProjectPublishQueueStatus::None->value", $source);
        self::assertStringContainsString("whereNull('archived_at')", $source);
        self::assertStringContainsString('->update($payload)', $source);
    }

    public function test_restore_clears_project_flag_keeps_snapshot(): void
    {
        $method = (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('restore');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('restored_at', $source);
        self::assertStringContainsString('restored_by', $source);
        self::assertStringContainsString('content_project_restored', $source);
        self::assertStringContainsString('workspace_reused', $source);
        self::assertStringNotContainsString('->delete()', $source);
        self::assertStringNotContainsString('taskLifecycle', $source);
    }

    public function test_sync_items_writes_article_snapshot_without_html_body(): void
    {
        $method = (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('buildArticleSnapshot');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString("'title'", $source);
        self::assertStringContainsString('seo_score', $source);
        self::assertStringContainsString('indexed_at', $source);
        self::assertStringContainsString('previous_indexed_at', $source);
        self::assertStringNotContainsString("'body'", $source);
        self::assertStringNotContainsString("'content'", $source);
    }

    public function test_models_expose_archive_relations_and_casts(): void
    {
        $project = new ReflectionClass(SeoProject::class);
        self::assertTrue($project->hasMethod('currentArchive'));
        self::assertTrue($project->hasMethod('isProjectArchived'));
        self::assertTrue($project->hasMethod('scopeActiveProjects'));

        $archive = new ReflectionClass(SeoProjectArchive::class);
        self::assertTrue($archive->hasMethod('items'));
        self::assertTrue($archive->hasMethod('scopeCurrent'));

        $item = new ReflectionClass(SeoProjectArchiveItem::class);
        self::assertTrue($item->hasMethod('article'));
        self::assertTrue($item->hasMethod('archive'));
    }

    public function test_excel_formula_escaper_neutralizes_dangerous_prefixes(): void
    {
        self::assertSame("'=SUM(A1)", ExcelFormulaEscaper::escape('=SUM(A1)'));
        self::assertSame("'+123", ExcelFormulaEscaper::escape('+123'));
        self::assertSame("'-1", ExcelFormulaEscaper::escape('-1'));
        self::assertSame("'@cmd", ExcelFormulaEscaper::escape('@cmd'));
        self::assertSame('safe', ExcelFormulaEscaper::escape('safe'));
        self::assertSame(12, ExcelFormulaEscaper::escape(12));

        $row = ExcelFormulaEscaper::escapeRow(['=1+1', 'ok']);
        self::assertSame(["'=1+1", 'ok'], $row);
    }

    public function test_list_resource_action_uses_archive_content_project_service(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('ArchiveContentProjectService', $source);
        self::assertStringContainsString("Action::make('archive_project')", $source);
        self::assertStringContainsString('isProjectArchived()', $source);
        self::assertStringContainsString("getUrl('archive-preview'", $source);
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
