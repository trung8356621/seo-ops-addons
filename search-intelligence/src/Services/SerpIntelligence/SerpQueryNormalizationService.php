<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * Normalize SERP query text — giữ dấu tiếng Việt, không dịch.
 * lowercase chỉ cho normalized_query; display_query giữ nguyên casing gốc (sau trim/whitespace).
 */
final class SerpQueryNormalizationService
{
    private const DEFAULT_MAX_LENGTH = 500;

    private const DEFAULT_LANGUAGE = 'vi';

    private const DEFAULT_COUNTRY = 'VN';

    private const DEFAULT_DEVICE = 'desktop';

    private const DEFAULT_SEARCH_ENGINE = 'google';

    /**
     * @param  array<string, mixed>  $scope
     * @return array{
     *   tenant: ?string,
     *   site: ?string,
     *   display_query: string,
     *   normalized_query: string,
     *   language: string,
     *   country: string,
     *   location: ?string,
     *   device: string,
     *   search_engine: string,
     *   provider: string,
     *   is_valid: bool,
     *   failure_code: ?string,
     *   changes: list<string>
     * }
     */
    public function normalizeScope(array $scope): array
    {
        $displayQuery = $this->displayQuery((string) ($scope['query'] ?? $scope['display_query'] ?? ''));
        $normalizedQuery = $this->normalizeQuery($displayQuery);
        $changes = $this->detectChanges((string) ($scope['query'] ?? ''), $displayQuery, $normalizedQuery);

        $maxLength = $this->configInt('normalization.max_query_length', self::DEFAULT_MAX_LENGTH);
        $failureCode = null;
        if ($normalizedQuery === '') {
            $failureCode = 'serp.query.empty';
        } elseif (mb_strlen($normalizedQuery, 'UTF-8') > $maxLength) {
            $failureCode = 'serp.query.too_long';
        }

        return [
            'tenant' => $this->nullableString($scope['tenant'] ?? $scope['tenant_ref'] ?? null),
            'site' => $this->nullableString($scope['site'] ?? $scope['site_ref'] ?? null),
            'display_query' => $displayQuery,
            'normalized_query' => $normalizedQuery,
            'language' => $this->normalizeLocaleCode((string) ($scope['language'] ?? self::DEFAULT_LANGUAGE)),
            'country' => $this->normalizeLocaleCode((string) ($scope['country'] ?? self::DEFAULT_COUNTRY)),
            'location' => $this->nullableString($scope['location'] ?? null),
            'device' => $this->normalizeDevice((string) ($scope['device'] ?? self::DEFAULT_DEVICE)),
            'search_engine' => $this->normalizeSearchEngine((string) ($scope['search_engine'] ?? self::DEFAULT_SEARCH_ENGINE)),
            'provider' => trim((string) ($scope['provider'] ?? $scope['provider_key'] ?? '')),
            'is_valid' => $failureCode === null,
            'failure_code' => $failureCode,
            'changes' => $changes,
        ];
    }

    public function normalizeQuery(string $query): string
    {
        $value = $this->prepareText($query);
        if ($value === '') {
            return '';
        }

        return mb_strtolower($value, 'UTF-8');
    }

    public function displayQuery(string $query): string
    {
        return $this->prepareText($query);
    }

    /**
     * Canonical identity cho unique DB — sha256 hex (64).
     * Scope: normalized_query + language + country + location + device + search_engine + provider.
     */
    public function identityHash(
        string $normalizedQuery,
        string $language = '',
        string $country = '',
        string $location = '',
        string $device = self::DEFAULT_DEVICE,
        string $searchEngine = self::DEFAULT_SEARCH_ENGINE,
        string $providerKey = '',
    ): string {
        $parts = [
            $this->normalizeQuery($normalizedQuery),
            $this->normalizeLocaleCode($language !== '' ? $language : self::DEFAULT_LANGUAGE),
            $this->normalizeLocaleCode($country !== '' ? $country : self::DEFAULT_COUNTRY),
            mb_strtolower(trim($location), 'UTF-8'),
            $this->normalizeDevice($device),
            $this->normalizeSearchEngine($searchEngine),
            mb_strtolower(trim($providerKey), 'UTF-8'),
        ];

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @return list<string>
     */
    private function detectChanges(string $original, string $display, string $normalized): array
    {
        $changes = [];
        if (trim($original) !== $original) {
            $changes[] = 'trimmed_whitespace';
        }
        if (preg_replace('/\s+/u', ' ', trim($original)) !== trim($original)) {
            $changes[] = 'collapsed_whitespace';
        }
        if ($display !== '' && mb_strtolower($display, 'UTF-8') !== $display) {
            $changes[] = 'lowercased_for_matching';
        }
        if ($display !== $normalized && $normalized !== '') {
            $changes[] = 'display_preserved';
        }

        return $changes;
    }

    private function prepareText(string $query): string
    {
        $value = trim($query);
        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeLocaleCode(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));

        return $value !== '' ? $value : self::DEFAULT_LANGUAGE;
    }

    private function normalizeDevice(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if (in_array($value, ['mobile', 'tablet', 'desktop'], true)) {
            return $value;
        }

        return self::DEFAULT_DEVICE;
    }

    private function normalizeSearchEngine(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));

        return $value !== '' ? $value : self::DEFAULT_SEARCH_ENGINE;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
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
