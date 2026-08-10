<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPageMappingMethod;

/**
 * Map GSC page URL → article_ref.
 * Precedence: manual > exact_canonical > exact_wp > slug > unmapped.
 * Không str_contains host match, không cross-site.
 */
final class GscPageArticleMapper
{
    public const ALGORITHM_VERSION = '1.0.0';

    public const ERROR_AMBIGUOUS = 'gsc.page_mapping_ambiguous';

    public function __construct(
        private readonly GscPageNormalizationService $pageNormalizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates  site_id, article_ref, method, normalized_url?, canonical_url?, wp_url?, slug?, manual?
     * @return array{article_ref: ?string, method: GscPageMappingMethod, confidence: float, error_code: ?string, candidates: list<string>}
     */
    public function map(string $pageUrl, string $siteId, array $candidates): array
    {
        $normalized = $this->pageNormalizer->normalize($pageUrl);
        if ($normalized['normalized_url'] === '') {
            return $this->result(null, GscPageMappingMethod::Unmapped, 0.0, null, []);
        }

        $pagePath = trim((string) ($normalized['path'] ?? '/'), '/');

        $siteCandidates = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => (string) ($c['site_id'] ?? '') === $siteId,
        ));

        if ($siteCandidates === []) {
            return $this->result(null, GscPageMappingMethod::Unmapped, 0.0, null, []);
        }

        $byMethod = [
            GscPageMappingMethod::Manual->value => [],
            GscPageMappingMethod::ExactCanonical->value => [],
            GscPageMappingMethod::ExactWp->value => [],
            GscPageMappingMethod::Slug->value => [],
        ];

        foreach ($siteCandidates as $candidate) {
            $method = (string) ($candidate['method'] ?? '');
            if ($method === GscPageMappingMethod::Manual->value && ($candidate['manual'] ?? false) === true) {
                $target = $this->normalizeCandidateUrl((string) ($candidate['normalized_url'] ?? $candidate['canonical_url'] ?? ''));
                if ($target === $normalized['normalized_url']) {
                    $byMethod[GscPageMappingMethod::Manual->value][] = $candidate;
                }
                continue;
            }

            $canonical = $this->normalizeCandidateUrl((string) ($candidate['canonical_url'] ?? ''));
            if ($canonical !== '' && $canonical === $normalized['normalized_url']) {
                $byMethod[GscPageMappingMethod::ExactCanonical->value][] = $candidate;
            }

            $wpUrl = $this->normalizeCandidateUrl((string) ($candidate['wp_url'] ?? ''));
            if ($wpUrl !== '' && $wpUrl === $normalized['normalized_url']) {
                $byMethod[GscPageMappingMethod::ExactWp->value][] = $candidate;
            }

            $slug = trim((string) ($candidate['slug'] ?? ''), '/');
            if ($slug !== '' && $pagePath !== '' && $slug === $pagePath) {
                $byMethod[GscPageMappingMethod::Slug->value][] = $candidate;
            }
        }

        foreach ([
            GscPageMappingMethod::Manual,
            GscPageMappingMethod::ExactCanonical,
            GscPageMappingMethod::ExactWp,
            GscPageMappingMethod::Slug,
        ] as $method) {
            $matches = $byMethod[$method->value] ?? [];
            if ($matches === []) {
                continue;
            }

            $refs = array_values(array_unique(array_map(
                static fn (array $c): string => (string) ($c['article_ref'] ?? ''),
                $matches,
            )));
            $refs = array_values(array_filter($refs, static fn (string $r): bool => $r !== ''));

            if (count($refs) > 1) {
                return $this->result(null, GscPageMappingMethod::Unmapped, 0.0, self::ERROR_AMBIGUOUS, $refs);
            }

            $confidence = match ($method) {
                GscPageMappingMethod::Manual => 1.0,
                GscPageMappingMethod::ExactCanonical => 0.95,
                GscPageMappingMethod::ExactWp => 0.9,
                GscPageMappingMethod::Slug => 0.75,
                default => 0.5,
            };

            return $this->result($refs[0] ?? null, $method, $confidence, null, $refs);
        }

        return $this->result(null, GscPageMappingMethod::Unmapped, 0.0, null, []);
    }

    private function normalizeCandidateUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        return $this->pageNormalizer->normalize($url)['normalized_url'];
    }

    /**
     * @param  list<string>  $candidateRefs
     * @return array{article_ref: ?string, method: GscPageMappingMethod, confidence: float, error_code: ?string, candidates: list<string>}
     */
    private function result(?string $articleRef, GscPageMappingMethod $method, float $confidence, ?string $errorCode, array $candidateRefs): array
    {
        return [
            'article_ref' => $articleRef,
            'method' => $method,
            'confidence' => $confidence,
            'error_code' => $errorCode,
            'candidates' => $candidateRefs,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }
}
