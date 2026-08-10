<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpResultType;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\ManualImportSerpProvider;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpResultClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class SerpImportTest extends TestCase
{
    private ManualImportSerpProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new ManualImportSerpProvider(new SerpUrlNormalizationService, new SerpResultClassifier);
    }

    public function test_preview_json_valid_rows(): void
    {
        $payload = json_encode([
            'results' => [
                ['position' => 1, 'url' => 'https://Example.COM/page?utm_source=x', 'title' => 'A', 'type' => 'organic'],
                ['position' => 2, 'url' => 'https://example.com/other', 'title' => 'B', 'type' => 'organic'],
            ],
        ], JSON_THROW_ON_ERROR);

        $preview = $this->provider->preview($payload, 'json');

        self::assertCount(2, $preview->validRows);
        self::assertSame(2, $preview->summary['valid']);
        self::assertSame('organic', $preview->validRows[0]['canonical_type']);
    }

    public function test_preview_csv_valid_rows(): void
    {
        $csv = "position,url,title,type\n1,https://site.test/a,Title A,organic\n2,https://site.test/b,Title B,organic\n";

        $preview = $this->provider->preview($csv, 'csv');

        self::assertCount(2, $preview->validRows);
        self::assertSame(2, $preview->summary['valid']);
    }

    public function test_invalid_rows_and_missing_url(): void
    {
        $payload = json_encode([
            ['title' => 'no url'],
            'not-an-array',
            ['url' => 'https://valid.test/x', 'type' => 'organic'],
        ], JSON_THROW_ON_ERROR);

        $preview = $this->provider->preview($payload, 'json');

        self::assertCount(1, $preview->validRows);
        self::assertSame(1, $preview->summary['missing_urls']);
        self::assertSame(0, $preview->summary['invalid']);
    }

    public function test_unknown_provider_type_maps_to_other(): void
    {
        $payload = json_encode([
            ['url' => 'https://example.com/x', 'type' => 'totally_unknown_widget'],
        ], JSON_THROW_ON_ERROR);

        $preview = $this->provider->preview($payload, 'json');

        self::assertSame(SerpResultType::Other->value, $preview->validRows[0]['canonical_type']);
        self::assertSame(1, $preview->summary['unknown_types']);
    }
}
