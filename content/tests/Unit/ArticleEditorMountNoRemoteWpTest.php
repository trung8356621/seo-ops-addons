<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Static contract: EditArticle mount must not call remote WordPress helpers.
 */
final class ArticleEditorMountNoRemoteWpTest extends TestCase
{
    public function test_mount_and_hydrate_source_omit_remote_wordpress_calls(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);

        $mountBlock = $this->extractMethodBody($source, 'public function mount(int|string $record): void');
        $pollBlock = $this->extractMethodBody($source, 'public function pollEditorReadiness(): void');
        $hydrateBlock = $this->extractMethodBody($source, 'protected function hydrateArticleState(): void');

        self::assertNotSame('', $mountBlock);
        self::assertNotSame('', $pollBlock);
        self::assertNotSame('', $hydrateBlock);

        foreach (['syncTitleFromWordPressWhenAllowed', 'syncWordPressCategoriesOnLoad', 'importFaqsFromWordPressOnLoad'] as $forbidden) {
            self::assertStringNotContainsString(
                '$this->'.$forbidden.'(',
                $mountBlock,
                "mount() must not call {$forbidden}",
            );
            self::assertStringNotContainsString(
                '$this->'.$forbidden.'(',
                $pollBlock,
                "pollEditorReadiness() must not call {$forbidden}",
            );
        }

        self::assertStringNotContainsString(
            'healTaxonomyMetaFromWordPress',
            $hydrateBlock,
            'hydrateArticleState() must not call healTaxonomyMetaFromWordPress (remote HTTP)',
        );
        self::assertStringNotContainsString(
            'resolveEditorHtml',
            $hydrateBlock,
            'hydrateArticleState() must not call resolveEditorHtml (WP HTTP fallback)',
        );
        self::assertStringNotContainsString(
            'resolveFeaturedImageUrl',
            $hydrateBlock,
            'hydrateArticleState() must not call resolveFeaturedImageUrl (WP HTTP fallback)',
        );
        self::assertStringNotContainsString(
            'resolveProductAlbum',
            $hydrateBlock,
            'hydrateArticleState() must not load product album into Livewire snapshot',
        );
        self::assertStringContainsString('productGallery = []', $hydrateBlock);

        self::assertStringContainsString('protected string $bootstrapEditorHtml', $source);
        self::assertStringNotContainsString('public string $editorHtml', $source);
        self::assertStringContainsString('forEditorBootstrap', $source);

        $forceOpenBlock = $this->extractMethodBody($source, 'public function forceOpenEditorWhilePreparing(): void');
        self::assertNotSame('', $forceOpenBlock);
        foreach (['syncTitleFromWordPressWhenAllowed', 'syncWordPressCategoriesOnLoad', 'importFaqsFromWordPressOnLoad'] as $forbidden) {
            self::assertStringNotContainsString(
                '$this->'.$forbidden.'(',
                $forceOpenBlock,
                "forceOpenEditorWhilePreparing() must not call {$forbidden}",
            );
        }
    }

    /**
     * Extract one method body only (until next method at same indentation level).
     * Avoids swallowing later private method definitions that share the forbidden names.
     */
    private function extractMethodBody(string $source, string $signature): string
    {
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
