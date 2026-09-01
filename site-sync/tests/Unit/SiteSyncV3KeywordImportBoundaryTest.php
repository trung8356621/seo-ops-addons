<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\CanonicalKeywordReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\ProviderKeywordReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteSyncKeywordCandidateEvaluator;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncV3KeywordImportBoundaryTest extends TestCase
{
    public function test_href_never_promoted_to_keyword_candidate(): void
    {
        $evaluator = new SiteSyncKeywordCandidateEvaluator();
        $href = 'https://maybalotuixachgiare.com';

        $fromHref = $evaluator->evaluate($href, $href, SiteSyncKeywordCandidateEvaluator::CANDIDATE_HREF);
        self::assertFalse($fromHref['eligible']);
        self::assertSame('url_domain', $fromHref['phrase_kind']);
        self::assertSame('href_never_promoted_to_keyword', $fromHref['reason']);
    }

    public function test_url_shaped_anchor_classified_as_url_domain_not_seo(): void
    {
        $evaluator = new SiteSyncKeywordCandidateEvaluator();
        $url = 'https://maybalotuixachgiare.com';

        $result = $evaluator->evaluate($url, $url, SiteSyncKeywordCandidateEvaluator::CANDIDATE_ANCHOR);
        self::assertFalse($result['eligible']);
        self::assertFalse($result['is_seo_keyword']);
        self::assertSame(KeywordRuleClassifier::KIND_URL_DOMAIN, $result['phrase_kind']);
    }

    public function test_url_shaped_provider_keyword_not_eligible(): void
    {
        $evaluator = new SiteSyncKeywordCandidateEvaluator();
        $url = 'https://maybalotuixachgiare.com';

        $result = $evaluator->evaluate($url, $url, SiteSyncKeywordCandidateEvaluator::CANDIDATE_PROVIDER);
        self::assertFalse($result['eligible']);
        self::assertSame(KeywordRuleClassifier::KIND_URL_DOMAIN, $result['phrase_kind']);
    }

    public function test_valid_anchor_phrase_still_eligible(): void
    {
        $evaluator = new SiteSyncKeywordCandidateEvaluator();
        $phrase = 'balo học sinh giá rẻ';

        $result = $evaluator->evaluate($phrase, mb_strtolower($phrase), SiteSyncKeywordCandidateEvaluator::CANDIDATE_ANCHOR);
        self::assertTrue($result['eligible'], json_encode($result));
        self::assertNotSame(KeywordRuleClassifier::KIND_URL_DOMAIN, $result['phrase_kind']);
        self::assertNotSame(KeywordRuleClassifier::KIND_NOISE, $result['phrase_kind']);
    }

    public function test_noise_anchor_not_eligible(): void
    {
        $evaluator = new SiteSyncKeywordCandidateEvaluator();
        $result = $evaluator->evaluate('--- ///', '--- ///', SiteSyncKeywordCandidateEvaluator::CANDIDATE_ANCHOR);
        self::assertFalse($result['eligible']);
        self::assertSame(KeywordRuleClassifier::KIND_NOISE, $result['phrase_kind']);
    }

    public function test_importer_never_falls_back_href_to_phrase(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );

        self::assertStringContainsString('url_shaped_anchor_not_promoted', $src);
        self::assertStringContainsString('looksLikeUrlOrDomain', $src);
        self::assertStringNotContainsString("mb_substr(\$href, 0, 120)", $src);
        self::assertStringContainsString('empty_anchor_href_not_promoted', $src);
        self::assertStringContainsString('findOrAttachEligible', $src);
        self::assertStringContainsString('CANDIDATE_ANCHOR', $src);
        self::assertStringContainsString('CanonicalKeywordReconciler', $src);
    }

    public function test_canonical_reconciler_handles_unique_violation_by_refetch(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(CanonicalKeywordReconciler::class))->getFileName()
        );

        self::assertStringContainsString('keywords_phrase_unique', $src);
        self::assertStringContainsString('keyword_unique_reconciled', $src);
        self::assertStringContainsString('findByPhrase', $src);
        self::assertStringContainsString('SOURCE_MANUAL', $src);
        self::assertStringContainsString('mergeSourceMetadataIfAllowed', $src);
    }

    public function test_provider_reconciler_uses_candidate_gate_and_preserves_manual(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ProviderKeywordReconciler::class))->getFileName()
        );

        self::assertStringContainsString('CanonicalKeywordReconciler', $src);
        self::assertStringContainsString('CANDIDATE_PROVIDER', $src);
        self::assertStringContainsString('SOURCE_MANUAL', $src);
        self::assertStringContainsString('skipped_manual', $src);
    }

    public function test_domain_only_string_classified_url_domain(): void
    {
        $evaluator = new SiteSyncKeywordCandidateEvaluator();
        $result = $evaluator->evaluate(
            'maybalotuixachgiare.com',
            'maybalotuixachgiare.com',
            SiteSyncKeywordCandidateEvaluator::CANDIDATE_ANCHOR,
        );
        self::assertFalse($result['eligible']);
        self::assertSame(KeywordRuleClassifier::KIND_URL_DOMAIN, $result['phrase_kind']);
    }

    public function test_malformed_http_scheme_anchor_rejected(): void
    {
        $evaluator = new SiteSyncKeywordCandidateEvaluator();
        $result = $evaluator->evaluate(
            'http:maybalogiare.com',
            'http:maybalogiare.com',
            SiteSyncKeywordCandidateEvaluator::CANDIDATE_ANCHOR,
        );
        self::assertFalse($result['eligible']);
        self::assertTrue($evaluator->looksLikeUrlOrDomain('http:maybalogiare.com'));
        self::assertSame('url_or_domain_shaped', $result['reason']);
    }
}
