<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorHtmlSanitizeService;
use PHPUnit\Framework\TestCase;

final class ArticleEditorHtmlSanitizeServiceTest extends TestCase
{
    public function test_strip_ai_utility_classes_removes_claude_and_tailwind(): void
    {
        $html = <<<'HTML'
<p class="font-claude-response-body break-words whitespace-normal leading-[1.7]">Đoạn mở.</p>
<ul class="[li_&]:mb-0 list-disc flex flex-col gap-1 pl-8 mb-3">
<li class="font-claude-response-body whitespace-normal break-words pl-2">Mục 1</li>
</ul>
<blockquote class="ml-2 border-l-4 border-border-300/10 pl-4 text-300">Trích dẫn</blockquote>
<h2 class="text-300 mt-3 mb-1 text-[1.125rem] font-bold">Tiêu đề</h2>
<p class="aligncenter">Giữ align WordPress</p>
HTML;

        $clean = app(ArticleEditorHtmlSanitizeService::class)->stripAiUtilityClasses($html);

        $this->assertStringNotContainsString('font-claude', $clean);
        $this->assertStringNotContainsString('break-words', $clean);
        $this->assertStringNotContainsString('flex-col', $clean);
        $this->assertStringNotContainsString('[li_&]', $clean);
        $this->assertStringNotContainsString('border-border-300', $clean);
        $this->assertStringContainsString('aligncenter', $clean);
        $this->assertStringContainsString('Đoạn mở.', $clean);
        $this->assertStringContainsString('Mục 1', $clean);
    }

    public function test_strip_transient_also_merges_split_inline_links(): void
    {
        $html = '<p><a href="https://example.com/x">công nghệ</a><strong><a href="https://example.com/x">DWR</a></strong></p>';
        $clean = app(ArticleEditorHtmlSanitizeService::class)->stripTransientEditorMarkup($html);

        $this->assertSame(1, substr_count(strtolower($clean), '<a '));
        $this->assertStringContainsString('<strong>DWR</strong>', $clean);
    }
}
