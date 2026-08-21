<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Source/contract test (plain string parsing â€” no class autoload, no Livewire mount,
 * no DB; mirrors ArticleEditorMountNoRemoteWpTest's approach) â€” Phase 2:
 * getEditorCoreBootstrap() must expose the lazy endpoint map and identity/content
 * fields, and must NOT eagerly pull heavy SEO/images/faqs/meta payloads inline.
 */
final class ArticleEditorCoreBootstrapContractTest extends TestCase
{
    public function test_core_bootstrap_exposes_identity_content_and_endpoint_map(): void
    {
        $body = $this->methodBody('public function getEditorCoreBootstrap(): array');
        self::assertNotSame('', $body);

        foreach ([
            "'articleId'", "'connectionHash'", "'siteId'", "'title'", "'slug'",
            "'metaDescription'", "'focusKeyword'", "'permalinkBase'", "'permalinkSuffix'", "'siteDomain'",
            "'content'", "'contentLifecycle'", "'status'", "'postType'", "'updatedAt'", "'expectedUpdatedAt'",
            "'expectedContentHash'", "'featuredImageUrl'", "'supportsProductGallery'", "'isCanaryProduct'",
            "'parentChildAllowed'", "'parentChildReason'",
            "'endpoints'", "'settings'",
        ] as $needle) {
            self::assertStringContainsString($needle, $body, "core bootstrap missing key {$needle}");
        }

        foreach ([
            'seoSummary', 'images', 'faqs', 'faqsCount', 'meta', 'links', 'linksSuggestions', 'vocabulary', 'settings', 'mediaPickerConfig',
        ] as $endpoint) {
            self::assertStringContainsString("'{$endpoint}'", $body, "core bootstrap endpoints missing {$endpoint}");
        }

        self::assertStringContainsString("'faqCount'", $body);
    }

    public function test_core_bootstrap_does_not_inline_heavy_payload_getters(): void
    {
        $body = $this->methodBody('public function getEditorCoreBootstrap(): array');
        self::assertNotSame('', $body);

        self::assertStringNotContainsString('getEditorSeoPayload', $body);
        self::assertStringNotContainsString('getEditorImagesPayload', $body);
        self::assertStringNotContainsString('getEditorFaqsPayload', $body);
        self::assertStringNotContainsString('getEditorMetaPayload', $body);
        self::assertStringNotContainsString('getArticleMediaPickerPayload', $body);
        // Phase 2: analysisPolicy/externalFacts computed once and reused into settings.
        self::assertStringContainsString('$analysisPolicy', $body);
        self::assertStringContainsString('$externalFacts', $body);
        self::assertStringContainsString('getEditorCoreSettingsPayload($analysisPolicy, $externalFacts)', $body);
        self::assertSame(1, substr_count($body, '->forArticle($this->record)'));
        self::assertSame(1, substr_count($body, '->externalFacts($this->record)'));
    }

    public function test_core_settings_payload_excludes_scoring_rules_and_messages(): void
    {
        $body = $this->methodBody('private function getEditorCoreSettingsPayload(?array $analysisPolicy = null, ?array $externalFacts = null): array');
        self::assertNotSame('', $body);

        self::assertStringNotContainsString('seo_scoring_rules', $body);
        self::assertStringNotContainsString('seo_rule_messages', $body);
        self::assertStringNotContainsString('seo_scoring_messages', $body);
        self::assertStringNotContainsString('SeoScoringRulesRegistry', $body);
    }

    /**
     * Extract one method body only (brace-depth matched) â€” avoids autoloading the
     * EditArticle class (Filament\Resources\Pages\EditRecord + Livewire chain).
     */
    private function methodBody(string $signature): string
    {
        static $source = null;
        if ($source === null) {
            $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
            $source = (string) file_get_contents($path);
        }

        $pos = strpos($source, $signature);
        if ($pos === false) {
            return '';
        }

        $brace = strpos($source, '{', $pos);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }
}
