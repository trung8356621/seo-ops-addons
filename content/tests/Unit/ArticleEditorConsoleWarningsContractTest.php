<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Contract: no duplicate runtime command / no seo-reason console spam for known keys.
 */
final class ArticleEditorConsoleWarningsContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_ai_module_does_not_reregister_insert_image(): void
    {
        $ai = $this->readAddon('resources/js/editor/modules/ai/index.js');
        $media = $this->readAddon('resources/js/editor/modules/media/index.js');

        self::assertStringContainsString("optionalDependsOn: ['article-editor.media']", $ai);
        self::assertStringContainsString('do not re-register', $ai);
        self::assertStringNotContainsString("id: 'insert_image'", $ai);
        self::assertStringContainsString("id: 'insert_image'", $media);
        self::assertStringContainsString("name: 'insert_image'", $media);
    }

    public function test_present_seo_reason_uses_client_defaults_without_spam_warn(): void
    {
        $source = $this->readAddon('resources/js/utils/seoReasonMetrics.js');

        self::assertStringContainsString('DEFAULT_SEO_RULE_TEMPLATES', $source);
        self::assertStringContainsString('resolveSeoReasonMessageBag', $source);
        self::assertStringContainsString('window.__SEO_RULE_MESSAGES__', $source);
        self::assertStringContainsString('wiki_trust_missing', $source);
        self::assertStringContainsString('keyword_missing_in_intro', $source);
        self::assertStringContainsString('content_length_low', $source);
        self::assertStringContainsString('Known keys already have client defaults', $source);
        self::assertStringContainsString("console.warn('[seo-reason] missing translation'", $source);
    }
}
