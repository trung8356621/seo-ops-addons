<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultKeywordDiscoveryPromptInstaller;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Product planning brief / option / persist regression — Post behavior must stay compatible.
 */
final class NewContentProductPlanningBriefTest extends TestCase
{
    public function test_normalize_product_options_remain_product(): void
    {
        $opts = NewContentSuggestionOptions::normalize(['post_type' => 'product']);

        self::assertSame('product', $opts['post_type']);
        self::assertSame('product', $opts['content_type']);
        self::assertSame('product', NewContentSuggestionOptions::taskPostType($opts['post_type']));
        self::assertSame('product', NewContentSuggestionOptions::taskPostType($opts['content_type']));
    }

    public function test_product_render_brief_requests_product_planning_shape(): void
    {
        $brief = $this->renderBrief(['content_type' => 'product', 'quantity' => 5]);

        self::assertStringContainsString('OUTPUT CONTRACT', $brief);
        self::assertStringContainsString('Mode: PRODUCT', $brief);
        self::assertStringContainsString('product_type', $brief);
        self::assertStringContainsString('gallery_description', $brief);
        self::assertStringContainsString('PRODUCT CONTENT BRIEF', $brief);
        self::assertStringContainsString('product-page opportunities', $brief);
        self::assertStringContainsString('Propose Product planning items only', $brief);
        self::assertStringContainsString('Existing published product/content titles', $brief);
        self::assertStringContainsString('Do not fabricate prices', $brief);
        self::assertStringNotContainsString('article brief that disambiguates', $brief);
        self::assertStringNotContainsString('Do not invent articles; propose planning suggestions only.', $brief);
        self::assertStringNotContainsString('Existing published article titles', $brief);
        self::assertStringNotContainsString('Mode: POST', $brief);
    }

    public function test_post_render_brief_keeps_article_semantics_without_product_fields(): void
    {
        $brief = $this->renderBrief(['content_type' => 'post', 'quantity' => 5]);

        self::assertStringContainsString('OUTPUT CONTRACT', $brief);
        self::assertStringContainsString('Mode: POST', $brief);
        self::assertStringContainsString('article brief that disambiguates', $brief);
        self::assertStringContainsString('Do not invent articles; propose planning suggestions only.', $brief);
        self::assertStringContainsString('Existing published article titles (coverage evidence):', $brief);
        self::assertStringContainsString('Rewrite/Improve', $brief);
        self::assertStringContainsString('Do NOT include product_type', $brief);
        self::assertStringNotContainsString('"product_type":', $brief);
        self::assertStringNotContainsString('Mode: PRODUCT', $brief);
        self::assertStringNotContainsString('PRODUCT PLANNING', $brief);
        self::assertStringNotContainsString('PRODUCT CONTENT BRIEF', $brief);
    }

    public function test_planner_product_execution_passes_product_into_discover_once(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString(
            'contentType: NewContentSuggestionOptions::normalizeContentType((string) ($options[\'post_type\'] ?? $options[\'content_type\'] ?? \'post\'))',
            $src,
        );
        self::assertStringContainsString("'post_type' => \$contentType", $src);
        self::assertStringContainsString("'content_type' => \$contentType", $src);
        self::assertStringContainsString('NewContentSuggestionOptions::taskPostType((string) $options[\'post_type\'])', $src);
    }

    public function test_parser_preserves_product_type_and_gallery_description(): void
    {
        $parsed = (new NewContentSuggestionParser)->parse([
            [
                'keyword' => 'balo chống nước',
                'suggested_title' => 'Balo chống nước Hợp Phát',
                'description' => 'Trang sản phẩm nhấn mạnh độ bền và chống nước cho học sinh.',
                'product_type' => 'balo học sinh',
                'gallery_description' => 'Ảnh sản phẩm góc trước/sau, lifestyle học đường.',
                'suggestion_reason' => 'cluster gap',
                'source_signal' => 'cluster_gap',
            ],
        ], 10);

        self::assertSame('balo học sinh', $parsed['candidates'][0]['product_type']);
        self::assertSame('Ảnh sản phẩm góc trước/sau, lifestyle học đường.', $parsed['candidates'][0]['gallery_description']);
        self::assertSame(
            'Trang sản phẩm nhấn mạnh độ bền và chống nước cho học sinh.',
            $parsed['candidates'][0]['description'],
        );
    }

    public function test_product_persistence_mapping_contract(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        self::assertSame('product', SeoProjectTask::POST_TYPE_PRODUCT);
        self::assertStringContainsString('$isProduct = $postType === SeoProjectTask::POST_TYPE_PRODUCT', $src);
        self::assertStringContainsString("'post_type' => \$postType !== '' ? \$postType : SeoProjectTask::POST_TYPE_ARTICLE", $src);
        self::assertStringContainsString("'loai_san_pham' => \$isProduct && \$productType !== '' ? \$productType : null", $src);
        self::assertStringContainsString("'description' => \$isProduct && \$gallery !== '' ? \$gallery : null", $src);
        self::assertStringContainsString("'secondary_description' => \$brief !== '' ? \$brief : null", $src);
        self::assertStringContainsString("\$productType = \$isProduct ? trim((string) (\$candidate['product_type'] ?? '')) : ''", $src);
        self::assertStringContainsString("\$gallery = \$isProduct ? trim((string) (\$candidate['gallery_description'] ?? '')) : ''", $src);
    }

