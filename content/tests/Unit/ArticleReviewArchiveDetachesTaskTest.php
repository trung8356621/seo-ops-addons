<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskLifecycleService;
use Omnichannel\Addons\ContentProjects\Console\RepairArchivedArticleActiveTasksCommand;
use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guard: “Hoàn tất duyệt” (Archive action) chỉ đổi trạng thái nghiệp vụ —
 * không detach task, không set content_archived_at.
 * Reopen vẫn có thể restore task đã detach bởi flow legacy.
 */
final class ArticleReviewArchiveDetachesTaskTest extends TestCase
{
    public function test_article_review_service_depends_on_task_lifecycle_service(): void
    {
        $ctor = (new ReflectionClass(ArticleReviewService::class))->getConstructor();

        self::assertNotNull($ctor);
        self::assertSame(
            SeoProjectTaskLifecycleService::class,
            $ctor->getParameters()[0]->getType()?->getName(),
        );
    }

    public function test_archive_side_effect_does_not_detach_tasks_or_set_content_archived_at(): void
    {
        $method = (new ReflectionClass(ArticleReviewService::class))->getMethod('completeReviewWithoutDetaching');
        $source = $this->readMethodSource($method);

        self::assertStringNotContainsString('content_archived_at', $source);
        self::assertStringNotContainsString('taskLifecycle->archive', $source);
        self::assertStringContainsString('detached_task_ids', $source);
        self::assertStringContainsString('[]', $source);
    }

    public function test_reopen_side_effect_still_restores_legacy_detached_tasks(): void
    {
        $method = (new ReflectionClass(ArticleReviewService::class))->getMethod('reopenReviewKeepingProjectLinks');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('content_archived_at', $source);
        self::assertStringContainsString('->archived()', $source);
        self::assertStringContainsString('$this->taskLifecycle->restore(', $source);
    }

    public function test_apply_side_effects_routes_through_non_detaching_archive(): void
    {
        $method = (new ReflectionClass(ArticleReviewService::class))->getMethod('applySideEffects');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('completeReviewWithoutDetaching', $source);
        self::assertStringContainsString('reopenReviewKeepingProjectLinks', $source);
        self::assertStringNotContainsString('archiveAndDetachProjectTasks', $source);
    }

    public function test_last_side_effect_meta_is_exposed_and_included_in_api_payload(): void
    {
        $ref = new ReflectionClass(ArticleReviewService::class);
        self::assertTrue($ref->hasMethod('lastSideEffectMeta'));

        $method = $ref->getMethod('toApiPayload');
        $source = $this->readMethodSource($method);
        self::assertStringContainsString('lastSideEffectMeta', $source);
        self::assertStringContainsString('content_project', $source);
    }

    public function test_repair_command_has_dry_run_and_apply_options_and_is_registered(): void
    {
        $ref = new ReflectionClass(RepairArchivedArticleActiveTasksCommand::class);
        $file = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString('--dry-run', $file);
        self::assertStringContainsString('--apply', $file);

        $provider = (string) file_get_contents((new ReflectionClass(SeoContentAiServiceProvider::class))->getFileName());
        self::assertStringContainsString('RepairArchivedArticleActiveTasksCommand::class', $provider);
    }

    private function readMethodSource(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
