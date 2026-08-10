<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoToolsService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeoToolsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('wp_options')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_02_16_100000_create_wp_options_table.php',
            ]);
        }
    }

    public function test_markdown_to_html_converts_basic_content(): void
    {
        $service = app(SeoToolsService::class);

        $html = $service->markdownToHtml("## Tiêu đề\n\nĐoạn nội dung.");

        $this->assertStringContainsString('<h2>', $html);
        $this->assertStringContainsString('Tiêu đề', $html);
        $this->assertStringContainsString('Đoạn nội dung', $html);
    }

    public function test_html_to_markdown_converts_heading_and_paragraph(): void
    {
        $service = app(SeoToolsService::class);

        $markdown = $service->htmlToMarkdown('<h2>Tiêu đề</h2><p>Đoạn mở đầu.</p>');

        $this->assertStringContainsString('## Tiêu đề', $markdown);
        $this->assertStringContainsString('Đoạn mở đầu', $markdown);
    }

    public function test_markdown_to_faq_extracts_question_rows(): void
    {
        $service = app(SeoToolsService::class);

        $markdown = <<<'MD'
## FAQ

**Câu hỏi 1?**
Trả lời cho câu hỏi 1.

**Câu hỏi 2?**
Trả lời cho câu hỏi 2.
MD;

        $faqs = $service->markdownToFaq($markdown);

        $this->assertNotEmpty($faqs);
        $this->assertArrayHasKey('question', $faqs[0]);
        $this->assertArrayHasKey('answer', $faqs[0]);
    }
}
