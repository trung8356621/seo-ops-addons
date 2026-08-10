<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

/**
 * identity_hash / data_hash cho daily facts — canonical dimensions, không gộp metric.
 */
final class GscFactHashService
{
    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * Hash định danh row theo dimensions (không gồm metric values).
     */
    public function identityHash(
        string $propertyRef,
        string $date,
        string $normalizedQuery,
        string $normalizedPage,
        string $country,
        string $device,
        string $searchAppearance,
    ): string {
        return $this->hashDimensions([
            'property' => $propertyRef,
            'date' => $date,
            'query' => $normalizedQuery,
            'page' => $normalizedPage,
            'country' => mb_strtolower($country, 'UTF-8'),
            'device' => mb_strtolower($device, 'UTF-8'),
            'search_appearance' => mb_strtolower($searchAppearance, 'UTF-8'),
        ]);
    }

    /**
     * data_hash dùng cho upsert — REPLACE values, không cộng dồn clicks/impressions.
     */
    public function dataHash(
        string $propertyRef,
        string $date,
        string $normalizedQuery,
        string $normalizedPage,
        string $country,
        string $device,
        string $searchAppearance,
    ): string {
        return $this->identityHash(
            $propertyRef,
            $date,
            $normalizedQuery,
            $normalizedPage,
            $country,
            $device,
            $searchAppearance,
        );
    }

    /**
     * @param  array<string, string>  $dimensions
     */
    private function hashDimensions(array $dimensions): string
    {
        ksort($dimensions);
        $canonical = json_encode($dimensions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', 'gsc-fact:'.self::ALGORITHM_VERSION.':'.($canonical ?: '{}'));
    }
}
