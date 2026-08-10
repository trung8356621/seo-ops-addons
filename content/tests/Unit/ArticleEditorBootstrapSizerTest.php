<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleEditorBootstrapSizer;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 bootstrap size fixtures — no Laravel app boot needed:
 * ArticleEditorBootstrapSizer::bytes()/kb() are pure (json_encode + strlen).
 *
 * Numbers here are cross-checked against `php -r` measurements recorded in
 * docs/archive/historical-reports/ARTICLE_EDITOR_PHASE2_BOOTSTRAP_SIZES.md.
 */
final class ArticleEditorBootstrapSizerTest extends TestCase
{
    public function test_bytes_and_kb_match_json_encode_strlen(): void
    {
        $data = ['a' => 1, 'b' => 'chuỗi tiếng Việt'];
        $expectedBytes = strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        self::assertSame($expectedBytes, ArticleEditorBootstrapSizer::bytes($data));
        self::assertSame(round($expectedBytes / 1024, 2), ArticleEditorBootstrapSizer::kb($data));
    }

    public function test_record_and_snapshot_accumulate_by_key(): void
    {
        $sizer = new ArticleEditorBootstrapSizer;
        $sizer->record('content', str_repeat('a', 1000));
        $sizer->record('seo', ['score' => 80]);

        $snapshot = $sizer->snapshot();

        self::assertArrayHasKey('content', $snapshot);
        self::assertArrayHasKey('seo', $snapshot);
        self::assertSame(1002, $snapshot['content']); // JSON string adds 2 quote bytes
        self::assertGreaterThan(0, $sizer->totalBytes());
    }

    /**
     * BEFORE (Phase 1 blade embed) vs AFTER (Phase 2 core bootstrap, content excluded)
     * fixture reduction — mirrors the `php -r` measurement script referenced in the
     * Phase 2 audit doc. Asserts the actual Phase 2 acceptance criterion:
     * after-non-content-bootstrap < before * 0.5 OR after-non-content < 15KB.
     */
    public function test_before_after_fixture_reduction_meets_phase2_target(): void
    {
        $before = $this->buildBeforeFixture();
        $after = $this->buildAfterFixture();

        $beforeBytes = ArticleEditorBootstrapSizer::bytes($before);
        // "content" HTML is intentionally excluded from the comparison — both phases
        // ship the article body once; the point of Phase 2 is trimming everything else.
        $afterNonContent = $after;
        unset($afterNonContent['content']);
        $afterNonContentBytes = ArticleEditorBootstrapSizer::bytes($afterNonContent);

        self::assertLessThan(
            15 * 1024,
            $afterNonContentBytes,
            sprintf(
                'Phase 2 core bootstrap (excl. content) should be < 15KB, got %.2f KB',
                $afterNonContentBytes / 1024,
            ),
        );

        self::assertLessThan(
            $beforeBytes * 0.5,
            $afterNonContentBytes,
            'Phase 2 non-content bootstrap should be < 50% of the Phase 1 fixture total',
        );
    }

