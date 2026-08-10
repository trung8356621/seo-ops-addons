<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPageMappingMethod;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageArticleMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class GscPageMappingTest extends TestCase
{
    private GscPageArticleMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GscPageArticleMapper(new GscPageNormalizationService(new SerpUrlNormalizationService));
    }

    public function test_exact_url_maps_canonical(): void
    {
        $mapped = $this->mapper->map('https://example.test/post', '1', [
            ['site_id' => '1', 'article_ref' => 'art_1', 'method' => 'exact_canonical', 'canonical_url' => 'https://example.test/post'],
        ]);

        self::assertSame('art_1', $mapped['article_ref']);
        self::assertSame(GscPageMappingMethod::ExactCanonical, $mapped['method']);
    }

    public function test_trailing_slash_normalized_to_same_url(): void
    {
        $mapped = $this->mapper->map('https://example.test/post/', '1', [
            ['site_id' => '1', 'article_ref' => 'art_1', 'method' => 'exact_canonical', 'canonical_url' => 'https://example.test/post'],
        ]);

        self::assertSame('art_1', $mapped['article_ref']);
    }

    public function test_ambiguous_candidates_not_auto_mapped(): void
    {
        $candidates = [
            ['site_id' => '1', 'article_ref' => 'art_a', 'method' => 'exact_canonical', 'canonical_url' => 'https://example.test/post'],
            ['site_id' => '1', 'article_ref' => 'art_b', 'method' => 'exact_canonical', 'canonical_url' => 'https://example.test/post'],
        ];

        $mapped = $this->mapper->map('https://example.test/post', '1', $candidates);
        self::assertSame(GscPageArticleMapper::ERROR_AMBIGUOUS, $mapped['error_code']);
        self::assertNull($mapped['article_ref']);
    }

    public function test_wrong_host_canonical_does_not_match(): void
    {
        $mapped = $this->mapper->map('https://example.test/post', '1', [
            ['site_id' => '1', 'article_ref' => 'art_evil', 'method' => 'exact_canonical', 'canonical_url' => 'https://evil.test/post'],
        ]);

        self::assertNull($mapped['article_ref']);
        self::assertSame(GscPageMappingMethod::Unmapped, $mapped['method']);
    }
}
