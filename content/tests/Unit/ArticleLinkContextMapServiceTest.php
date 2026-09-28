<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleLinkContextMapService;
use Omnichannel\Addons\SearchFoundation\Support\KeywordSyncIsolation;
use Tests\TestCase;

final class ArticleLinkContextMapServiceTest extends TestCase
{
    public function test_extract_anchors_with_context_includes_surrounding_text(): void
    {
        $service = app(ArticleLinkContextMapService::class);

        $html = '<p>Trước anchor này có nội dung dài để test context. '
            .'<a href="/san-pham-a">sản phẩm A</a> '
            .'sau anchor cũng có thêm văn bản mô tả.</p>';

        $anchors = $service->extractAnchorsWithContext($html);

        $this->assertCount(1, $anchors);
        $this->assertSame('sản phẩm A', $anchors[0]['anchor_text']);
        $this->assertSame('/san-pham-a', $anchors[0]['href']);
        $this->assertNotNull($anchors[0]['context_before']);
        $this->assertNotNull($anchors[0]['context_after']);
    }

    public function test_automatic_keyword_sync_is_enabled_for_content_and_link_list(): void
    {
        $this->assertTrue(KeywordSyncIsolation::allowsAutomaticContentSync());
        $this->assertTrue(KeywordSyncIsolation::allowsDomainLinkListSync());
        $this->assertFalse(KeywordSyncIsolation::allowsContentKeywordPersistence());
        $this->assertTrue(KeywordSyncIsolation::allowsDomainResync());
    }

    public function test_domain_resync_context_enables_keyword_persistence_gate(): void
    {
        KeywordSyncIsolation::runWithinDomainResync(function (): void {
            $this->assertTrue(KeywordSyncIsolation::allowsContentKeywordPersistence());
        });

        $this->assertFalse(KeywordSyncIsolation::allowsContentKeywordPersistence());
    }

    public function test_extract_anchors_includes_contact_and_social_links(): void
    {
        $service = app(ArticleLinkContextMapService::class);

        $html = '<p>Liên hệ qua <a href="tel:0901234567">090 123 4567</a> '
            .'hoặc gửi thư đến <a href="mailto:support@example.com">email hỗ trợ</a> '
            .'hoặc nhắn tin qua <a href="https://zalo.me/0901234567">Zalo</a>.</p>';

        $anchors = $service->extractAnchorsWithContext($html);

        $this->assertCount(3, $anchors);
        $this->assertSame('tel:0901234567', $anchors[0]['href']);
        $this->assertSame('090 123 4567', $anchors[0]['anchor_text']);
        $this->assertSame('mailto:support@example.com', $anchors[1]['href']);
        $this->assertSame('email hỗ trợ', $anchors[1]['anchor_text']);
        $this->assertSame('https://zalo.me/0901234567', $anchors[2]['href']);
        $this->assertSame('Zalo', $anchors[2]['anchor_text']);
    }

    public function test_extract_anchors_filters_out_unusable_schemes(): void
    {
        $service = app(ArticleLinkContextMapService::class);

        $html = '<p><a href="javascript:void(0)">Click me</a> '
            .'<a href="data:text/plain;base64,SGVsbG8=">Data link</a> '
            .'<a href="#section-1">Hash link</a></p>';

        $anchors = $service->extractAnchorsWithContext($html);

        $this->assertCount(0, $anchors);
    }
}