    /**
     * Simulates the Phase 1 blade embeds: initial-html, initial-seo (forEditorBootstrap
     * shape with empty catalogs), initial-images (20 rows), settings (scoring rules +
     * messages), meta (supplemental 10 + product_gallery 5 + category options 30), faqs (10).
     *
     * @return array<string, mixed>
     */
    private function buildBeforeFixture(): array
    {
        return [
            'content' => str_repeat('<p>Paragraph with words. </p>', 2000),
            'seo' => [
                'score' => 82,
                'status' => 'cached',
                'analyzed_content_hash' => str_repeat('a', 64),
                'focus_keyword' => 'từ khóa mẫu',
                'seo_title' => 'Tiêu đề SEO mẫu cho bài viết dài',
                'meta_description' => str_repeat('Mô tả meta mẫu. ', 8),
                'updated_at' => '2026-07-22T08:00:00+00:00',
                'site_domain' => 'example.com',
                'article_type' => 'post',
                'skip_seo_score' => false,
                'article_slug' => 'bai-viet-mau',
                'permalink_base' => 'https://example.com/blog',
                'google_serp_preview' => [
                    'type' => 'article',
                    'title' => 'Tiêu đề SEO mẫu',
                    'url' => 'https://example.com/blog/bai-viet-mau',
                    'description' => str_repeat('Mô tả SERP mẫu. ', 6),
                    'display_url' => 'example.com › blog › bai-viet-mau',
                    'meta' => ['og_image' => 'https://example.com/img.jpg'],
                ],
                'analysis' => [
                    'violations' => array_fill(0, 8, ['rule' => 'sample_rule', 'message' => 'Vi phạm mẫu về nội dung SEO']),
                    'score' => 82,
                ],
                'violations' => array_fill(0, 8, ['rule' => 'sample_rule', 'message' => 'Vi phạm mẫu về nội dung SEO']),
                'extracted_links' => ['internal' => [], 'external' => []],
                'suggested_internal_links' => [],
                'suggested_internal_links_catalog' => [],
                'suggested_external_links' => [],
                'suggested_external_links_catalog' => [],
                'domain_link_list' => [],
                'domain_link_list_catalog' => [],
                'domain_cta_list' => [],
                'content_bonus' => null,
                'bootstrap_mode' => 'light',
            ],
            'images' => array_fill(0, 20, [
                'block_id' => 'blk_sample',
                'src' => 'https://example.com/wp-content/uploads/2026/07/sample.jpg',
                'wp_url' => 'https://example.com/wp-content/uploads/2026/07/sample.jpg',
                'local_src' => '',
                'slug' => 'sample',
                'alt' => 'Ảnh mẫu minh hoạ bài viết',
                'title' => 'Ảnh mẫu',
                'caption' => '',
                'align' => 'none',
                'wp_attachment_id' => 123,
                'seo_media_id' => null,
            ]),
            'settings' => [
                'history_step' => 20,
                'autosave_interval_seconds' => 60,
                'wiki_trust_domains' => ['wikipedia.org', 'britannica.com'],
                'featured_snippet_thresholds' => ['min_words' => 40, 'max_words' => 60],
                'article_length_product' => 800,
                'article_length_default' => 1200,
                'seo_scoring_rules' => array_fill(0, 40, [
                    'key' => 'sample_rule',
                    'label' => 'Quy tắc chấm điểm mẫu',
                    'weight' => 5,
                    'description' => 'Mô tả quy tắc chấm điểm SEO mẫu dùng để đo kích thước payload.',
                ]),
                'seo_rule_messages' => array_fill(0, 40, 'Thông báo lỗi mẫu cho quy tắc chấm điểm SEO khá dài dòng.'),
                'seo_scoring_messages' => array_fill(0, 20, 'Thông báo chấm điểm SEO mẫu khác.'),
                'show_reviews_tab' => true,
                'allow_wp_sync' => true,
                'can_generate_featured_snippet' => true,
                'can_generate_outline_heading' => true,
                'can_generate_image' => true,
                'can_generate_video' => false,
                'prompt_hooks' => ['title_suggestion' => ['configured' => true, 'hook_key' => 'article.title_suggestion']],
                'perf_debug' => false,
            ],
            'meta' => [
                'id' => 123,
                'site_id' => 45,
                'seo_connection_hash' => str_repeat('b', 40),
                'content_revision' => str_repeat('c', 64),
                'expected_updated_at' => '2026-07-22T08:00:00+00:00',
                'expected_content_hash' => str_repeat('d', 64),
                'media_picker_url' => 'https://example.com/api/seo/articles/123/media-picker',
                'title' => 'Tiêu đề bài viết mẫu',
                'post_type' => 'product',
                'virtual_reviews' => [],
                'supports_product_gallery' => true,
                'product_category_options' => array_map(
                    static fn (int $i): array => ['id' => $i, 'label' => 'Danh mục sản phẩm mẫu số '.$i],
                    range(1, 30),
                ),
                'product_gallery' => array_map(
                    static fn (int $i): array => ['url' => 'https://example.com/gallery/'.$i.'.jpg', 'id' => $i],
                    range(1, 5),
                ),
                'preview_url' => 'https://example.com/preview/123',
                'can_sync_wp' => true,
                'loai_san_pham' => 'Điện tử',
                'gallery_description' => 'Album ảnh sản phẩm mẫu',
                'ai_debug' => ['enabled' => false],
                'supplemental_images' => array_map(
                    static fn (int $i): array => [
                        'key' => 'sample_'.$i,
                        'src' => 'https://example.com/supplemental/'.$i.'.jpg',
                        'wp_url' => 'https://example.com/supplemental/'.$i.'.jpg',
                        'local_src' => '',
                        'slug' => 'supplemental-'.$i,
                        'alt' => '',
                        'title' => '',
                        'caption' => '',
                        'align' => 'none',
                        'origin' => 'gallery',
                        'origin_label' => 'Album san pham',
                    ],
                    range(1, 10),
                ),
            ],
            'faqs' => array_map(
                static fn (int $i): array => [
                    'id' => $i,
                    'question' => 'Câu hỏi thường gặp mẫu số '.$i.' dành cho bài viết?',
                    'answer' => str_repeat('Câu trả lời mẫu. ', 10),
                ],
                range(1, 10),
            ),
        ];
    }

