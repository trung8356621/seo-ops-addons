<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

/**
 * Normalized Google URL Inspection result (no raw nested Google blob).
 */
final class GscUrlInspectionResult
{
    /**
     * @param  list<string>  $sitemaps
     * @param  list<string>  $referringUrls
     * @param  array<string, mixed>  $rawIndexStatus  sanitized subset only
     */
    public function __construct(
        public readonly string $inspectionUrl,
        public readonly string $propertyUri,
        public readonly ?string $verdict,
        public readonly ?string $coverageState,
        public readonly ?string $indexingState,
        public readonly ?string $pageFetchState,
        public readonly ?string $robotsTxtState,
        public readonly ?string $lastCrawlTime,
        public readonly ?string $googleCanonical,
        public readonly ?string $userCanonical,
        public readonly array $sitemaps = [],
        public readonly array $referringUrls = [],
        public readonly array $rawIndexStatus = [],
    ) {}

    public function canonicalMismatch(): bool
    {
        $google = trim((string) ($this->googleCanonical ?? ''));
        $user = trim((string) ($this->userCanonical ?? ''));
        if ($google === '' || $user === '') {
            return false;
        }

        return rtrim($google, '/') !== rtrim($user, '/');
    }

    /**
     * Compact diagnostics for Index Health history.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return array_filter([
            'verdict' => $this->verdict,
            'coverage_state' => $this->coverageState,
            'indexing_state' => $this->indexingState,
            'page_fetch_state' => $this->pageFetchState,
            'robots_txt_state' => $this->robotsTxtState,
            'last_crawl_time' => $this->lastCrawlTime,
            'google_canonical' => $this->googleCanonical,
            'user_canonical' => $this->userCanonical,
            'canonical_mismatch' => $this->canonicalMismatch() ? true : null,
            'sitemaps' => $this->sitemaps !== [] ? array_slice($this->sitemaps, 0, 5) : null,
            'referring_urls' => $this->referringUrls !== [] ? array_slice($this->referringUrls, 0, 5) : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }
}
