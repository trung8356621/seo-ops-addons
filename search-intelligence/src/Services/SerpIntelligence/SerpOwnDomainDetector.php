<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * Own-domain match theo hostname đã normalize — không dùng str_contains.
 */
final class SerpOwnDomainDetector
{
    public function __construct(
        private readonly SerpUrlNormalizationService $urlNormalizer,
    ) {}

    /**
     * @param  list<string>  $siteDomains  Primary + alias domains (raw or normalized)
     */
    public function isOwnDomain(string $normalizedDomain, array $siteDomains): bool
    {
        $candidate = $this->urlNormalizer->normalizeHost($normalizedDomain);
        if ($candidate === '') {
            return false;
        }

        foreach ($siteDomains as $siteDomain) {
            if (! is_string($siteDomain) || trim($siteDomain) === '') {
                continue;
            }

            $own = $this->urlNormalizer->normalizeHost($siteDomain);
            if ($own === '') {
                continue;
            }

            if ($this->hostnameMatches($candidate, $own)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact hoặc subdomain hợp lệ — chặn suffix giả (example.com.evil.com).
     */
    private function hostnameMatches(string $candidate, string $own): bool
    {
        if ($candidate === $own) {
            return true;
        }

        if (! str_ends_with($candidate, '.'.$own)) {
            return false;
        }

        $prefix = mb_substr($candidate, 0, mb_strlen($candidate, 'UTF-8') - mb_strlen('.'.$own, 'UTF-8'), 'UTF-8');
        if ($prefix === '') {
            return false;
        }

        // Chặn label chứa dấu chấm giả lập TLD nội bộ (example.com.attacker.tld).
        if (str_contains($prefix, '.'.$own)) {
            return false;
        }

        $maxDepth = $this->configInt('own_domain.max_subdomain_depth', 5);
        $labels = explode('.', $prefix);

        return count($labels) <= $maxDepth && $labels !== [''];
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (int) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
