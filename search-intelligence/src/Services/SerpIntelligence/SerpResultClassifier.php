<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpResultType;

/**
 * Map provider-specific result types sang canonical SerpResultType.
 */
final class SerpResultClassifier
{
    /**
     * @param  array<string, mixed>  $providerResult
     * @return array{
     *   type: SerpResultType,
     *   provider_type: string,
     *   metadata: array<string, mixed>
     * }
     */
    public function classify(array $providerResult): array
    {
        $providerType = mb_strtolower(trim((string) ($providerResult['type'] ?? $providerResult['result_type'] ?? '')), 'UTF-8');
        $mapped = $this->mapProviderType($providerType);

        return [
            'type' => $mapped ?? SerpResultType::Other,
            'provider_type' => $providerType !== '' ? $providerType : 'unknown',
            'metadata' => [
                'mapped' => $mapped !== null,
                'classifier_version' => '1.0.0',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $providerResults
     * @return list<array<string, mixed>>
     */
    public function classifyMany(array $providerResults): array
    {
        $classified = [];
        foreach ($providerResults as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $result = $this->classify($row);
            $classified[] = array_merge($row, [
                'canonical_type' => $result['type']->value,
                'provider_type' => $result['provider_type'],
                'type_metadata' => $result['metadata'],
                'source_index' => $index,
            ]);
        }

        return $classified;
    }

    private function mapProviderType(string $providerType): ?SerpResultType
    {
        if ($providerType === '') {
            return null;
        }

        $map = $this->providerTypeMap();
        if (isset($map[$providerType])) {
            return $map[$providerType];
        }

        foreach ($map as $pattern => $type) {
            if (str_contains($providerType, $pattern)) {
                return $type;
            }
        }

        return null;
    }

    /** @return array<string, SerpResultType> */
    private function providerTypeMap(): array
    {
        $defaults = [
            'organic' => SerpResultType::Organic,
            'natural' => SerpResultType::Organic,
            'web' => SerpResultType::Organic,
            'paid' => SerpResultType::Other,
            'ad' => SerpResultType::Other,
            'ppc' => SerpResultType::Other,
            'sponsored' => SerpResultType::Other,
            'featured_snippet' => SerpResultType::FeaturedSnippet,
            'answer_box' => SerpResultType::FeaturedSnippet,
            'local_pack' => SerpResultType::LocalPack,
            'local pack' => SerpResultType::LocalPack,
            'map' => SerpResultType::LocalPack,
            'local' => SerpResultType::LocalPack,
            'knowledge' => SerpResultType::KnowledgePanel,
            'knowledge_graph' => SerpResultType::KnowledgePanel,
            'image' => SerpResultType::Image,
            'images' => SerpResultType::Image,
            'video' => SerpResultType::Video,
            'videos' => SerpResultType::Video,
            'news' => SerpResultType::News,
            'top_stories' => SerpResultType::News,
            'shopping' => SerpResultType::Shopping,
            'product' => SerpResultType::Shopping,
            'paa' => SerpResultType::PeopleAlsoAsk,
            'people_also_ask' => SerpResultType::PeopleAlsoAsk,
            'related' => SerpResultType::Other,
            'related_search' => SerpResultType::Other,
            'sitelink' => SerpResultType::Sitelink,
            'sitelinks' => SerpResultType::Sitelink,
            'discussions' => SerpResultType::Discussion,
            'discussion' => SerpResultType::Discussion,
            'forum' => SerpResultType::Forum,
        ];

        if (! function_exists('config')) {
            return $defaults;
        }

        try {
            $custom = config('seo-content-ai.serp_intelligence.result_type_map', []);
            if (! is_array($custom)) {
                return $defaults;
            }

            foreach ($custom as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    continue;
                }
                $enum = SerpResultType::tryFrom($value);
                if ($enum !== null) {
                    $defaults[mb_strtolower($key, 'UTF-8')] = $enum;
                }
            }
        } catch (\Throwable) {
            // keep defaults
        }

        return $defaults;
    }
}
