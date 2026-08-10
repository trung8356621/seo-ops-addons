<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncEligibility;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: archived Content Project â‰  archived Article.
 * Articles under archived projects behave as standalone for editor/save/WP sync.
 */
final class ArticleEditorArchivedContentProjectStandaloneTest extends TestCase
{
    public function test_assert_article_editable_does_not_deny_archived_content_project(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ArticleEditorSessionService::class, 'assertArticleEditable'),
        );

        self::assertStringContainsString('NOT_EDITABLE', $source);
        self::assertStringContainsString('trashed', $source);
        self::assertStringNotContainsString('CONTENT_PROJECT_ARCHIVED', $source);
        self::assertStringNotContainsString('assignedTaskForArticle', $source);
        self::assertStringNotContainsString('archived_at', $source);
        self::assertStringNotContainsString('isArchive()', $source);
        self::assertStringContainsString('historical/reporting only', $source);
    }

    public function test_archive_revoke_reason_code_still_exists_for_mid_archive_ux(): void
    {
        self::assertSame('content_project_archived', ArticleEditorSessionErrorCode::CONTENT_PROJECT_ARCHIVED);

        $archive = (string) file_get_contents(
            (new ReflectionClass(ArchiveContentProjectService::class))->getFileName(),
        );
        self::assertStringContainsString('revokeActiveSessionsForArticles', $archive);
        self::assertStringContainsString('content_project_archived', $archive);

        $shell = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString("code === 'content_project_archived'", $shell);
        self::assertStringContainsString('editor_archived_body', $shell);
    }

    public function test_membership_treats_archived_association_as_non_ownership(): void
    {
        $membership = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArticleMembership::class))->getFileName(),
        );

        self::assertStringContainsString('Archived project association is historical/reporting only', $membership);
        self::assertStringContainsString('return $this->activeTaskForArticle($article);', $membership);
        self::assertStringContainsString('return $this->belongsToActiveContentProject($article);', $membership);
        self::assertStringContainsString('function historicalAssignedTaskForArticle', $membership);
        self::assertStringContainsString("whereNull('archived_at')", $membership);
    }

    public function test_article_resource_in_content_project_ignores_archived_project(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ArticleResource::class, 'articleAssignedContentProjectId'),
        );

        self::assertStringContainsString('->active()', $source);
        self::assertStringContainsString("whereNull('archived_at')", $source);
        self::assertStringContainsString('historical/reporting', $source);
    }

    public function test_wp_sync_gates_use_active_membership_only(): void
    {
        $eligibility = (string) file_get_contents(
            (new ReflectionClass(ArticleWordPressSyncEligibility::class))->getFileName(),
        );
        self::assertStringContainsString('assignedTaskForArticle', $eligibility);

        $manual = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );
        self::assertStringContainsString('belongsToContentProject', $manual);

        $membership = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArticleMembership::class))->getFileName(),
        );
        self::assertStringContainsString(
            'return $this->belongsToActiveContentProject($article);',
            $membership,
        );
    }

    public function test_archive_preview_keeps_article_id_historical_association(): void
    {
        $archive = (string) file_get_contents(
            (new ReflectionClass(ArchiveContentProjectService::class))->getFileName(),
        );
        self::assertStringContainsString('SeoProjectArchiveItem', $archive);
        self::assertStringContainsString("'article_id' => \$articleId", $archive);
        self::assertStringContainsString("'article_id' => null", $archive);
        self::assertStringContainsString('resetProjectTasksForFreshFlow', $archive);

        $presenter = (string) file_get_contents(
            (new ReflectionClass(ArchivePreviewArticlePresenter::class))->getFileName(),
        );
        self::assertStringContainsString('article_id', $presenter);
        self::assertStringContainsString('can_edit', $presenter);
        self::assertStringContainsString('edit_url', $presenter);
    }

    public function test_archive_does_not_clear_project_archived_at_on_article_edit_paths(): void
    {
        $session = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorSessionService::class))->getFileName(),
        );
        self::assertStringNotContainsString("archived_at' => null", $session);
        self::assertStringNotContainsString('content_project_restored', $session);
        self::assertStringNotContainsString('workspaceDestroyer', $session);
        self::assertStringNotContainsString('resetProjectTasksForFreshFlow', $session);
    }

    private function methodSource(ReflectionMethod $method): string
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
