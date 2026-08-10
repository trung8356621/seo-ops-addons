<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guard test cho fix "Content Project Delete rollback nhầm tháng" (project chỉ còn task
 * archived vẫn hiển thị Total items = 0 trên UI nhưng bị `deleteProjectRollingBackToPreviousMonth`
 * cũ rollback sang tháng trước). Dùng reflection + source-string assertion (không cần DB) để khoá
 * lại: (1) API mới `deleteProject` tồn tại, (2) wrapper cũ không còn tự rollback tháng, (3) UI
 * Filament không còn gọi thẳng API rollback cũ, (4) action archive cấp-project bị ẩn khỏi UI.
 */
final class SeoProjectDeleteNoMonthRollbackTest extends TestCase
{
    public function test_delete_project_method_exists_with_the_expected_return_contract(): void
    {
        $ref = new ReflectionClass(SeoProjectTaskMoveService::class);

        self::assertTrue($ref->hasMethod('deleteProject'));

        $method = $ref->getMethod('deleteProject');
        $params = $method->getParameters();
        self::assertCount(1, $params);
        self::assertSame('project', $params[0]->getName());
    }

    public function test_delete_project_does_not_move_tasks_to_a_previous_month_project(): void
    {
        $method = (new ReflectionClass(SeoProjectTaskMoveService::class))->getMethod('deleteProject');
        $source = $this->readMethodSource($method);

        self::assertStringNotContainsString('findOrCreatePreviousMonthProject', $source);
        self::assertStringNotContainsString('appendTasksToProject', $source);
        self::assertStringContainsString('active()', $source);
        self::assertStringContainsString('ValidationException', $source);
    }

    public function test_deprecated_rollback_wrapper_delegates_to_delete_project_without_moving_tasks(): void
    {
        $ref = new ReflectionClass(SeoProjectTaskMoveService::class);
        self::assertTrue($ref->hasMethod('deleteProjectRollingBackToPreviousMonth'));

        $method = $ref->getMethod('deleteProjectRollingBackToPreviousMonth');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('$this->deleteProject(', $source);
        self::assertStringNotContainsString('findOrCreatePreviousMonthProject', $source);
        self::assertStringNotContainsString('subMonth', $source);
    }

    public function test_move_only_apis_are_still_available_for_the_separate_move_feature(): void
    {
        $ref = new ReflectionClass(SeoProjectTaskMoveService::class);

        self::assertTrue($ref->hasMethod('moveTasksToProject'));
        self::assertTrue($ref->hasMethod('moveTargetOptions'));
        self::assertTrue($ref->hasMethod('findOrCreatePreviousMonthProject'));
        self::assertTrue($ref->hasMethod('assertTargetHasCapacity'));
    }

    public function test_seo_project_resource_delete_actions_call_delete_project_not_the_rollback_wrapper(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SeoProjectResource::class))->getFileName());

        self::assertStringNotContainsString('deleteProjectRollingBackToPreviousMonth', $source);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, '->deleteProject('),
            'Expected the table DeleteAction and DeleteBulkAction to both call deleteProject().',
        );
        self::assertStringNotContainsString('delete_completed_rollback_body', $source);
    }

    public function test_seo_project_resource_hides_the_project_level_archive_action(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SeoProjectResource::class))->getFileName());

        self::assertStringContainsString("Tables\\Actions\\Action::make('archive_project_articles')", $source);

        $tableActionBlockStart = strpos($source, "Tables\\Actions\\Action::make('archive_project_articles')");
        self::assertIsInt($tableActionBlockStart);
        $tableActionBlock = substr($source, $tableActionBlockStart, 400);
        self::assertStringContainsString('->visible(false)', $tableActionBlock);

        self::assertTrue(method_exists(SeoProjectResource::class, 'makeArchiveProjectPageAction'));
        $pageActionMethod = (new ReflectionClass(SeoProjectResource::class))->getMethod('makeArchiveProjectPageAction');
        $pageActionSource = $this->readMethodSource($pageActionMethod);
        self::assertStringContainsString('->visible(false)', $pageActionSource);
    }

    private function readMethodSource(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