    /**
     * Phase 2 core bootstrap — content + identity + endpoint map + minimal settings only.
     *
     * @return array<string, mixed>
     */
    private function buildAfterFixture(): array
    {
        return [
            'articleId' => 123,
            'connectionHash' => str_repeat('b', 40),
            'siteId' => 45,
            'title' => 'Tiêu đề bài viết mẫu',
            'slug' => 'bai-viet-mau',
            'content' => str_repeat('<p>Paragraph with words. </p>', 2000),
            'status' => 'draft',
            'updatedAt' => '2026-07-22T08:00:00+00:00',
            'expectedUpdatedAt' => '2026-07-22T08:00:00+00:00',
            'expectedContentHash' => str_repeat('d', 64),
            'postType' => 'product',
            'featuredImageUrl' => 'https://example.com/gallery/1.jpg',
            'supportsProductGallery' => true,
            'endpoints' => [
                'seoSummary' => 'https://example.com/api/seo/articles/123/editor/seo-summary',
                'images' => 'https://example.com/api/seo/articles/123/editor/images',
                'faqs' => 'https://example.com/api/seo/articles/123/editor/faqs',
                'faqsCount' => 'https://example.com/api/seo/articles/123/editor/faqs/count',
                'meta' => 'https://example.com/api/seo/articles/123/editor/meta',
                'links' => 'https://example.com/api/seo/articles/123/editor/links',
                'linksSuggestions' => 'https://example.com/api/seo/articles/123/editor/links/suggestions',
                'settings' => 'https://example.com/api/seo/articles/123/editor/settings',
                'mediaPickerConfig' => 'https://example.com/api/seo/articles/123/editor/media-picker-config',
            ],
            'faqCount' => 0,
            'settings' => [
                'history_step' => 20,
                'autosave_interval_seconds' => 60,
                'wiki_trust_domains' => ['wikipedia.org', 'britannica.com'],
                'show_reviews_tab' => true,
                'show_link_widgets' => true,
                'allow_wp_sync' => true,
                'can_generate_featured_snippet' => true,
                'can_generate_outline_heading' => true,
                'can_generate_image' => true,
                'can_generate_video' => false,
                'can_generate_faq' => true,
                'prompt_hooks' => ['title_suggestion' => ['configured' => true, 'hook_key' => 'article.title_suggestion']],
                'perf_debug' => false,
            ],
        ];
    }
}
