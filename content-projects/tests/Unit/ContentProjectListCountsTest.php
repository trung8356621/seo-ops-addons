<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContentProjectListCountsTest extends TestCase
{
    public function test_generated_scope_does_not_treat_rewrite_source_article_as_generated(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(SeoProjectTask::class))->getMethod('scopeActiveGenerated'),
        );

        self::assertStringContainsString('STATUS_COMPLETED', $source);
        self::assertStringContainsString('STATUS_REVIEWING', $source);
        self::assertStringContainsString('TYPE_CREATE', $source);
        self::assertStringNotContainsString('TYPE_REWRITE', $source);
    }

    public function test_never_generated_scope_keeps_rewrite_pending_with_source_article(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(SeoProjectTask::class))->getMethod('scopeActiveNeverGenerated'),
        );

        self::assertStringContainsString('STATUS_PENDING', $source);
        self::assertStringContainsString('TYPE_CREATE', $source);
        self::assertStringContainsString("type', '!='", $source);
    }

    public function test_list_query_uses_generated_and_never_generated_scopes(): void
    {
        $source = $this->readMethodSource(
            new ReflectionMethod(SeoProjectResource::class, 'getEloquentQuery'),
        );

        self::assertStringContainsString('activeGenerated()', $source);
        self::assertStringContainsString('activeNeverGenerated()', $source);
        self::assertStringContainsString('currentArchive', $source);
    }

    public function test_list_columns_render_display_counts(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('displayGeneratedCount()', $source);
        self::assertStringContainsString('displayPendingCount()', $source);
        self::assertStringContainsString('displayFailedCount()', $source);
    }

    public function test_archived_list_counts_use_snapshot_not_reset_pending_tasks(): void
    {
        $generated = $this->readMethodSource(
            (new ReflectionClass(SeoProject::class))->getMethod('displayGeneratedCount'),
        );
        $pending = $this->readMethodSource(
            (new ReflectionClass(SeoProject::class))->getMethod('displayPendingCount'),
        );

        self::assertStringContainsString('isProjectArchived()', $generated);
        self::assertStringContainsString('completed_articles', $generated);
        self::assertStringContainsString('isProjectArchived()', $pending);
        self::assertStringContainsString('incomplete_articles', $pending);
        self::assertStringContainsString('active_pending_count', $pending);
    }

    public function test_live_counts_fall_back_to_with_count_attributes(): void
    {
        $generated = $this->readMethodSource(
            (new ReflectionClass(SeoProject::class))->getMethod('displayGeneratedCount'),
        );

        self::assertStringContainsString('active_generated_count', $generated);
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
