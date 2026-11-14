<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectOpsReviewFeedbackContractTest extends TestCase
{
    public function test_ops_read_model_exposes_latest_human_review_fields(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('SeoArticleReview', $src);
        self::assertStringContainsString('latestHumanReviewsByArticleIds', $src);
        self::assertStringContainsString('latest_review_note', $src);
        self::assertStringContainsString('latest_reviewer_name', $src);
        self::assertStringContainsString('latest_review_at', $src);
        self::assertStringContainsString('ensureApproved:', $src);
    }

    public function test_item_meta_renders_compact_review_note_ux(): void
    {
        $meta = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/content-project-item-meta.blade.php',
        );

        self::assertStringContainsString('latest_review_note', $meta);
        self::assertStringContainsString('hide_review_reviewer_name', $meta);
        self::assertStringContainsString('noteNeedsExpand', $meta);
        self::assertStringContainsString('ops_review_note_expand', $meta);
        self::assertStringContainsString('<details', $meta);
        // CP-03: no duplicate keyword line, no unpublished-changes chip.
        self::assertStringNotContainsString('showKeyword', $meta);
        self::assertStringNotContainsString('has_unpublished_changes', $meta);
        self::assertStringNotContainsString('ops_unpublished_changes', $meta);
    }

    public function test_in_review_reporting_badge_is_edited_not_viewed(): void
    {
        $badge = ContentProjectStatusBadgePresenter::reporting('in_review');
        self::assertIsArray($badge);
        self::assertSame('in_review', $badge['key'] ?? null);
        self::assertNotSame('Content Manager đã xem', $badge['label'] ?? null);

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectStatusBadgePresenter::class))->getFileName(),
        );
        self::assertStringContainsString('badge_reviewed_by_content_manager', $src);
        self::assertStringContainsString("'Edited'", $src);
    }
}
