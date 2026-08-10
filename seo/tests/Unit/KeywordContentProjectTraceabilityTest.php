<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordContentProjectLink;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordProjectConversion;
use PHPUnit\Framework\TestCase;

final class KeywordContentProjectTraceabilityTest extends TestCase
{
    public function test_conversion_and_link_models_target_omi_seo_ai(): void
    {
        $conversion = new SeoKeywordProjectConversion;
        $link = new SeoKeywordContentProjectLink;

        self::assertSame('omi_seo_ai', $conversion->getConnectionName());
        self::assertSame('seo_keyword_project_conversions', $conversion->getTable());
        self::assertSame('omi_seo_ai', $link->getConnectionName());
        self::assertSame('seo_keyword_content_project_links', $link->getTable());
    }

    public function test_link_casts_include_trace_ids(): void
    {
        $casts = (new SeoKeywordContentProjectLink)->getCasts();
        foreach (['workspace_id', 'topical_map_version_id', 'cluster_id', 'keyword_id', 'conversion_id'] as $key) {
            self::assertArrayHasKey($key, $casts);
        }
    }
}
