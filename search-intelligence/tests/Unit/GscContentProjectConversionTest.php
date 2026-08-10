<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscContentAction;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscContentProjectPreviewBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityContentProjectConverter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GscContentProjectConversionTest extends TestCase
{
    public function test_preview_builder_has_no_gallery_description(): void
    {
        $builder = new GscContentProjectPreviewBuilder;
        $item = $builder->build(
            ['action' => GscContentAction::Improve, 'reason_codes' => ['near_page_one'], 'article_ref' => 'art_1'],
            ['clicks' => 20, 'impressions' => 500, 'ctr' => 0.04, 'position' => 9.2],
            ['display_query' => 'dịch vụ seo', 'opportunities' => [['type' => 'near_page_one']]],
        );

        self::assertArrayHasKey('improve_description', $item);
        self::assertStringContainsString('dịch vụ seo', $item['improve_description']);
        self::assertStringContainsString('500', $item['improve_description']);
        self::assertArrayNotHasKey('gallery_description', $item);
    }

    public function test_opportunity_converter_unsets_gallery_description(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(GscOpportunityContentProjectConverter::class))->getFileName());
        self::assertStringContainsString("unset(\$attributes['gallery_description'])", $source);
        self::assertStringContainsString('GscContentProjectPreviewBuilder', $source);
        self::assertStringNotContainsString('gallery_description =', $source);
    }
}
