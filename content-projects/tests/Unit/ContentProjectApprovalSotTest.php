<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Article\ApproveArticleAction;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ApproveProjectItemsHandler;
use Omnichannel\Addons\Content\Support\ArticleReviewCutoverRules;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Batch C hard cutover â€” review_status sole SoT, is_reviewed removed.
 */
final class ContentProjectApprovalSotTest extends TestCase
{
    public function test_cutover_rules_map_mirror_true_to_approved_not_archived(): void
    {
        $decision = ArticleReviewCutoverRules::decide(null, true);
        self::assertSame(ArticleReviewCutoverRules::RULE_MIRROR_TO_APPROVED, $decision['rule']);
        self::assertSame('set_approved', $decision['action']);
        self::assertSame(ArticleReviewStatus::Approved->value, $decision['target_status']);
    }

    public function test_cutover_rules_preserve_archived_when_mirror_true(): void
    {
        $decision = ArticleReviewCutoverRules::decide('archived', true);
        self::assertSame(ArticleReviewCutoverRules::RULE_ARCHIVED_MIRROR_TRUE, $decision['rule']);
        self::assertSame('preserve', $decision['action']);
        self::assertSame(ArticleReviewStatus::Archived->value, $decision['target_status']);
    }

    public function test_article_review_service_has_no_is_reviewed_mirror(): void
    {
        $src = $this->source(ArticleReviewService::class);
        self::assertStringContainsString('function ensureApproved', $src);
        self::assertStringContainsString('function isCanonicallyApproved', $src);
        self::assertStringNotContainsString('is_reviewed', $src);
        self::assertStringNotContainsString('applyCompatibilityMirror', $src);
        self::assertStringNotContainsString('markArticleReviewed($article)', $src);
    }

    public function test_project_approve_handler_is_all_or_nothing(): void
    {
        $src = $this->source(ApproveProjectItemsHandler::class);
        self::assertStringContainsString('articleReview->ensureApproved', $src);
        self::assertStringContainsString('ContentProjectItemsApproved', $src);
        self::assertStringNotContainsString("'is_reviewed'", $src);
        self::assertStringContainsString('Batch approve rejected', $src);
        self::assertStringNotContainsString('canApproveArticleReview', $src);
        // Import + emit â€” assert single dispatch after commit only.
        self::assertSame(1, substr_count($src, 'new ContentProjectItemsApproved'));
        self::assertSame(1, substr_count($src, 'dispatchAfterCommit(new ContentProjectItemsApproved'));
    }

    public function test_automation_approve_still_uses_article_review_service(): void
    {
        $src = $this->source(ApproveArticleAction::class);
        self::assertStringContainsString('reviewService->performAction', $src);
        self::assertStringNotContainsString('is_reviewed', $src);
    }

    public function test_lifecycle_approved_only_review_status_approved(): void
    {
        $src = $this->source(ContentProjectLifecycle::class);
        self::assertStringContainsString('ContentProjectItemStateResolver', $src);
        self::assertStringContainsString('function resolveState', $src);
        self::assertStringNotContainsString('is_reviewed', $src);
    }

    public function test_bulk_list_and_edit_article_do_not_write_is_reviewed(): void
    {
        $resource = $this->source(ArticleResource::class);
        self::assertStringContainsString('ensureApproved', $resource);
        self::assertStringNotContainsString('markArticleReviewed', $resource);
        self::assertStringNotContainsString('markArticleUnreviewed', $resource);
        self::assertStringNotContainsString("'is_reviewed'", $resource);

        $edit = $this->source(EditArticle::class);
        self::assertStringNotContainsString('syncReviewedStatusFromExistingReviews', $edit);
        self::assertStringContainsString('isCanonicallyApproved', $edit);
    }

    public function test_migration_drops_is_reviewed_after_cutover_rules(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_07_31_120000_cutover_drop_articles_is_reviewed.php');
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('ArticleReviewCutoverRules', $src);
        self::assertStringContainsString('dropColumn(\'is_reviewed\')', $src);
        self::assertStringContainsString('set_approved', $src);
    }

    public function test_ensure_approved_rejects_archived_as_not_approved(): void
    {
        $src = $this->source(ArticleReviewService::class);
        self::assertStringContainsString('ArticleReviewStatus::Archived', $src);
        self::assertStringContainsString('reopen before approve', $src);
        self::assertStringContainsString('already_approved', $src);
    }

    public function test_ensure_approved_skips_business_hook_automation_emits_once(): void
    {
        $svc = $this->source(ArticleReviewService::class);
        self::assertStringContainsString('Does NOT emit BusinessHook', $svc);

        $action = $this->source(ApproveArticleAction::class);
        self::assertSame(1, substr_count($action, "ActionSupport::articleEvent('article.approved'"));
    }

    public function test_publish_eligibility_uses_lifecycle_approved_from_review_status(): void
    {
        $lifecycle = $this->source(ContentProjectLifecycle::class);
        self::assertStringContainsString('resolvePhase', $lifecycle);
        self::assertStringContainsString('ContentProjectItemStateResolver', $lifecycle);
        self::assertStringNotContainsString('is_reviewed', $lifecycle);

        $queue = $this->source(
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService::class,
        );
        // Batch D+: schedule eligibility = ActionGuard (resolver â†’ review_status), not raw resolvePhase.
        self::assertStringContainsString('ContentProjectItemActionGuard', $queue);
        self::assertStringContainsString('actionGuard->assertCan', $queue);
        self::assertStringContainsString('ContentProjectItemAction::Schedule', $queue);
        self::assertStringNotContainsString('is_reviewed', $queue);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return $contents;
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $file = (string) file_get_contents((string) $method->getFileName());
        $lines = explode("\n", $file);
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return implode("\n", array_slice($lines, $start, $end - $start));
    }
}
