<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Exactly one dock chip may look selected. Health/error must not mimic is-active.
 */
final class ArticleEditorSidebarNavSingleActiveChipTest extends TestCase
{
    private function js(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    private function css(): string
    {
        return ProjectRoot::addonsPath().'/content/resources/css/article-editor.css';
    }

    public function test_selected_state_is_independent_of_health_error(): void
    {
        $state = (string) file_get_contents($this->js('editor/host/editorSidebarNavChipState.js'));
        self::assertStringContainsString('export function isNavChipActive', $state);
        self::assertStringContainsString('export function resolveNavChipHealthFlags', $state);
        self::assertStringContainsString('export function buildNavChipClassName', $state);
        self::assertStringContainsString('export function collectActiveNavChipIds', $state);
        self::assertStringContainsString('export function resolveImagesActiveSeoErrorScenario', $state);
        self::assertStringContainsString('String(activePanelId || \'\') === String(chipId || \'\')', $state);
        self::assertStringContainsString('hasError', $state);
        // Active must never be derived from error/issue counts.
        self::assertStringNotContainsString('isActive = hasError', $state);
        self::assertStringNotContainsString('isActive = health', $state);
        self::assertStringNotContainsString('error_count > 0 ? true', $state);

        $nav = (string) file_get_contents($this->js('editor/host/EditorSidebarNavigation.jsx'));
        self::assertStringContainsString('isNavChipActive(activePanel, chip.id)', $nav);
        self::assertStringContainsString('resolveNavChipHealthFlags', $nav);
        self::assertStringContainsString('buildNavChipClassName', $nav);
        self::assertStringContainsString("data-active={isActive ? '1' : '0'}", $nav);
        self::assertStringContainsString("data-has-error={hasError ? '1' : '0'}", $nav);
        self::assertStringContainsString('never from health/error', $nav);
    }

    public function test_status_css_does_not_paint_chip_chrome_like_active(): void
    {
        $css = (string) file_get_contents($this->css());
        self::assertStringContainsString('.seo-assistant-dock__tab.is-active', $css);
        self::assertStringContainsString(
            'Health/error must NOT paint chip chrome like .is-active',
            $css,
        );
        // Error/warning style indicators only — no filled chip background on status classes.
        self::assertStringContainsString(
            '.seo-assistant-dock__tab.is-status-error .seo-assistant-dock__tab-dot',
            $css,
        );
        self::assertStringNotContainsString(
            ".seo-assistant-dock__tab.is-status-error {\n    color: #b91c1c;\n    border-color: #fecaca;\n    background: #fff7f7;\n}",
            $css,
        );
        self::assertStringNotContainsString(
            ".seo-assistant-dock__tab.is-status-warning {\n    color: #b45309;\n    border-color: #fde68a;\n    background: #fffbeb;\n}",
            $css,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.seo-assistant-dock__tab\.is-status-error\s*\{[^}]*background\s*:/',
            $css,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.seo-assistant-dock__tab\.is-status-warning\s*\{[^}]*background\s*:/',
            $css,
        );
    }

    public function test_images_active_seo_error_scenario_has_exactly_one_active_chip(): void
    {
        $helper = $this->js('editor/host/editorSidebarNavChipState.js');
        $helperUri = 'file:///'.str_replace('\\', '/', $helper);
        $script = <<<JS
import {
  resolveImagesActiveSeoErrorScenario,
  collectActiveNavChipIds,
} from {$this->jsString($helperUri)};

const scenario = resolveImagesActiveSeoErrorScenario({
  activePanelId: 'images',
  seoErrorCount: 4,
});

if (scenario.images.isActive !== true) {
  console.error('FAIL: images.isActive expected true');
  process.exit(1);
}
if (scenario.seo.isActive !== false) {
  console.error('FAIL: seo.isActive expected false');
  process.exit(1);
}
if (scenario.seo.hasError !== true) {
  console.error('FAIL: seo.hasError expected true');
  process.exit(1);
}
if (!scenario.images.className.includes('is-active')) {
  console.error('FAIL: images className missing is-active');
  process.exit(1);
}
if (scenario.seo.className.includes('is-active')) {
  console.error('FAIL: seo className must not include is-active');
  process.exit(1);
}
if (!scenario.seo.className.includes('is-status-error')) {
  console.error('FAIL: seo className missing is-status-error');
  process.exit(1);
}
if (scenario.activeChipIds.length !== 1 || scenario.activeChipIds[0] !== 'images') {
  console.error('FAIL: activeChipIds.length expected 1 (images)', scenario.activeChipIds);
  process.exit(1);
}

const again = collectActiveNavChipIds(['seo', 'images', 'reviews'], 'images');
if (again.length !== 1) {
  console.error('FAIL: collectActiveNavChipIds length', again);
  process.exit(1);
}

console.log('PASS: images active only; seo hasError without is-active');
JS;

        $tmp = tempnam(sys_get_temp_dir(), 'navchip');
        self::assertNotFalse($tmp);
        $mjs = $tmp.'.mjs';
        file_put_contents($mjs, $script);

        $cmd = 'node '.escapeshellarg($mjs);
        $output = [];
        $exit = 1;
        exec($cmd.' 2>&1', $output, $exit);
        @unlink($tmp);
        @unlink($mjs);

        self::assertSame(0, $exit, implode("\n", $output));
        self::assertStringContainsString('PASS:', implode("\n", $output));
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