    public function test_post_persistence_does_not_write_product_only_fields(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        // Post path still uses description→secondary_description; product fields gated by $isProduct.
        self::assertStringContainsString("'secondary_description' => \$brief !== '' ? \$brief : null", $src);
        self::assertStringContainsString("'description' => \$isProduct && \$gallery !== '' ? \$gallery : null", $src);
        self::assertStringContainsString("'loai_san_pham' => \$isProduct && \$productType !== '' ? \$productType : null", $src);
        self::assertSame('article', NewContentSuggestionOptions::taskPostType('post'));
    }

    public function test_canonical_hook_defines_both_modes_and_still_validates(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/keyword.discovery.structured@0.1.0.json';
        $spec = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($spec);
        self::assertSame([], (new PromptHookSpecV01Validator)->validate($spec));
        self::assertSame('0.1.0', $spec['version']);

        $markdown = (string) ($spec['canonical_default']['markdown'] ?? '');
        self::assertStringContainsString('OUTPUT CONTRACT — STRICT', $markdown);
        self::assertStringContainsString('FIRST non-whitespace character', $markdown);
        self::assertStringContainsString('Do all evaluation internally', $markdown);
        self::assertStringContainsString('POST mode', $markdown);
        self::assertStringContainsString('PRODUCT mode', $markdown);
        self::assertStringContainsString('product_type', $markdown);
        self::assertStringContainsString('gallery_description', $markdown);
        self::assertStringContainsString('PRODUCT CONTENT BRIEF', $markdown);
        self::assertStringContainsString('article planning brief', $markdown);
        self::assertStringContainsString('Product-page Draft planning candidates', $markdown);
        self::assertStringNotContainsString('product-oriented content ideas for a product flow', $markdown);
        self::assertStringNotContainsString('think step by step', mb_strtolower($markdown));
        self::assertStringNotContainsString('explain your reasoning', mb_strtolower($markdown));

        $installerMarkdown = DefaultKeywordDiscoveryPromptInstaller::canonicalDefaultMarkdown();
        self::assertStringContainsString('PRODUCT mode', $installerMarkdown);
        self::assertStringContainsString('{{brief}}', $installerMarkdown);

        // Safety: installer still requires explicit restore to overwrite existing markdown.
        $installerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(DefaultKeywordDiscoveryPromptInstaller::class))->getFileName(),
        );
        self::assertStringContainsString('restoreCanonical', $installerSrc);
        self::assertStringContainsString('} elseif ($restoreCanonical) {', $installerSrc);
    }

    public function test_runtime_brief_is_injected_as_planning_context_for_legacy_prompt(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString("'brief' => \$brief", $src);
        self::assertStringContainsString("'planning_context' => \$brief", $src);
        self::assertStringContainsString("'user_brief' => \$brief", $src);
    }

    public function test_coverage_methods_are_not_content_type_filtered_yet(): void
    {
        // Secondary observation only — do not silently change coverage semantics in this patch.
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );
        $existingPublished = new ReflectionMethod(ContentPlanningIntelligenceService::class, 'existingPublishedTitles');
        self::assertTrue($existingPublished->isPrivate());
        self::assertStringContainsString('existingPublishedTitles($siteId)', $src);
        self::assertStringNotContainsString('existingPublishedTitles($siteId, $options', $src);
        self::assertStringContainsString("->whereHas('wordpressLink'", $src);
        self::assertStringNotContainsString("wp_post_type", $src);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function renderBrief(array $options): string
    {
        $service = new ContentPlanningIntelligenceService;
        $ctx = [
            'site' => [
                'id' => 1,
                'domain' => 'example.test',
                'primary_language' => 'vi',
            ],
            'coverage' => ['covered' => 0, 'weakly_covered' => 0, 'uncovered' => 0, 'unknown' => 0],
            'principal_keywords' => [],
            'clusters' => [],
            'missing_directions' => [],
            'existing_topics' => [
                ['title' => 'Bài viết blog cũ', 'coverage' => 'covered'],
            ],
            'planned_topics' => [],
            'rejected_topics' => [],
            'mcp_signals' => [],
            'gsc_signals' => [],
            'mcp_period' => null,
            'covered_keyword_norms' => [],
            'planned_fingerprints' => [],
            'rejected_fingerprints' => [],
            'diagnostics' => [
                'principal_keywords_count' => 0,
                'cluster_count' => 0,
                'missing_direction_count' => 0,
                'mcp_period' => null,
                'covered_keyword_count' => 0,
            ],
        ];

        return $service->renderBrief($ctx, $options);
    }
}
