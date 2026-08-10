<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

final class GoogleSearchConsoleDomainMatcherService
{
    /**
     * @return array{status: 'matched', property_url: string}|array{status: 'ambiguous', candidates: list<string>}|null
     */
    public function findBestPropertyForSite(string $siteDomain, array $properties): ?array
    {
        $normalizedSite = $this->normalizeHost($siteDomain);
        if ($normalizedSite === '') {
            return null;
        }

        $candidates = [];

        foreach ($properties as $propertyUrl) {
            if (! is_string($propertyUrl) || trim($propertyUrl) === '') {
                continue;
            }

            $propertyHost = $this->normalizeHost($propertyUrl);
            if ($propertyHost === '' || $propertyHost !== $normalizedSite) {
                continue;
            }

            $candidates[] = [
                'host' => $propertyHost,
                'priority' => $this->propertyPriority($propertyUrl),
                'property_url' => $propertyUrl,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        $bestPriority = min(array_column($candidates, 'priority'));
        $bestCandidates = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['priority'] === $bestPriority,
        ));

        if (count($bestCandidates) > 1) {
            return [
                'status' => 'ambiguous',
                'candidates' => array_column($bestCandidates, 'property_url'),
            ];
        }

        return [
            'status' => 'matched',
            'property_url' => (string) $bestCandidates[0]['property_url'],
        ];
    }

    public function normalizeHost(string $input): string
    {
        $input = trim(mb_strtolower($input));
        if ($input === '') {
            return '';
        }

        if (str_starts_with($input, 'sc-domain:')) {
            $input = mb_substr($input, 10);
        } elseif (! str_contains($input, '://')) {
            $input = 'https://'.$input;
        }

        $host = parse_url($input, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $host = $input;
        }

        $host = mb_strtolower($host);

        if (str_starts_with($host, 'www.')) {
            $host = mb_substr($host, 4);
        }

        $port = parse_url($input, PHP_URL_PORT);
        if (is_int($port) && $port > 0) {
            $host = preg_replace('/:'.preg_quote((string) $port, '/').'$/', '', $host) ?? $host;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = mb_strtolower($ascii);
            }
        }

        return rtrim($host, '/');
    }

    public function propertyPriority(string $propertyUrl): int
    {
        $propertyUrl = trim($propertyUrl);

        if (str_starts_with($propertyUrl, 'sc-domain:')) {
            return 1;
        }

        $lower = mb_strtolower($propertyUrl);

        if (str_starts_with($lower, 'https://')) {
            $path = parse_url($propertyUrl, PHP_URL_PATH) ?? '/';
            $host = parse_url($propertyUrl, PHP_URL_HOST) ?? '';

            if (str_starts_with(mb_strtolower($host), 'www.')) {
                return 3;
            }

            if ($path === '/' || $path === '') {
                return 2;
            }

            return 5;
        }

        if (str_starts_with($lower, 'http://')) {
            $host = parse_url($propertyUrl, PHP_URL_HOST) ?? '';

            return str_starts_with(mb_strtolower($host), 'www.') ? 5 : 4;
        }

        return 6;
    }

    public function isPropertyAvailable(string $propertyUrl, array $availableProperties): bool
    {
        return in_array($propertyUrl, $availableProperties, true);
    }
}
