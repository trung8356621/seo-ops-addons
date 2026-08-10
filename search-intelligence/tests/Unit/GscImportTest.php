<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscFactHashService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscImportPreviewService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class GscImportTest extends TestCase
{
    private GscImportPreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GscImportPreviewService(
            new GscQueryNormalizationService(new KeywordNormalizationService),
            new GscPageNormalizationService(new SerpUrlNormalizationService),
            new GscFactHashService,
        );
    }

    public function test_csv_preview_parses_valid_rows(): void
    {
        $csv = <<<'CSV'
date,query,page,country,device,search_appearance,clicks,impressions,ctr,position
2026-07-01,dịch vụ seo,https://example.test/dich-vu-seo,vnm,desktop,,10,100,0.05,8.5
CSV;

        $preview = $this->service->preview($csv);
        self::assertCount(1, $preview->validRows);
        self::assertSame(0.1, $preview->validRows[0]['ctr']);
        self::assertSame('dịch vụ seo', $preview->validRows[0]['normalized_query']);
    }

    public function test_invalid_negative_metrics_rejected(): void
    {
        $csv = <<<'CSV'
date,query,page,country,device,search_appearance,clicks,impressions,ctr,position
2026-07-01,seo,https://example.test/a,vnm,desktop,,-1,10,0,5
CSV;

        $preview = $this->service->preview($csv);
        self::assertCount(0, $preview->validRows);
        self::assertSame('negative_metrics', $preview->invalidRows[0]['reason']);
    }

    public function test_ctr_recalculated_from_clicks_and_impressions(): void
    {
        $csv = <<<'CSV'
date,query,page,country,device,search_appearance,clicks,impressions,ctr,position
2026-07-01,seo,https://example.test/a,vnm,desktop,,25,100,99,5
CSV;

        $preview = $this->service->preview($csv);
        self::assertSame(0.25, $preview->validRows[0]['ctr']);
    }

    public function test_clicks_over_impressions_rejected(): void
    {
        $csv = <<<'CSV'
date,query,page,country,device,search_appearance,clicks,impressions,ctr,position
2026-07-01,seo,https://example.test/a,vnm,desktop,,150,100,1.5,4
CSV;

        $preview = $this->service->preview($csv);
        self::assertCount(0, $preview->validRows);
        self::assertSame('clicks_exceed_impressions', $preview->invalidRows[0]['reason']);
    }
}
