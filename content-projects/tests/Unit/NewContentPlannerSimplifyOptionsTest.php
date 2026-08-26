<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Simplified AI New Content options: Notes + Content type (post|product).
 */
final class NewContentPlannerSimplifyOptionsTest extends TestCase
{
    public function test_default_content_type_is_post_not_article_label(): void
    {
        $opts = NewContentSuggestionOptions::normalize(['quantity' => 20]);
        self::assertSame('post', $opts['post_type']);
        self::assertSame('post', $opts['content_type']);
        self::assertSame('', $opts['notes']);
        self::assertSame('automatic', $opts['direction']);
    }

    public function test_page_and_taxonomy_entities_collapse_to_post(): void
    {
        self::assertSame('post', NewContentSuggestionOptions::normalize(['post_type' => 'page'])['post_type']);
        self::assertSame('post', NewContentSuggestionOptions::normalize(['post_type' => 'category'])['post_type']);
        self::assertSame('post', NewContentSuggestionOptions::normalize(['post_type' => 'product_category'])['post_type']);
        self::assertSame('product', NewContentSuggestionOptions::normalize(['content_type' => 'product'])['post_type']);
    }

    public function test_legacy_article_maps_to_post_and_task_post_type_article(): void
    {
        $opts = NewContentSuggestionOptions::normalize(['post_type' => 'article']);
        self::assertSame('post', $opts['post_type']);
        self::assertSame('article', NewContentSuggestionOptions::taskPostType($opts['post_type']));
        self::assertSame('product', NewContentSuggestionOptions::taskPostType('product'));
    }

    public function test_old_focus_maps_into_notes(): void
    {
        $opts = NewContentSuggestionOptions::normalize(['focus' => 'balo học sinh']);
        self::assertSame('balo học sinh', $opts['notes']);

        $withNotes = NewContentSuggestionOptions::normalize([
            'focus' => 'old',
            'notes' => 'Ưu tiên học sinh cấp 2.',
        ]);
        self::assertSame('Ưu tiên học sinh cấp 2.', $withNotes['notes']);
    }

    public function test_snapshot_stores_notes_and_content_type_without_filter_noise(): void
    {
        $snap = NewContentSuggestionOptions::snapshot([
            'quantity' => 20,
            'notes' => 'Ưu tiên quà tặng doanh nghiệp.',
            'content_type' => 'post',
        ], 'vi');

        self::assertSame(20, $snap['quantity']);
        self::assertSame('post', $snap['content_type']);
        self::assertSame('post', $snap['post_type']);
        self::assertSame('Ưu tiên quà tặng doanh nghiệp.', $snap['notes']);
        self::assertSame('', $snap['focus']);
        self::assertSame('', $snap['taxonomy']);
        self::assertSame('automatic', $snap['direction']);
        self::assertTrue($snap['context']['planning_intelligence']);
    }

    public function test_empty_notes_brief_omits_additional_instructions(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );
        self::assertStringContainsString('Additional user instructions:', $src);
        self::assertStringContainsString('Content type:', $src);
        self::assertStringContainsString("\$options['notes']", $src);
    }

    public function test_planner_maps_content_type_to_task_post_type(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString('NewContentSuggestionOptions::taskPostType', $src);
        self::assertStringContainsString('logical_ai_calls', $src);
    }

    public function test_ui_card_has_notes_and_content_type_without_legacy_fields(): void
    {
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');

        self::assertStringContainsString('planner_notes', $card);
        self::assertStringContainsString('newContentNotes', $card);
        self::assertStringContainsString('planner_content_type', $card);
        self::assertStringContainsString('newContentPostType', $card);
        self::assertStringContainsString('data-planner-content-type="1"', $card);
        self::assertStringContainsString('data-planner-notes="new-content"', $card);
        self::assertStringNotContainsString('planner_options', $card);
        self::assertStringNotContainsString('planner_save_options', $card);
        self::assertStringNotContainsString('data-planner-options="new-content"', $card);
        self::assertStringNotContainsString('saveNewContentOptions', $card);

        self::assertStringNotContainsString('newContentDirection', $card);
        self::assertStringNotContainsString('newContentFocus', $card);
        self::assertStringNotContainsString('newContentTaxonomy', $card);
        self::assertStringNotContainsString('planner_direction', $card);
        self::assertStringNotContainsString('planner_focus', $card);
        self::assertStringNotContainsString('planner_taxonomy', $card);
        self::assertStringNotContainsString('article / post', $card);
        self::assertStringNotContainsString('planner_filters', $card);
        self::assertStringNotContainsString('data-planner-filters="new-content"', $card);
        self::assertStringNotContainsString('<option value="page"', $card);
        self::assertStringNotContainsString('<option value="category"', $card);
        self::assertStringNotContainsString('article / post', $card);
    }

    public function test_parser_maps_product_fields_without_fabricating(): void
    {
        $parser = new NewContentSuggestionParser;
        $product = $parser->parse([
            [
                'keyword' => 'balo học sinh sư tử',
                'suggested_title' => 'Balo học sinh Sư Tử - Công ty may balo Hợp Phát',
                'description' => 'Brief nội dung...',
                'product_type' => 'balo học sinh',
                'gallery_description' => 'Nền Cam Đào, pattern vương miện...',
                'suggestion_reason' => 'cluster gap',
                'source_signal' => 'cluster_gap',
            ],
        ], 20);

        self::assertSame('balo học sinh', $product['candidates'][0]['product_type']);
        self::assertSame('Nền Cam Đào, pattern vương miện...', $product['candidates'][0]['gallery_description']);
        self::assertSame('Brief nội dung...', $product['candidates'][0]['description']);

        $legacy = $parser->parse([
            ['keyword' => 'túi tote', 'suggested_title' => 'Túi tote canvas'],
        ], 20);
        self::assertSame('', $legacy['candidates'][0]['product_type']);
        self::assertSame('', $legacy['candidates'][0]['gallery_description']);
    }

    public function test_planner_persist_writes_canonical_product_task_fields(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString("'loai_san_pham' => \$isProduct && \$productType !== '' ? \$productType : null", $src);
        self::assertStringContainsString("'secondary_description' => \$brief !== '' ? \$brief : null", $src);
        self::assertStringContainsString("'description' => \$isProduct && \$gallery !== '' ? \$gallery : null", $src);
        self::assertStringContainsString('POST_TYPE_PRODUCT', $src);
        self::assertStringNotContainsString('saveNewContentOptions', $src);
    }
}
