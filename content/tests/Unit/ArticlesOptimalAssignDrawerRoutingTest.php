<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Pages\ArticlesOptimal;
use Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * SEO Audit Assign must open the shared drawer — never short-circuit on missing focus keyword.
 */
final class ArticlesOptimalAssignDrawerRoutingTest extends TestCase
{
    public function test_bulk_assign_opens_shared_drawer_without_keyword_short_circuit(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/articles-optimal.blade.php',
        );

        self::assertStringContainsString('AssignToContentProjectContract::OPEN_EVENT', $blade);
        self::assertStringContainsString('openAssignDrawer', $blade);
        self::assertStringContainsString('runAssignSelected', $blade);
        self::assertStringContainsString('detect_missing_focus_keyword: true', $blade);

        // Regression: missing keyword must NOT block drawer open.
        self::assertStringNotContainsString('notifyAssignBlockedMissingKeyword()', $blade);
        self::assertStringNotContainsString(
            'if (this.hasSelectedMissingKeyword()) {\n                this.$wire.notifyAssignBlockedMissingKeyword();',
            $blade,
        );

        // Bulk payload includes all selected ids (including those missing focus keyword).
        self::assertStringContainsString(
            ".filter((id) => id > 0);",
            $blade,
        );
        self::assertStringNotContainsString('&& !! this.articleFocusMap[id]', $blade);
    }

    public function test_drawer_allows_shared_keyword_override_for_multiple_missing(): void
    {
        $src = $this->source(AssignToContentProjectDrawer::class);
        self::assertStringContainsString('$this->needsFocusKeyword = $missing->count() >= 1', $src);
        self::assertStringNotContainsString('$missing->count() === 1', $src);
        self::assertStringNotContainsString("assign_missing_keyword_bulk", $src);
        self::assertStringContainsString('KeywordFocusAttach::syncMainKeyword', $src);
    }

    public function test_notify_method_remains_but_is_not_the_assign_gate(): void
    {
        $page = $this->source(ArticlesOptimal::class);
        self::assertStringContainsString('function notifyAssignBlockedMissingKeyword', $page);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/articles-optimal.blade.php',
        );
        self::assertStringNotContainsString('notifyAssignBlockedMissingKeyword()', $blade);
    }

    public function test_row_assign_button_restored_via_open_assign_drawer(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/articles-optimal.blade.php',
        );

        self::assertStringContainsString('openAssignDrawer({{ (int) $row[\'id\'] }})', $blade);
        self::assertStringContainsString('data-assign-content-project-trigger', $blade);
        self::assertStringContainsString('AssignToContentProjectContract::ICON', $blade);
        // Must not use the nested trigger component that previously crashed Alpine with $label.
        self::assertStringNotContainsString('x-content::assign-to-content-project-trigger', $blade);
    }

    public function test_no_parallel_assign_contract_introduced(): void
    {
        self::assertSame('assign-content-project:open', AssignToContentProjectContract::OPEN_EVENT);
        self::assertSame('article', AssignToContentProjectContract::MODE_ARTICLE);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/articles-optimal.blade.php',
        );
        self::assertStringContainsString("mode: 'article'", $blade);
        self::assertStringNotContainsString('assign-content-project-audit', $blade);
        self::assertStringNotContainsString('seo-audit-assign-modal', $blade);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }
}
