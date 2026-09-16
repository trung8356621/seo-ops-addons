<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionCandidateRetriever;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Anchor candidate vs resolved destination decoupling after 0.7.8 coupling fix.
 */
final class AnchorCandidateDestinationDecouplingTest extends TestCase
{
    public function test_placeholder_rejected_by_real_link_validator_but_usable_as_anchor(): void
    {
        self::assertTrue(LinkSuggestionValidator::isUsableAnchorCandidate([
            'text' => 'túi xách du lịch',
        ]));
        self::assertFalse(LinkSuggestionValidator::isValidLinkSuggestion([
            'text' => 'túi xách du lịch',
            'href' => '#',
            'bucket' => 'internal',
        ], ['site_domain' => 'shop.example']));
        self::assertTrue(SeoSuggestionUrlNormalizer::isPlaceholder('#'));
    }

    public function test_resolve_best_keeps_unresolved_destination_as_editor_placeholder(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'resolveBestForAnchors');

        self::assertStringContainsString('isUsableAnchorCandidate', $body);
        self::assertStringContainsString("destinationResolved ? \$url : '#'", $body);
        self::assertStringContainsString("'destination_resolved' => \$destinationResolved", $body);
        self::assertStringContainsString("'url' => \$destinationResolved ? \$url : null", $body);
    }

    public function test_pipeline_generic_stage_accepts_unresolved_placeholder_href(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName(),
        );

        self::assertStringContainsString('destination_resolved', $src);
        self::assertStringContainsString("href = \$destinationResolved ? \$canonicalUrl : '#'", $src);
        self::assertStringContainsString('isUsableAnchorCandidate', $src);
    }

    public function test_placeable_search_still_requires_resolved_destination(): void
    {
        $ranked = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'searchRanked');
        self::assertStringContainsString('destination_resolved', $ranked);
        self::assertStringContainsString('isParsableTarget', $ranked);

        $searchSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkSearchService::class))->getFileName(),
        );
        self::assertStringContainsString('hasWpPostId()', $searchSrc);
        self::assertStringContainsString('resolveAuthoritativePermalink', $searchSrc);
    }

    public function test_index_shape_has_nullable_url_and_destination_flag(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'siteArticleIndex');
        self::assertStringContainsString("'destination_resolved' => \$destinationResolved", $body);
        self::assertStringContainsString('canonicalUrl = $destinationResolved ? $url : null', $body);
        self::assertStringNotContainsString('hasWpPostId()', $body);
        self::assertStringContainsString('notContentArchived()', $body);
    }

    public function test_placeholder_not_counted_as_internal_or_external_link(): void
    {
        $analyzer = (new ReflectionClass(SeoAnalyzerService::class))->newInstanceWithoutConstructor();
        $extracted = $analyzer->extractLinks(
            '<a href="https://shop.example/one">one</a>'
            .'<a href="#">ph</a>'
            .'<a href="https://shop.example/two">two</a>'
            .'<a href="https://external.example/x">ext</a>',
            'shop.example',
        );

        self::assertCount(2, $extracted['internal']);
        self::assertCount(1, $extracted['external']);
    }

    public function test_existing_link_scanner_skips_hash_placeholders(): void
    {
        $scanner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/existingLinkScanner.js',
        );
        self::assertStringContainsString("value === '#'", $scanner);
        self::assertStringContainsString("lower.startsWith('#')", $scanner);
    }

    public function test_wordpress_permalink_sot_unchanged_no_domain_slug(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(WordPressInternalLinkTargetPolicy::class))->getFileName(),
        );
        self::assertStringContainsString('never fabricates', $src);
        self::assertStringContainsString('observed_permalink', $src);
        self::assertStringContainsString('site_index.v3.', $src);
        self::assertStringNotContainsString('getPermalinkBase', $src);
    }

    public function test_wrong_layer_gen_normalizer_removed(): void
    {
        $addons = ProjectRoot::addonsPath();
        self::assertFileDoesNotExist($addons.'/content/src/Services/AiGeneratedArticleLinkNormalizer.php');
        self::assertFileDoesNotExist($addons.'/content/src/Services/WordPressAiGeneratedLinkDestinationGate.php');
        self::assertFileDoesNotExist($addons.'/content/src/Contracts/AiGeneratedLinkDestinationGate.php');

        $publish = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService::class))
                ->getFileName(),
        );
        self::assertStringNotContainsString('AiGeneratedArticleLinkNormalizer', $publish);
        self::assertStringNotContainsString('rewriteUnverifiedDestinations', $publish);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = new ReflectionMethod($class, $method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
