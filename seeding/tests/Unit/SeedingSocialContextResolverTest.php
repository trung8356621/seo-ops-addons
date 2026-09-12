<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use PHPUnit\Framework\TestCase;

final class SeedingSocialContextResolverTest extends TestCase
{
    private SeedingSocialContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SeedingSocialContextResolver();
    }

    public function test_a_text_source_returns_plain_content(): void
    {
        $payload = [
            'source_type' => 'text',
            'content' => 'Đầu tư một cái balo xài nguyên thời học sinh tính ra quá lời',
            'social' => 'threads',
            'quantity' => 3,
        ];

        $context = $this->resolver->resolve($payload);
        self::assertSame('Đầu tư một cái balo xài nguyên thời học sinh tính ra quá lời', $context);
    }

    public function test_b_url_source_with_preview_metadata_formats_plain_text(): void
    {
        $payload = [
            'source_type' => 'url',
            'url' => 'https://example.com/balo-chong-gu',
            'preview_title' => 'Balo chống gù lưng thế hệ mới',
            'preview_description' => 'Thiết kế công thái học nhẹ và bền bỉ',
            'preview_domain' => 'example.com',
            'social' => 'facebook',
            'quantity' => 3,
        ];

        $context = $this->resolver->resolve($payload);
        self::assertSame("Tiêu đề: Balo chống gù lưng thế hệ mới\nMô tả: Thiết kế công thái học nhẹ và bền bỉ", $context);
    }

    public function test_c_legacy_payload_fields_normalize_successfully(): void
    {
        $payload = [
            'full_text' => 'Nội dung từ trường legacy full_text',
            'count' => 3,
            'platform' => 'threads',
        ];

        $context = $this->resolver->resolve($payload);
        self::assertSame('Nội dung từ trường legacy full_text', $context);
    }

    public function test_d_social_platform_is_not_treated_as_source_type(): void
    {
        // When client mistakenly sends source_type="threads" with content
        $payload = [
            'source_type' => 'threads',
            'content' => 'Nội dung chia sẻ trên mạng xã hội',
            'social' => 'threads',
        ];

        $canonicalSource = $this->resolver->determineCanonicalSourceType($payload);
        self::assertSame('text', $canonicalSource);

        $context = $this->resolver->resolve($payload);
        self::assertSame('Nội dung chia sẻ trên mạng xã hội', $context);
    }

    public function test_e_manual_source_type_from_seeding_topic_normalizes_to_text(): void
    {
        // When topic card has source_type="manual"
        $payload = [
            'source_type' => 'manual',
            'content' => 'Chủ đề seeding tạo thủ công',
            'social' => 'threads',
        ];

        $canonicalSource = $this->resolver->determineCanonicalSourceType($payload);
        self::assertSame('text', $canonicalSource);

        $context = $this->resolver->resolve($payload);
        self::assertSame('Chủ đề seeding tạo thủ công', $context);
    }

    public function test_f_url_without_metadata_throws_controlled_validation_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Không tìm thấy tiêu đề hoặc mô tả cho liên kết này. Vui lòng nhập nội dung gợi ý.');

        $this->resolver->resolve([
            'source_type' => 'url',
            'url' => 'https://completely-unknown-domain-999.xyz/unknown-post',
        ]);
    }
}
