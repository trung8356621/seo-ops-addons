<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ArticleEditorMediaAiGalleryDescriptionBindTest extends TestCase
{
    public function test_modal_brief_wins_over_article_meta(): void
    {
        $bag = $this->invokeBriefVariables('NEW USER BRIEF', 'OLD META');

        self::assertSame('NEW USER BRIEF', $bag['gallery_description']);
        self::assertSame('NEW USER BRIEF', $bag['user_brief']);
        self::assertSame('NEW USER BRIEF', $bag['input']);
    }

    public function test_empty_modal_falls_back_to_article_meta(): void
    {
        $bag = $this->invokeBriefVariables('', 'OLD META');

        self::assertSame('OLD META', $bag['gallery_description']);
        self::assertSame('', $bag['user_brief']);
        self::assertSame('', $bag['input']);
    }

    public function test_modal_brief_fills_gallery_description_when_meta_empty(): void
    {
        $bag = $this->invokeBriefVariables('NEW USER BRIEF', '');

        self::assertSame('NEW USER BRIEF', $bag['gallery_description']);
        self::assertSame('NEW USER BRIEF', $bag['input']);
    }

    public function test_compiled_design_uses_gallery_description_not_placeholder(): void
    {
        $bag = $this->invokeBriefVariables('Nền balo màu đỏ (Crimson).', '');
        $template = "Product:\nTúi canvas quà tặng\n\nDesign:\n{{gallery_description}}";
        $compiled = $this->substituteVariables($template, [
            'gallery_description' => $bag['gallery_description'],
        ]);

        self::assertStringContainsString("Design:\nNền balo màu đỏ (Crimson).", $compiled);
        self::assertStringNotContainsString('{{gallery_description}}', $compiled);
    }

    public function test_mode1_and_mode2_share_exact_brief_helper(): void
    {
        $buildSrc = $this->methodBody(new ReflectionMethod(ArticleEditorMediaAiService::class, 'buildVariables'));
        $mode2Src = $this->methodBody(new ReflectionMethod(ArticleEditorMediaAiService::class, 'maybeStartMode2Gallery'));

        self::assertStringContainsString('buildProductGalleryBriefVariables', $buildSrc);
        self::assertStringContainsString('buildProductGalleryBriefVariables', $mode2Src);
        self::assertStringContainsString("'gallery_description' => \$galleryBrief['gallery_description']", $mode2Src);
        self::assertStringContainsString("'user_brief' => \$galleryBrief['user_brief']", $mode2Src);
        self::assertStringContainsString("'input' => \$galleryBrief['input']", $mode2Src);
    }

    public function test_helper_documents_modal_precedence(): void
    {
        $src = $this->methodBody(new ReflectionMethod(ArticleEditorMediaAiService::class, 'buildProductGalleryBriefVariables'));
        self::assertStringContainsString('$userBrief !== \'\'', $src);
        self::assertStringContainsString('? $userBrief', $src);
        self::assertStringContainsString(': trim((string) $fromArticle)', $src);
    }

    /**
     * @return array{gallery_description: string, user_brief: string, input: string}
     */
    private function invokeBriefVariables(string $userBrief, string $fromArticle): array
    {
        $service = (new ReflectionClass(ArticleEditorMediaAiService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ArticleEditorMediaAiService::class, 'buildProductGalleryBriefVariables');
        $method->setAccessible(true);

        $article = (new ReflectionClass(SeoArticle::class))->newInstanceWithoutConstructor();

        /** @var array{gallery_description: string, user_brief: string, input: string} $bag */
        $bag = $method->invoke($service, $article, $userBrief, $fromArticle);

        return $bag;
    }

    /**
     * Mirrors PromptRunnerService::substituteVariables — missing key keeps placeholder.
     *
     * @param  array<string, string>  $variables
     */
    private function substituteVariables(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $key = $matches[1];

                return array_key_exists($key, $variables)
                    ? (string) $variables[$key]
                    : $matches[0];
            },
            $text,
        );
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
