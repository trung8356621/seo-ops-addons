<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

/**
 * Project Planner SEO Audit: balanced 2-column Improve + Site Planning (Ideas moved to Idea Manager).
 * Generate AI card layout contracts remain on the standalone new-content card component.
 */
final class PlannerAiExpandLayoutContractTest extends TestCase
{
    public function test_planner_layout_is_two_column_without_ai_focus_toggle(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringContainsString('x-data="{ filtersOpen: true }"', $draft);
        self::assertStringContainsString('data-planner-layout="two-column"', $draft);
        self::assertStringNotContainsString('createTab', $draft);
        self::assertStringNotContainsString('plannerLayout', $draft);
        self::assertStringNotContainsString('is-ai-focused', $draft);
        self::assertStringNotContainsString('localStorage', $draft);
        self::assertStringNotContainsString('sessionStorage', $draft);
        self::assertStringNotContainsString('cp-plan-improve-float', $draft);
    }

    public function test_desktop_defaults_to_two_equal_columns(): void
    {
        $css = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\)\s*\{\s*\.cp-plan-grid\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(0,\s*1fr\)/',
            $css,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.cp-plan-grid\s*\{\s*grid-template-columns:\s*repeat\(3,/',
            $css,
        );
        self::assertStringNotContainsString('cp-plan-improve-float', $css);
        self::assertStringNotContainsString('is-ai-expanded', $css);
    }

    public function test_no_top_thirty_seventy_switcher_on_improve_header(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringNotContainsString('data-planner-improve-return', $draft);
        self::assertStringNotContainsString('cp-plan-card__head--return', $draft);
        self::assertStringContainsString('data-planner-card="improve"', $draft);
        self::assertStringContainsString('data-planner-action="idea-manager"', $draft);
        self::assertStringNotContainsString('cp-plan-improve-float', $draft);
    }

    public function test_ai_focus_bottom_actions_remain_on_new_content_card(): void
    {
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');
        $css = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('data-planner-ai-focus-actions="1"', $card);
        self::assertStringContainsString('cp-plan-sticky-cta__split', $card);
        self::assertStringContainsString('data-planner-return-balanced="1"', $card);
        self::assertStringContainsString("plannerLayout = 'balanced'", $card);
        self::assertStringContainsString('planner_improve_heading', $card);
        self::assertStringContainsString('idea_candidate_tab_ai', $card);
        self::assertStringContainsString('generateNewContentSuggestions', $card);
        self::assertStringContainsString('data-planner-generate="new-content"', $card);
        self::assertStringContainsString('data-planner-generate-balanced="1"', $card);

        self::assertStringContainsString('.cp-plan-sticky-cta__split', $css);
        self::assertStringContainsString('grid-template-columns: 3fr 7fr', $css);
        self::assertStringContainsString('gap: 0.5rem', $css);
    }

    public function test_balanced_keeps_improve_card_cta(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');

        self::assertStringContainsString('cp-plan-sticky-cta--improve', $draft);
        self::assertStringContainsString('wire:click="fillSuggestions"', $draft);
        self::assertStringContainsString('planner_fill_from_seo_audit', $draft);
        self::assertStringContainsString('planner_generate_with_ai', $card);
        self::assertStringContainsString('data-planner-generate-balanced="1"', $card);
    }

    public function test_no_layout_persistence_and_no_floating_improve(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $page = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $css = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringNotContainsString('draft_layout', $draft);
        self::assertStringNotContainsString('plannerLayout', $page);
        self::assertStringNotContainsString("Url(as: 'planner_layout')", $draft);
        self::assertStringNotContainsString('cp-plan-improve-float', $css);
    }
}
