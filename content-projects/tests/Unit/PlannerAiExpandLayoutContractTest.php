<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

/**
 * Project Planner: balanced 50/50 cards; AI focus = full-width AI + bottom 30/70 actions.
 */
final class PlannerAiExpandLayoutContractTest extends TestCase
{
    public function test_planner_layout_initializes_balanced(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringContainsString("plannerLayout: 'balanced'", $draft);
        self::assertStringContainsString("createTab: 'ideas'", $draft);
        self::assertStringContainsString("x-data=\"{ filtersOpen: true, createTab: 'ideas', plannerLayout: 'balanced' }\"", $draft);
        self::assertStringNotContainsString('localStorage', $draft);
        self::assertStringNotContainsString('sessionStorage', $draft);
        self::assertStringNotContainsString('public string $plannerLayout', $draft);
        self::assertStringNotContainsString('ai-expanded', $draft);
        self::assertStringNotContainsString('cp-plan-improve-float', $draft);
    }

    public function test_desktop_defaults_to_equal_columns_and_ai_focus_hides_improve(): void
    {
        $css = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\)\s*\{\s*\.cp-plan-grid\s*\{\s*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/',
            $css,
        );
        self::assertStringContainsString('.cp-plan-grid.is-ai-focused', $css);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $css);
        self::assertStringContainsString(
            '.cp-plan-grid.is-ai-focused > [data-planner-card="improve"]',
            $css,
        );
        self::assertStringContainsString('display: none', $css);
        self::assertStringNotContainsString('minmax(15rem, 3fr) minmax(0, 7fr)', $css);
        self::assertStringNotContainsString('cp-plan-improve-float', $css);
        self::assertStringNotContainsString('is-ai-expanded', $css);
    }

    public function test_gen_ai_tab_sets_ai_focused_and_ideas_restores_balanced(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringContainsString(
            "@click=\"createTab = 'ai'; plannerLayout = 'ai-focused'\"",
            $draft,
        );
        self::assertStringContainsString(
            "@click=\"createTab = 'ideas'; plannerLayout = 'balanced'\"",
            $draft,
        );
        self::assertStringContainsString(":class=\"{ 'is-ai-focused': plannerLayout === 'ai-focused' }\"", $draft);
    }

    public function test_no_top_thirty_seventy_switcher_on_improve_header(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringNotContainsString('data-planner-improve-return', $draft);
        self::assertStringNotContainsString('cp-plan-card__head--return', $draft);
        self::assertStringNotContainsString(
            "@click=\"if (plannerLayout === 'ai-focused') plannerLayout = 'balanced'\"",
            $draft,
        );
        self::assertStringContainsString('data-planner-card="improve"', $draft);
        self::assertStringNotContainsString('cp-plan-improve-float', $draft);
    }

    public function test_ai_focus_bottom_actions_are_thirty_seventy(): void
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
        self::assertStringContainsString("x-show=\"plannerLayout === 'ai-focused'\"", $card);
        self::assertStringContainsString("x-show=\"plannerLayout !== 'ai-focused'\"", $card);

        self::assertStringContainsString('.cp-plan-sticky-cta__split', $css);
        self::assertStringContainsString('grid-template-columns: 3fr 7fr', $css);
        self::assertStringContainsString('gap: 0.5rem', $css);
    }

    public function test_balanced_keeps_single_generate_and_improve_card_cta(): void
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
        self::assertStringNotContainsString('position: absolute', substr(
            $css,
            (int) strpos($css, '.cp-plan-grid.is-ai-focused'),
            500,
        ));
    }
}
