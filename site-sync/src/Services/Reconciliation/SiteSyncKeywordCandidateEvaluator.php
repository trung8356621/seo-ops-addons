<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;

/**
 * Distinguishes SEO keyword candidates from link/URL inventory strings.
 * Uses existing KeywordRuleClassifier taxonomy (url_domain / noise / …).
 */
final class SiteSyncKeywordCandidateEvaluator
{
    public const CANDIDATE_ANCHOR = 'anchor_text';

    public const CANDIDATE_PROVIDER = 'provider_keyword';

    public const CANDIDATE_HREF = 'href';

    public function __construct(
        private readonly ?KeywordRuleClassifier $classifier = null,
    ) {}

    /**
     * @return array{
     *     eligible: bool,
     *     phrase_kind: string,
     *     is_seo_keyword: bool,
     *     is_anchor_candidate: bool,
     *     reason: string
     * }
     */
    public function evaluate(string $raw, string $normalized, string $candidateType): array
    {
        if ($candidateType === self::CANDIDATE_HREF) {
            return [
                'eligible' => false,
                'phrase_kind' => KeywordRuleClassifier::KIND_URL_DOMAIN,
                'is_seo_keyword' => false,
                'is_anchor_candidate' => false,
                'reason' => 'href_never_promoted_to_keyword',
            ];
        }

        if ($normalized === '' && trim($raw) === '') {
            return [
                'eligible' => false,
                'phrase_kind' => KeywordRuleClassifier::KIND_NOISE,
                'is_seo_keyword' => false,
                'is_anchor_candidate' => false,
                'reason' => 'empty_phrase',
            ];
        }

        // Hard reject before classifier — URL/domain inventory must never become SEO keywords.
        if ($this->looksLikeUrlOrDomain($raw) || $this->looksLikeUrlOrDomain($normalized)) {
            return [
                'eligible' => false,
                'phrase_kind' => KeywordRuleClassifier::KIND_URL_DOMAIN,
                'is_seo_keyword' => false,
                'is_anchor_candidate' => false,
                'reason' => 'url_or_domain_shaped',
            ];
        }

        $classifier = $this->classifier ?? (class_exists(KeywordRuleClassifier::class)
            ? new KeywordRuleClassifier()
            : null);

        if ($classifier === null) {
            return $this->fallbackEvaluate($raw, $normalized, $candidateType);
        }

        $sourceKind = match ($candidateType) {
            self::CANDIDATE_ANCHOR => KeywordSourceNormalizer::ANCHOR_TEXT,
            self::CANDIDATE_PROVIDER => KeywordSourceNormalizer::SITE_SYNC,
            default => KeywordSourceNormalizer::OTHER,
        };

        $classified = $classifier->classify($raw !== '' ? $raw : $normalized, $normalized, [
            'source_kind' => $sourceKind,
            'skip_segments' => true,
        ]);

        $kind = (string) ($classified['phrase_kind'] ?? KeywordRuleClassifier::KIND_NOISE);
        $isSeo = (bool) ($classified['is_seo_keyword'] ?? false);
        $isAnchor = (bool) ($classified['is_anchor_candidate'] ?? false);

        if (in_array($kind, [KeywordRuleClassifier::KIND_URL_DOMAIN, KeywordRuleClassifier::KIND_NOISE], true)) {
            return [
                'eligible' => false,
                'phrase_kind' => $kind,
                'is_seo_keyword' => false,
                'is_anchor_candidate' => false,
                'reason' => 'classified_'.$kind,
            ];
        }

        $eligible = match ($candidateType) {
            self::CANDIDATE_ANCHOR => $isSeo || $isAnchor,
            self::CANDIDATE_PROVIDER => $isSeo,
            default => false,
        };

        return [
            'eligible' => $eligible,
            'phrase_kind' => $kind,
            'is_seo_keyword' => $isSeo,
            'is_anchor_candidate' => $isAnchor,
            'reason' => $eligible ? 'eligible' : 'not_seo_keyword_candidate',
        ];
    }

    public function looksLikeUrlOrDomain(string $value): bool
    {
        $probe = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($probe === '') {
            return false;
        }

        // Strip common OCR/copy junk around URLs (":https://…", "https://… .").
        $probe = preg_replace('/^[:\/*\s]+/u', '', $probe) ?? $probe;
        $probe = preg_replace('/[\s.]+$/u', '', $probe) ?? $probe;
        $probe = trim($probe);
        if ($probe === '') {
            return false;
        }

        if (preg_match('#https?://#i', $probe) === 1) {
            return true;
        }
        // Malformed scheme without slashes (e.g. "http:example.com").
        if (preg_match('#^https?:#i', $probe) === 1) {
            return true;
        }
        if (preg_match('#^www\.#i', $probe) === 1) {
            return true;
        }
        // Bare domain / host path (no spaces).
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(\/[\S]*)?$/i', $probe) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array{
     *     eligible: bool,
     *     phrase_kind: string,
     *     is_seo_keyword: bool,
     *     is_anchor_candidate: bool,
     *     reason: string
     * }
     */
    private function fallbackEvaluate(string $raw, string $normalized, string $candidateType): array
    {
        $probe = $raw !== '' ? $raw : $normalized;
        if ($this->looksLikeUrlOrDomain($probe)) {
            return [
                'eligible' => false,
                'phrase_kind' => 'url_domain',
                'is_seo_keyword' => false,
                'is_anchor_candidate' => false,
                'reason' => 'fallback_url_shaped',
            ];
        }

        $eligible = $normalized !== '';

        return [
            'eligible' => $eligible,
            'phrase_kind' => $eligible ? 'keyword_phrase' : 'noise',
            'is_seo_keyword' => $eligible,
            'is_anchor_candidate' => $eligible && $candidateType === self::CANDIDATE_ANCHOR,
            'reason' => $eligible ? 'fallback_eligible' : 'empty_phrase',
        ];
    }
}
