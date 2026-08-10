<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryKeywordMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;
use PHPUnit\Framework\TestCase;

final class GscQueryMappingTest extends TestCase
{
    private GscQueryKeywordMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GscQueryKeywordMapper(new GscQueryNormalizationService(new KeywordNormalizationService));
    }

    public function test_exact_normalized_match_maps_keyword(): void
    {
        $mapped = $this->mapper->map('dịch vụ seo', '1', [
            ['site_id' => '1', 'keyword_ref' => 'kw_10', 'normalized' => 'dịch vụ seo'],
        ]);

        self::assertSame('kw_10', $mapped['keyword_ref']);
        self::assertSame('exact', $mapped['match_type']);
    }

    public function test_near_intent_mismatch_rejects_mapping(): void
    {
        $mapped = $this->mapper->map('dịch vụ seo', '1', [
            ['site_id' => '1', 'keyword_ref' => 'kw_11', 'normalized' => 'seo là gì'],
        ]);

        self::assertNull($mapped['keyword_ref']);
        self::assertSame('unmapped', $mapped['match_type']);
    }

    public function test_manual_mapping_preserved(): void
    {
        $mapped = $this->mapper->map('dịch vụ seo', '1', [
            ['site_id' => '1', 'keyword_ref' => 'kw_manual', 'normalized' => 'dịch vụ seo', 'manual' => true],
        ]);

        self::assertSame('kw_manual', $mapped['keyword_ref']);
        self::assertSame('manual', $mapped['match_type']);
        self::assertTrue($mapped['preserved_manual']);
    }
}
