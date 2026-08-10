<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Support\ArticleReviewCutoverRules;
use PHPUnit\Framework\TestCase;

/**
 * Source assertions for Batch C is_reviewed cutover migration rules.
 */
final class ContentProjectIsReviewedCutoverMigrationTest extends TestCase
{
    public function test_rule_a_preserves_valid_approved(): void
    {
        $decision = ArticleReviewCutoverRules::decide(ArticleReviewStatus::Approved->value, false);
        self::assertSame(ArticleReviewCutoverRules::RULE_VALID_APPROVED, $decision['rule']);
        self::assertSame('preserve', $decision['action']);
    }

    public function test_rule_b_preserves_valid_archived_without_mirror(): void
    {
        $decision = ArticleReviewCutoverRules::decide(ArticleReviewStatus::Archived->value, false);
        self::assertSame(ArticleReviewCutoverRules::RULE_VALID_ARCHIVED, $decision['rule']);
        self::assertSame('preserve', $decision['action']);
    }

    public function test_rule_c_promotes_null_invalid_mirror_true_to_approved(): void
    {
        $decision = ArticleReviewCutoverRules::decide(null, true);
        self::assertSame(ArticleReviewCutoverRules::RULE_MIRROR_TO_APPROVED, $decision['rule']);
        self::assertSame('set_approved', $decision['action']);
        self::assertSame(ArticleReviewStatus::Approved->value, $decision['target_status']);
    }

    public function test_rule_d_sets_null_invalid_mirror_false_to_draft(): void
    {
        $decision = ArticleReviewCutoverRules::decide('not-a-status', false);
        self::assertSame(ArticleReviewCutoverRules::RULE_MIRROR_TO_DRAFT, $decision['rule']);
        self::assertSame('set_draft', $decision['action']);
        self::assertSame(ArticleReviewStatus::Draft->value, $decision['target_status']);
    }

    public function test_rule_e_promotes_draft_pending_conflict_to_approved(): void
    {
        $decision = ArticleReviewCutoverRules::decide(ArticleReviewStatus::PendingReview->value, true);
        self::assertSame(ArticleReviewCutoverRules::RULE_CONFLICT_TO_APPROVED, $decision['rule']);
        self::assertSame('set_approved', $decision['action']);
    }

    public function test_rule_f_preserves_archived_when_mirror_true(): void
    {
        $decision = ArticleReviewCutoverRules::decide(ArticleReviewStatus::Archived->value, true);
        self::assertSame(ArticleReviewCutoverRules::RULE_ARCHIVED_MIRROR_TRUE, $decision['rule']);
        self::assertSame('preserve', $decision['action']);
        self::assertSame(ArticleReviewStatus::Archived->value, $decision['target_status']);
    }

    public function test_migration_file_documents_drop_and_shared_rules(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_07_31_120000_cutover_drop_articles_is_reviewed.php');
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('ArticleReviewCutoverRules::decide', $src);
        self::assertStringContainsString("dropColumn('is_reviewed')", $src);
        self::assertStringContainsString('hasColumn(\'articles\', \'is_reviewed\')', $src);
        self::assertStringContainsString('Cutover is_reviewed stats', $src);
    }

    public function test_report_command_is_dry_run_only(): void
    {
        $path = ProjectRoot::addonsPath().'/commerce/src/Console/ReportIsReviewedCutoverCommand.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('seo:articles:report-is-reviewed-cutover', $src);
        self::assertStringContainsString('Dry-run report', $src);
        self::assertStringNotContainsString('->update(', $src);
    }
}
