<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\TestCase;

final class ArticleEditorLegacyFaqOwnershipTest extends TestCase
{
    public function test_article_editor_save_does_not_call_php_faq_body_extraction(): void
    {
        $persist = file_get_contents(__DIR__.'/../../src/Services/ArticleEditorPersistService.php');
        $page = file_get_contents(__DIR__.'/../../src/Filament/Resources/ArticleResource/Pages/EditArticle.php');

        $this->assertStringNotContainsString('extractFromBodyWhenMissing', (string) $persist);
        $this->assertStringNotContainsString('extractFromBodyWhenMissing', (string) $page);
    }

    public function test_browser_legacy_faq_flow_is_bounded_and_shared(): void
    {
        $utility = file_get_contents(__DIR__.'/../../resources/js/utils/legacyFaqDetection.js');
        $editor = file_get_contents(__DIR__.'/../../resources/js/components/SeoArticleEditor.jsx');

        $this->assertStringContainsString('.slice(-3)', (string) $utility);
        $this->assertStringContainsString('detectFaqPairsFromSection', (string) $utility);
        $this->assertStringContainsString("extractLegacyFaqSection(section, 'manual')", (string) $editor);
        $this->assertStringContainsString("extractLegacyFaqSection(candidate, 'auto')", (string) $editor);
        $this->assertStringContainsString('restoreLegacyFaqSection', (string) $editor);
    }
}
