<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpEvidenceContentProjectPreviewAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SerpContentProjectPreviewTest extends TestCase
{
    public function test_preview_adapter_accepts_serp_evidence_fields(): void
    {
        $adapter = new SerpEvidenceContentProjectPreviewAdapter;
        $item = ['cluster_ref' => 'kwc_1', 'name' => 'Cluster A'];

        $merged = $adapter->apply($item, [
            'observed_primary_intent' => 'informational',
            'confidence' => 0.72,
            'content_gaps' => [['gap_type' => 'missing_question']],
        ]);

        self::assertArrayHasKey('serp_evidence', $merged);
        self::assertSame('informational', $merged['serp_evidence']['observed_primary_intent']);
        self::assertSame(0.72, $merged['serp_evidence']['confidence']);
    }

    public function test_keyword_converters_do_not_touch_gallery_description(): void
    {
        $clusterConverter = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-intelligence/src/Services/KeywordIntelligence/KeywordToContentProjectConverter.php',
        );
        self::assertStringNotContainsString('gallery_description', $clusterConverter);

        $mapConverter = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-intelligence/src/Services/KeywordIntelligence/KeywordTopicalMapToContentProjectConverter.php',
        );
        self::assertStringContainsString("unset(\$attributes['gallery_description'])", $mapConverter);
        self::assertStringNotContainsString('gallery_description =', $mapConverter);
    }

    public function test_serp_preview_adapter_has_no_gallery_description_leakage(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SerpEvidenceContentProjectPreviewAdapter::class))->getFileName());
        self::assertStringNotContainsString('gallery_description', $source);
        self::assertStringContainsString('serp_evidence', $source);
    }
}
