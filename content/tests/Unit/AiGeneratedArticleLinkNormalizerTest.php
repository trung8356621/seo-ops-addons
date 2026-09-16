<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Contracts\AiGeneratedLinkDestinationGate;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\AiGeneratedArticleLinkNormalizer;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class AiGeneratedArticleLinkNormalizerTest extends TestCase
{
    public function test_raw_html_anchor_without_verified_url_becomes_placeholder(): void
    {
        $html = $this->pipelineHtml(
            'Prefix <a href="https://example-ai-hallucinated.com/foo">túi xách du lịch</a> suffix',
            verifiedUrls: [],
        );

        self::assertStringContainsString('<a href="#">túi xách du lịch</a>', $html);
        self::assertStringNotContainsString('example-ai-hallucinated.com', $html);
        self::assertStringContainsString('Prefix', $html);
        self::assertStringContainsString('suffix', $html);
    }

    public function test_suggested_anchor_with_verified_target_keeps_real_url(): void
    {
        $real = 'https://shop.example/tui-xach-du-lich';
        $html = $this->pipelineHtml(
            'See <a href="'.$real.'">túi xách du lịch</a> here',
            verifiedUrls: [$real],
        );

        self::assertStringContainsString('<a href="'.$real.'">túi xách du lịch</a>', $html);
        self::assertStringNotContainsString('href="#"', $html);
    }

    public function test_markdown_hallucinated_url_is_not_kept(): void
    {
        $html = $this->pipelineHtml(
            'See [túi xách du lịch](https://evil.example/fake-slug) here',
            verifiedUrls: [],
        );

        self::assertStringContainsString('<a href="#">túi xách du lịch</a>', $html);
        self::assertStringNotContainsString('evil.example', $html);
    }

    public function test_anchor_text_is_not_lost_when_destination_unresolved(): void
    {
        $html = $this->pipelineHtml(
            '[unique-anchor-phrase-xyz](https://not-verified.example/x)',
            verifiedUrls: [],
        );

        self::assertStringContainsString('unique-anchor-phrase-xyz', $html);
        self::assertMatchesRegularExpression('/<a\b[^>]*href="#"[^>]*>unique-anchor-phrase-xyz<\/a>/', $html);
    }

    public function test_existing_real_article_link_not_replaced_with_placeholder(): void
    {
        $real = 'https://shop.example/existing-post';
        $html = $this->pipelineHtml(
            'Keep [existing post]('.$real.') and drop [fake](https://hallucinated.example/x)',
            verifiedUrls: [$real],
        );

        self::assertStringContainsString('<a href="'.$real.'">existing post</a>', $html);
        self::assertStringContainsString('<a href="#">fake</a>', $html);
    }

    public function test_placeholder_href_not_counted_as_internal_or_external(): void
    {
        $content = '<p><a href="https://shop.example/a">real internal</a> '
            .'<a href="https://shop.example/b">real two</a> '
            .'<a href="#">placeholder</a> '
            .'<a href="https://external.example/x">external</a></p>';

        $analyzer = $this->seoAnalyzerWithoutConstructor();
        $extracted = $analyzer->extractLinks($content, 'shop.example');

        self::assertCount(2, $extracted['internal']);
        self::assertCount(1, $extracted['external']);
        foreach ($extracted['internal'] as $row) {
            self::assertFalse(str_starts_with((string) ($row['href'] ?? ''), '#'));
        }
        foreach ($extracted['external'] as $row) {
            self::assertFalse(str_starts_with((string) ($row['href'] ?? ''), '#'));
        }
    }

    public function test_fragment_only_skipped_like_placeholder(): void
    {
        $analyzer = $this->seoAnalyzerWithoutConstructor();
        $extracted = $analyzer->extractLinks(
            '<p><a href="#section">jump</a><a href="#abcPENDING">pending</a><a href="https://shop.example/ok">ok</a></p>',
            'shop.example',
        );

        self::assertCount(1, $extracted['internal']);
        self::assertSame('https://shop.example/ok', $extracted['internal'][0]['href']);
        self::assertSame([], $extracted['external']);
    }

    public function test_two_real_internal_plus_placeholder_counts_as_two(): void
    {
        $scanner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/existingLinkScanner.js',
        );
        self::assertStringContainsString("value === '#'", $scanner);
        self::assertStringContainsString("lower.startsWith('#')", $scanner);

        $analyzer = $this->seoAnalyzerWithoutConstructor();
        $extracted = $analyzer->extractLinks(
            '<a href="https://shop.example/one">one</a>'
            .'<a href="#">ph</a>'
            .'<a href="https://shop.example/two">two</a>',
            'shop.example',
        );
        self::assertCount(2, $extracted['internal']);
    }

    public function test_publish_article_wires_destination_rewrite(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService::class))
                ->getFileName() ?: '',
        );
        self::assertStringContainsString('AiGeneratedArticleLinkNormalizer', $src);
        self::assertStringContainsString('rewriteUnverifiedDestinations', $src);
    }

    public function test_markdown_service_preserves_raw_html_anchors_before_commonmark(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleMarkdownToHtmlService::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('preserveRawHtmlAnchorsInMarkdown', $src);
    }

    public function test_wordpress_policy_still_rejects_domain_slug_fabrication(): void
    {
        $policySrc = (string) file_get_contents(
            (new ReflectionClass(WordPressInternalLinkTargetPolicy::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('never fabricates', $policySrc);
        self::assertStringContainsString('observed_permalink', $policySrc);
        self::assertStringContainsString('wp_permalink', $policySrc);
    }

    public function test_preserve_turns_html_anchor_into_markdown_link(): void
    {
        $normalizer = $this->normalizer(verifiedUrls: []);
        $md = $normalizer->preserveRawHtmlAnchorsInMarkdown(
            'X <a href="https://evil.example/a">anchor text</a> Y',
        );
        self::assertSame('X [anchor text](https://evil.example/a) Y', $md);
    }

    public function test_javascript_href_becomes_placeholder_in_markdown_preserve(): void
    {
        $normalizer = $this->normalizer(verifiedUrls: []);
        $md = $normalizer->preserveRawHtmlAnchorsInMarkdown(
            '<a href="javascript:alert(1)">click</a>',
        );
        self::assertSame('[click](#)', $md);
    }

    /**
     * @param  list<string>  $verifiedUrls
     */
    private function pipelineHtml(string $markdown, array $verifiedUrls): string
    {
        $normalizer = $this->normalizer($verifiedUrls);
        $markdownService = new ArticleMarkdownToHtmlService(
            new SimpleMarkdownHtmlConverter,
            $normalizer,
        );
        $html = $markdownService->toHtml($markdown);
        $article = new SeoArticle;
        $article->forceFill(['id' => 1, 'site_id' => 7, 'slug' => 'current']);

        return $normalizer->rewriteUnverifiedDestinations($html, $article);
    }

    /**
     * @param  list<string>  $verifiedUrls
     */
    private function normalizer(array $verifiedUrls): AiGeneratedArticleLinkNormalizer
    {
        $normalizedAllowed = [];
        foreach ($verifiedUrls as $url) {
            $normalizedAllowed[strtolower(rtrim(trim($url), '/'))] = trim($url);
        }

        $gate = new class($normalizedAllowed) implements AiGeneratedLinkDestinationGate
        {
            /** @param array<string, string> $allowed */
            public function __construct(private readonly array $allowed) {}

            public function isVerifiedDestination(string $href, SeoArticle $article): bool
            {
                $needle = strtolower(rtrim(trim($href), '/'));

                return isset($this->allowed[$needle]);
            }

            public function authoritativePermalink(string $href, SeoArticle $article): ?string
            {
                $needle = strtolower(rtrim(trim($href), '/'));

                return $this->allowed[$needle] ?? null;
            }
        };

        return new AiGeneratedArticleLinkNormalizer($gate);
    }

    private function seoAnalyzerWithoutConstructor(): SeoAnalyzerService
    {
        return (new ReflectionClass(SeoAnalyzerService::class))
            ->newInstanceWithoutConstructor();
    }
}
