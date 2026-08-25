<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionHealthMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionPolicy;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionResult;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionService;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthRecorder;
use Omnichannel\Addons\Content\Filament\Pages\ArticleIndexHealth;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleOAuthService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 8B contracts — no live Google, no Search Performance coupling.
 */
final class GscUrlInspectionPhase8bContractTest extends TestCase
{
    public function test_verdict_mapping_is_conservative(): void
    {
        $mapper = new GscUrlInspectionHealthMapper;

        self::assertSame(ArticleIndexCheckStatus::Indexed, $mapper->map($this->makeInspectionResult('PASS')));
        self::assertSame(ArticleIndexCheckStatus::NotIndexed, $mapper->map($this->makeInspectionResult('FAIL')));
        self::assertSame(ArticleIndexCheckStatus::Unknown, $mapper->map($this->makeInspectionResult('PARTIAL')));
        self::assertSame(ArticleIndexCheckStatus::Unknown, $mapper->map($this->makeInspectionResult('NEUTRAL')));
        self::assertSame(ArticleIndexCheckStatus::Unknown, $mapper->map($this->makeInspectionResult('VERDICT_UNSPECIFIED')));
        self::assertSame(ArticleIndexCheckStatus::Unknown, $mapper->map($this->makeInspectionResult(null)));
        self::assertSame(ArticleIndexCheckStatus::Unknown, $mapper->map($this->makeInspectionResult('SOMETHING_NEW')));
    }

    public function test_mapper_never_emits_dropped(): void
    {
        $mapper = new GscUrlInspectionHealthMapper;
        foreach (['PASS', 'FAIL', 'PARTIAL', 'NEUTRAL', null, 'WEIRD'] as $verdict) {
            self::assertNotSame(
                'dropped',
                $mapper->map($this->makeInspectionResult($verdict))->value,
            );
        }
    }

    public function test_canonical_mismatch_is_diagnostic_only(): void
    {
        $result = new GscUrlInspectionResult(
            inspectionUrl: 'https://example.test/a/',
            propertyUri: 'sc-domain:example.test',
            verdict: 'PASS',
            coverageState: null,
            indexingState: null,
            pageFetchState: null,
            robotsTxtState: null,
            lastCrawlTime: '2026-08-23T10:00:00Z',
            googleCanonical: 'https://example.test/b/',
            userCanonical: 'https://example.test/a/',
        );

        self::assertTrue($result->canonicalMismatch());
        self::assertTrue($result->diagnostics()['canonical_mismatch'] ?? false);
        self::assertSame(ArticleIndexCheckStatus::Indexed, (new GscUrlInspectionHealthMapper)->map($result));
    }

    public function test_oauth_scope_already_covers_url_inspection(): void
    {
        self::assertStringContainsString('webmasters.readonly', GoogleSearchConsoleOAuthService::SCOPE);
    }

    public function test_inspection_service_uses_canonical_recorder(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(GscUrlInspectionService::class))->getFileName());
        self::assertStringContainsString('ArticleIndexHealthRecorder', $src);
        self::assertStringContainsString('GscUrlInspectionPolicy::sourceKey()', $src);
        self::assertSame('gsc_url_inspection', GscUrlInspectionPolicy::sourceKey());
        self::assertStringNotContainsString('seo_gsc_daily_metrics', $src);
        self::assertStringNotContainsString('GscOpportunityDetectionService', $src);
        self::assertStringNotContainsString('GscPlanningSignalNormalizer', $src);
    }

    public function test_batch_limit_is_conservative(): void
    {
        self::assertSame(25, GscUrlInspectionPolicy::DEFAULT_BATCH_LIMIT);
        self::assertSame(100, GscUrlInspectionPolicy::MAX_BATCH_LIMIT);
        self::assertSame(25, GscUrlInspectionPolicy::clampLimit(0));
        self::assertSame(100, GscUrlInspectionPolicy::clampLimit(5000));
        self::assertSame(10, GscUrlInspectionPolicy::clampLimit(10));
    }

    public function test_source_key_is_gsc_url_inspection_not_gsc(): void
    {
        self::assertSame('gsc_url_inspection', GscUrlInspectionPolicy::sourceKey());
    }

    public function test_ui_exposes_gsc_actions_and_keeps_manual(): void
    {
        $page = (string) file_get_contents((string) (new ReflectionClass(ArticleIndexHealth::class))->getFileName());
        self::assertStringContainsString('inspectWithGsc', $page);
        self::assertStringContainsString('inspectDueWithGsc', $page);
        self::assertStringContainsString('inspectSelectedWithGsc', $page);
        self::assertStringContainsString('markIndexed', $page);
        self::assertStringContainsString('ArticleIndexHealthRecorder', $page);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/article-index-health.blade.php'
        );
        self::assertStringContainsString('inspect_due_gsc', $blade);
        self::assertStringContainsString('inspect_gsc', $blade);
        self::assertStringContainsString('check_index', $blade);
        self::assertStringContainsString('markIndexed', $blade);
    }

    public function test_zero_ai_and_no_indexing_api(): void
    {
        $dir = dirname((string) (new ReflectionClass(GscUrlInspectionService::class))->getFileName());
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('openai', strtolower($src));
            self::assertStringNotContainsString('indexing.googleapis.com', $src);
            self::assertStringNotContainsString('site:', $src);
        }
    }

    public function test_endpoint_is_url_inspection_index_inspect(): void
    {
        $src = (string) file_get_contents(
            dirname((string) (new ReflectionClass(GscUrlInspectionService::class))->getFileName()).'/GscUrlInspectionClient.php'
        );
        self::assertStringContainsString('urlInspection/index:inspect', $src);
    }

    public function test_recorder_still_owns_dropped_transition(): void
    {
        $recorderSrc = (string) file_get_contents((string) (new ReflectionClass(ArticleIndexHealthRecorder::class))->getFileName());
        self::assertStringContainsString('gsc_url_inspection', $recorderSrc);
        self::assertStringContainsString('diagnostics', $recorderSrc);
        self::assertStringContainsString('deriveEffective', $recorderSrc);
    }

    private function makeInspectionResult(?string $verdict): GscUrlInspectionResult
    {
        return new GscUrlInspectionResult(
            inspectionUrl: 'https://example.test/a/',
            propertyUri: 'sc-domain:example.test',
            verdict: $verdict,
            coverageState: null,
            indexingState: null,
            pageFetchState: null,
            robotsTxtState: null,
            lastCrawlTime: null,
            googleCanonical: null,
            userCanonical: null,
        );
    }
}
