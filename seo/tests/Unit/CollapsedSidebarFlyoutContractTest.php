<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class CollapsedSidebarFlyoutContractTest extends TestCase
{
    public function test_expanded_parent_still_renders_inline_children(): void
    {
        $item = $this->itemBlade();

        self::assertStringContainsString('fi-sidebar-item-collapse-button', $item);
        self::assertStringContainsString('openChildren', $item);
        self::assertStringContainsString('fi-sidebar-sub-group-items', $item);
        self::assertStringContainsString('$childItem->getLabel()', $item);
        self::assertStringContainsString('$childItem->getUrl()', $item);
    }

    public function test_collapsed_rail_hides_child_items_and_does_not_render_child_icons(): void
    {
        $item = $this->itemBlade();

        self::assertStringContainsString('filled($icon) && ! $subGrouped', $item);
        self::assertStringNotContainsString('((! $subGrouped) || $sidebarCollapsible)', $item);
        self::assertStringNotContainsString("'! \$store.sidebar.isOpen'", $item);
        self::assertStringContainsString('openChildren && $store.sidebar.isOpen', $item);
        self::assertStringContainsString('data-fi-sidebar-inline-children', $item);
        $css = $this->collapsedCss();
        self::assertStringContainsString('.fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-sub-group-items', $css);
        self::assertStringContainsString('@media (min-width: 1024px)', $css);
        self::assertStringContainsString(':sidebar-collapsible="false"', $item);
    }

    public function test_collapsed_parent_with_children_opens_text_flyout(): void
    {
        $item = $this->itemBlade();
        $flyout = $this->flyoutBlade();

        self::assertStringContainsString('$childLeafCount >= 2', $item);
        self::assertStringContainsString('shouldFlyout', $item);
        self::assertStringContainsString('filament-panels::sidebar.collapsed-flyout', $item);
        self::assertStringContainsString('data-fi-sidebar-collapsed-flyout', $flyout);
        self::assertStringContainsString('teleport', $flyout);
        self::assertStringContainsString("x-show=\"! \$store.sidebar.isOpen\"", $flyout);
        self::assertStringContainsString('$navItem->getLabel()', $flyout);
        self::assertStringContainsString('max-width: 18rem', $flyout);
        self::assertStringContainsString('getChildItems()', $flyout);
        self::assertStringNotContainsString('SEO Audit', $flyout);
        self::assertStringNotContainsString('Content Editor Hub', $flyout);
        self::assertStringNotContainsString('PG Canary', $flyout);
        self::assertStringNotContainsString('window.location.reload', $flyout);
        self::assertStringNotContainsString('livewire:navigated', $flyout);
        self::assertStringNotContainsString('window.location.reload', $item);
        self::assertStringNotContainsString('livewire:navigated', $item);
    }

    public function test_flyout_closes_on_escape_outside_click_child_nav_and_sidebar_expand(): void
    {
        $flyout = $this->flyoutBlade();

        self::assertStringContainsString('x-on:keydown.escape.window="close()"', $flyout);
        self::assertStringContainsString('x-on:click.window=', $flyout);
        self::assertStringContainsString("x-on:click=\"close()\"", $flyout);
        self::assertStringContainsString('if ($store.sidebar.isOpen) close()', $flyout);
        self::assertStringContainsString('generate_href_html', $flyout);
        self::assertStringContainsString('type="button"', $flyout);
        self::assertStringContainsString('aria-haspopup="menu"', $flyout);
        self::assertStringContainsString('x-tooltip.html="tooltip"', $flyout);
    }

    public function test_active_child_marks_parent_and_flyout_child(): void
    {
        $item = $this->itemBlade();
        $flyout = $this->flyoutBlade();

        self::assertStringContainsString('$active || $activeChildItems', $item);
        self::assertStringContainsString('$parentIsActive', $item);
        self::assertStringContainsString("'fi-active fi-sidebar-item-active' => \$parentIsActive", $item);
        self::assertStringContainsString('$navItem->isActive() || $navItem->isChildItemsActive()', $flyout);
        self::assertStringContainsString('title-active', $item);
        self::assertStringContainsString('text-primary-600', $flyout);
    }

    public function test_single_child_root_navigates_directly(): void
    {
        $item = $this->itemBlade();

        self::assertStringContainsString('$childLeafCount === 1', $item);
        self::assertStringContainsString('data-fi-sidebar-single-child-root', $item);
        self::assertStringContainsString('$firstNavUrl($childItems)', $item);
    }

    public function test_labeled_groups_use_parent_icon_flyout_when_collapsed(): void
    {
        $group = $this->groupBlade();

        self::assertStringContainsString('filled($label) && $sidebarCollapsible && $destinationCount >= 2', $group);
        self::assertStringNotContainsString('filled($label) && filled($icon) && $sidebarCollapsible', $group);
        self::assertStringContainsString('fi-sidebar-group-has-collapsed-flyout', $group);
        self::assertStringContainsString('filament-panels::sidebar.collapsed-flyout', $group);
        self::assertStringContainsString('data-fi-sidebar-single-child-root', $group);
        self::assertStringContainsString('$destinationCount === 1', $group);
        self::assertStringContainsString('! @js($hasDropdown || filled($singleDestinationUrl))', $group);
        self::assertStringContainsString(':sidebar-collapsible="$sidebarCollapsible && ! $hasDropdown"', $group);
        self::assertStringNotContainsString('SEO Improvement', $group);
        self::assertStringNotContainsString('window.location.reload', $group);
        self::assertStringNotContainsString('livewire:navigated', $group);
    }

    public function test_mobile_keeps_inline_submenu_because_flyout_is_desktop_collapsed_only(): void
    {
        $flyout = $this->flyoutBlade();
        $item = $this->itemBlade();

        $css = $this->collapsedCss();

        self::assertStringContainsString('@media (min-width: 1024px)', $css);
        self::assertStringContainsString("x-show=\"! \$store.sidebar.isOpen\"", $flyout);
        self::assertStringContainsString('x-show="$store.sidebar.isOpen"', $item);
        self::assertStringContainsString('openChildren && $store.sidebar.isOpen', $item);
    }

    private function itemBlade(): string
    {
        return $this->read('item.blade.php');
    }

    private function groupBlade(): string
    {
        return $this->read('group.blade.php');
    }

    private function flyoutBlade(): string
    {
        return $this->read('collapsed-flyout.blade.php');
    }

    private function read(string $file): string
    {
        $path = ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/overrides/filament-panels/components/sidebar/'
            .$file;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function collapsedCss(): string
    {
        $path = ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/hooks/seo-sidebar-collapsed.blade.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
