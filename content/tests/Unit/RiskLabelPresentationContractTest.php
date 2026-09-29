<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Part A — Risk label presentation changes.
 * Verifies user-facing labels changed to Warning / Cảnh báo while internal keys remain 'review'.
 */
final class RiskLabelPresentationContractTest extends TestCase
{
    // ── EN labels ──

    public function test_en_risk_filter_label_is_warning(): void
    {
        $lang = $this->loadLang('en');

        self::assertSame('Warning', $lang['keyword']['risk_filter_review'] ?? null);
    }

    public function test_en_risk_level_label_is_warning(): void
    {
        $lang = $this->loadLang('en');

        self::assertSame('Warning', $lang['keyword']['risk_level_review'] ?? null);
    }

    public function test_en_safe_and_low_labels_unchanged(): void
    {
        $lang = $this->loadLang('en');

        self::assertSame('Safe', $lang['keyword']['risk_level_safe'] ?? null);
        self::assertSame('Low', $lang['keyword']['risk_level_low'] ?? null);
    }

    // ── VI labels ──

    public function test_vi_risk_filter_label_is_canh_bao(): void
    {
        $lang = $this->loadLang('vi');

        self::assertSame('Cảnh báo', $lang['keyword']['risk_filter_review'] ?? null);
    }

    public function test_vi_risk_level_label_is_canh_bao(): void
    {
        $lang = $this->loadLang('vi');

        self::assertSame('Cảnh báo', $lang['keyword']['risk_level_review'] ?? null);
    }

    // ── Internal keys preserved ──

    public function test_internal_keys_remain_review(): void
    {
        $langEn = $this->loadLang('en');

        // Keys must still use 'review' — never renamed to 'warning'
        self::assertArrayHasKey('risk_filter_review', $langEn['keyword'] ?? []);
        self::assertArrayHasKey('risk_level_review', $langEn['keyword'] ?? []);
        self::assertArrayNotHasKey('risk_filter_warning', $langEn['keyword'] ?? []);
        self::assertArrayNotHasKey('risk_level_warning', $langEn['keyword'] ?? []);
    }

    // ── CSS ──

    public function test_review_badge_css_is_red_not_amber(): void
    {
        $css = $this->loadKeywordWorkspaceCss();

        // Light mode: red background
        self::assertMatchesRegularExpression(
            '/\.keyword-risk-badge--review\s*\{[^}]*rgb\(254\s+226\s+226\)/',
            $css,
            'Light-mode review badge must use red background',
        );

        // Light mode: red text
        self::assertMatchesRegularExpression(
            '/\.keyword-risk-badge--review\s*\{[^}]*rgb\(153\s+27\s+27\)/',
            $css,
            'Light-mode review badge must use dark red text',
        );

        // Must NOT contain amber colors for review badge
        self::assertDoesNotMatchRegularExpression(
            '/\.keyword-risk-badge--review\s*\{[^}]*rgb\(254\s+243\s+199\)/',
            $css,
            'Review badge must not use amber background',
        );

        self::assertDoesNotMatchRegularExpression(
            '/\.keyword-risk-badge--review\s*\{[^}]*rgb\(146\s+64\s+14\)/',
            $css,
            'Review badge must not use amber text color',
        );
    }

    public function test_review_badge_dark_mode_css_is_red(): void
    {
        $css = $this->loadKeywordWorkspaceCss();

        // Dark mode: red hues
        self::assertMatchesRegularExpression(
            '/\.dark\s+\.keyword-risk-badge--review\s*\{[^}]*rgb\(127\s+29\s+29/',
            $css,
            'Dark-mode review badge must use red background',
        );

        self::assertMatchesRegularExpression(
            '/\.dark\s+\.keyword-risk-badge--review\s*\{[^}]*rgb\(252\s+165\s+165\)/',
            $css,
            'Dark-mode review badge must use light red text',
        );
    }

    public function test_safe_and_low_badge_css_unchanged(): void
    {
        $css = $this->loadKeywordWorkspaceCss();

        // Safe badge still green
        self::assertMatchesRegularExpression(
            '/\.keyword-risk-badge--safe\s*\{[^}]*rgb\(220\s+252\s+231\)/',
            $css,
        );

        // Low badge still blue
        self::assertMatchesRegularExpression(
            '/\.keyword-risk-badge--low\s*\{[^}]*rgb\(224\s+242\s+254\)/',
            $css,
        );
    }

    // ── Backend key unchanged ──

    public function test_read_model_still_uses_review_key_internally(): void
    {
        $ref = new \ReflectionClass(
            \Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordExternalRelationshipReadModel::class,
        );
        $source = (string) file_get_contents((string) $ref->getFileName());

        // Internal risk level key must remain 'review'
        self::assertStringContainsString("'review'", $source);
        self::assertStringNotContainsString("'warning'", $source);
    }

    // ── Helpers ──

    private function loadLang(string $locale): array
    {
        $path = dirname(__DIR__, 3) . "/seo-content-ai-compat/lang/{$locale}/filament.php";
        self::assertFileExists($path);

        return require $path;
    }

    private function loadKeywordWorkspaceCss(): string
    {
        $path = dirname(__DIR__, 3) . '/seo/resources/css/keyword-workspace.css';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
