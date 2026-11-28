<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\Models\User;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SeoAuditPublishMonthPreference;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class SeoAuditStickyToolbarAndPublishMonthPreferenceTest extends TestCase
{
    public function test_sticky_toolbar_places_formula_and_missing_in_separate_subrow_and_keeps_month_picker(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        // Sub-row is full-width (flex: 1 0 100%) and placed after the main header actions.
        self::assertStringContainsString('.cp-plan-draft-subrow', $styles);
        self::assertStringContainsString('flex: 1 0 100%;', $styles);

        $limitPos = strpos($blade, 'data-draft-monthly-limit="1"');
        $publishBtnPos = strpos($blade, 'data-content-planning-action="publish"');
        $subrowPos = strpos($blade, 'data-draft-sticky-subrow="1"');
        $formulaPos = strpos($blade, 'data-draft-monthly-limit-formula="1"');
        $missingPos = strpos($blade, 'data-draft-total-missing="1"');

        self::assertNotFalse($limitPos);
        self::assertNotFalse($publishBtnPos);
        self::assertNotFalse($subrowPos);
        self::assertNotFalse($formulaPos);
        self::assertNotFalse($missingPos);

        self::assertGreaterThan($limitPos, $publishBtnPos);
        self::assertGreaterThan($publishBtnPos, $subrowPos);
        self::assertGreaterThan($subrowPos, $formulaPos);
        self::assertGreaterThan($subrowPos, $missingPos);

        // Existing Month Picker preserved (not replaced by <select>).
        self::assertStringContainsString('class="cp-month-picker"', $blade);
        self::assertStringContainsString('cpMonthPicker()', $blade);
        self::assertStringContainsString('@entangle(\'draftSplitTargetMonth\').live', $blade);
    }

    public function test_publish_month_preference_is_per_user_empty_by_default_and_clears_invalid(): void
    {
        $userA = new class extends User {
            /** @var array<string, mixed> */
            public array $metaStore = [];
            public function getMeta(string $key, mixed $default = null): mixed
            {
                return $this->metaStore[$key] ?? $default;
            }
            public function setMeta(string $key, mixed $value): static
            {
                $this->metaStore[$key] = $value;

                return $this;
            }
        };
        $userB = clone $userA;

        // Default is empty (never falls back to current or Active Month).
        self::assertSame('', SeoAuditPublishMonthPreference::get($userA));
        self::assertSame('', SeoAuditPublishMonthPreference::get($userB));

        // User A selects 11/2026 -> persisted for A only, B remains empty.
        self::assertSame('2026-11', SeoAuditPublishMonthPreference::set('2026-11', $userA));
        self::assertSame('2026-11', SeoAuditPublishMonthPreference::get($userA));
        self::assertSame('', SeoAuditPublishMonthPreference::get($userB));

        // Invalid stored value is cleared and returns ''.
        $userA->setMeta(SeoAuditPublishMonthPreference::META_KEY, 'not-a-month');
        self::assertSame('', SeoAuditPublishMonthPreference::get($userA));
        self::assertSame('', $userA->getMeta(SeoAuditPublishMonthPreference::META_KEY));

        // Invalid set clears preference.
        self::assertSame('', SeoAuditPublishMonthPreference::set('2026-99', $userA));
    }

    public function test_planner_restores_and_preserves_publish_month_across_domain_filter_and_publish(): void
    {
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('SeoAuditPublishMonthPreference::get()', $pageSrc);
        self::assertStringContainsString('SeoAuditPublishMonthPreference::set($parsed)', $pageSrc);

        // Domain filter does not reset draftSplitTargetMonth, and confirmDraftSplit preserves it after Publish.
        $confirmPos = strpos($pageSrc, 'public function confirmDraftSplit(): void');
        $resolvePos = strpos($pageSrc, 'protected function resolvePublishEligibleTaskIds(');
        self::assertNotFalse($confirmPos);
        self::assertNotFalse($resolvePos);
        $confirmBody = substr($pageSrc, $confirmPos, $resolvePos - $confirmPos);
        self::assertStringNotContainsString("\$this->draftSplitTargetMonth = '';", $confirmBody);
    }
}
