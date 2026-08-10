<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpContentGapType;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpPageType;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpResultType;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpSnapshotStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpQueryNormalizationService;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SerpIntelligenceFoundationTest extends TestCase
{
    public function test_serp_public_ref_roundtrip_and_rejects_numeric(): void
    {
        $pairs = [
            ['serpQuery', 'decodeSerpQuery', 'resolveSerpQueryIdStrict', 'srpq_', 11],
            ['serpSnapshot', 'decodeSerpSnapshot', 'resolveSerpSnapshotIdStrict', 'srps_', 12],
            ['serpResult', 'decodeSerpResult', 'resolveSerpResultIdStrict', 'srpr_', 13],
            ['serpFeature', 'decodeSerpFeature', 'resolveSerpFeatureIdStrict', 'srpf_', 14],
            ['serpPageEvidence', 'decodeSerpPageEvidence', 'resolveSerpPageEvidenceIdStrict', 'srpe_', 15],
            ['serpClusterEvidence', 'decodeSerpClusterEvidence', 'resolveSerpClusterEvidenceIdStrict', 'srpc_', 16],
            ['serpContentGap', 'decodeSerpContentGap', 'resolveSerpContentGapIdStrict', 'srpg_', 17],
        ];

        foreach ($pairs as [$encode, $decode, $resolve, $prefix, $id]) {
            $ref = KeywordIntelligencePublicRef::{$encode}($id);
            self::assertStringStartsWith($prefix, $ref);
            self::assertSame($id, KeywordIntelligencePublicRef::{$decode}($ref));
            self::assertSame($id, KeywordIntelligencePublicRef::{$resolve}($ref));
        }

        $this->expectException(\InvalidArgumentException::class);
        KeywordIntelligencePublicRef::resolveSerpQueryIdStrict('11');
    }

    public function test_mobile_and_desktop_produce_different_scope_keys(): void
    {
        $normalizer = new SerpQueryNormalizationService;
        $base = ['query' => 'dich vu seo', 'language' => 'vi', 'country' => 'VN', 'provider' => 'manual_import'];

        $desktopScope = $normalizer->normalizeScope(array_merge($base, ['device' => 'desktop']));
        $mobileScope = $normalizer->normalizeScope(array_merge($base, ['device' => 'mobile']));

        self::assertSame('desktop', $desktopScope['device']);
        self::assertSame('mobile', $mobileScope['device']);

        $desktopKey = $this->scopeKeyFromScope($desktopScope);
        $mobileKey = $this->scopeKeyFromScope($mobileScope);

        self::assertNotSame($desktopKey, $mobileKey);
        self::assertSame('desktop', $desktopKey['device']);
        self::assertSame('mobile', $mobileKey['device']);
    }

    public function test_snapshot_immutability_via_assert_mutable_without_db(): void
    {
        $pending = new SeoSerpSnapshot;
        $pending->forceFill(['status' => SerpSnapshotStatus::Pending]);
        $pending->syncOriginal();
        $pending->assertMutable();

        $completed = new SeoSerpSnapshot;
        $completed->forceFill(['status' => SerpSnapshotStatus::Completed]);
        $completed->syncOriginal();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');
        $completed->assertMutable();
    }

    public function test_enum_values_match_spec(): void
    {
        self::assertSame('organic', SerpResultType::Organic->value);
        self::assertSame('featured_snippet', SerpResultType::FeaturedSnippet->value);
        self::assertSame('local_pack', SerpResultType::LocalPack->value);
        self::assertSame('other', SerpResultType::Other->value);

        self::assertSame('article', SerpPageType::Article->value);
        self::assertSame('service', SerpPageType::Service->value);
        self::assertSame('local_landing', SerpPageType::LocalLanding->value);
        self::assertSame('comparison', SerpPageType::Comparison->value);

        self::assertSame('missing_question', SerpContentGapType::MissingQuestion->value);
        self::assertSame('missing_schema', SerpContentGapType::MissingSchema->value);
        self::assertSame('missing_heading', SerpContentGapType::MissingHeading->value);
        self::assertSame('weak_coverage', SerpContentGapType::WeakCoverage->value);
    }

    public function test_serp_application_handlers_avoid_vendor_sdk_imports(): void
    {
        $dir = ProjectRoot::addonsPath().'/search-intelligence/src/Services/SerpIntelligence/Application/Handlers';
        self::assertDirectoryExists($dir);

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            if (basename($file) === 'AbstractSerpIntelligenceHandler.php') {
                continue;
            }

            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('SerperDev', $source, basename($file));
            self::assertStringNotContainsString('SerpApi\\', $source, basename($file));
            self::assertStringNotContainsString('SearchApi\\', $source, basename($file));
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function scopeKeyFromScope(array $scope): array
    {
        $request = new SerpQueryRequest(
            tenantRef: $scope['tenant'] ?? null,
            siteRef: $scope['site'] ?? null,
            query: (string) ($scope['display_query'] ?? ''),
            displayQuery: (string) ($scope['display_query'] ?? ''),
            normalizedQuery: (string) ($scope['normalized_query'] ?? ''),
            language: (string) ($scope['language'] ?? 'vi'),
            country: (string) ($scope['country'] ?? 'VN'),
            location: $scope['location'] ?? null,
            device: (string) ($scope['device'] ?? 'desktop'),
            searchEngine: (string) ($scope['search_engine'] ?? 'google'),
            providerKey: (string) ($scope['provider'] ?? ''),
        );

        return $request->scopeKey();
    }
}
