<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;

/**
 * Wrap/delegate SerpUrlNormalizationService cho GSC page URL.
 */
final class GscPageNormalizationService
{
    public const ALGORITHM_VERSION = '1.0.0';

    public function __construct(
        private readonly SerpUrlNormalizationService $urlNormalizer,
    ) {}

    /**
     * @return array{url: string, normalized_url: string, domain: string, normalized_domain: string, path: string}
     */
    public function normalize(string $url): array
    {
        $base = $this->urlNormalizer->normalize($url);
        $path = '/';
        if ($base['normalized_url'] !== '') {
            $parsed = parse_url($base['normalized_url']);
            $path = is_array($parsed) ? (string) ($parsed['path'] ?? '/') : '/';
            if ($path === '') {
                $path = '/';
            }
        }

        return array_merge($base, ['path' => $path]);
    }

    public function normalizeHost(string $host): string
    {
        return $this->urlNormalizer->normalizeHost($host);
    }

    public function identityHash(string $normalizedUrl): string
    {
        return hash('sha256', 'gsc-page:'.self::ALGORITHM_VERSION.':'.$normalizedUrl);
    }
}
