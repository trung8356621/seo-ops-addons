<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class DraftPlanningReviewContractTest extends TestCase
{
    public function test_migration_adds_planning_reviewed_columns(): void
    {
        $migration = dirname(__DIR__, 2).'/database/migrations/2026_08_25_100000_add_planning_reviewed_to_seo_project_tasks.php';
        self::assertFileExists($migration);
        $src = (string) file_get_contents($migration);
        self::assertStringContainsString('planning_reviewed_at', $src);
        self::assertStringContainsString('planning_reviewed_by', $src);
    }

    public function test_model_casts_planning_reviewed_fields(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTask::class))->getFileName(),
        );

        self::assertStringContainsString("'planning_reviewed_at' => 'datetime'", $src);
        self::assertStringContainsString("'planning_reviewed_by' => 'integer'", $src);
    }

    public function test_page_toggle_does_not_touch_article_or_cm_review(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('function setPlanningReviewed', $page);
        self::assertStringContainsString('planning_reviewed_at', $page);
        self::assertStringContainsString('planning_reviewed_by', $page);
        self::assertStringNotContainsString('content_manager_reviewed_at', $page);
        self::assertStringNotContainsString('review_status', $page);
    }

    public function test_read_model_exposes_review_counts_and_filters(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'unreviewed'", $src);
        self::assertStringContainsString("'reviewed'", $src);
        self::assertStringContainsString('planning_reviewed', $src);
        self::assertStringContainsString('filters[\'review\']', $src);
        self::assertStringContainsString('filters[\'type\']', $src);
    }

    public function test_ui_has_review_tabs_and_optimistic_toggle(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('data-draft-review-tabs', $items);
        self::assertStringContainsString('setDraftReviewFilter', $items);
        self::assertStringContainsString('cpPlanDraftItems', $items);
        self::assertStringContainsString('toggleReview(row)', $items);
        self::assertStringContainsString('setPlanningReviewed', $items);
        self::assertStringContainsString('planning_reviewed', $items);
        self::assertStringContainsString('data-draft-type-filter', $items);
        self::assertStringContainsString("tab === 'unreviewed'", $items);
        self::assertStringNotContainsString('data-inline-edit=\"', $items);
    }

    public function test_seo_score_segment_uses_local_alpine_selection(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringContainsString('data-seo-score-segment', $draft);
        self::assertStringContainsString('x-data="{ selected:', $draft);
        self::assertStringContainsString('cp-plan-segment__btn is-active', $draft);
        self::assertStringContainsString("\$wire.setSuggestionScorePreset", $draft);
        self::assertDoesNotMatchRegularExpression(
            '/wire:click="setSuggestionScorePreset[^"]*"[^>]*bg-emerald-600/',
            $draft,
        );
    }
}
