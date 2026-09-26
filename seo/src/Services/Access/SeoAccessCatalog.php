<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

/**
 * Canonical external SEO Access resource catalog (Agent read plane).
 */
class SeoAccessCatalog
{
    public const SCHEMA = 'seo.access.v1';

    /**
     * @return list<array{key: string, description: string}>
     */
    public static function resources(): array
    {
        return [
            [
                'key' => 'site',
                'description' => 'Business identity and writing context for this website.',
            ],
            [
                'key' => 'content',
                'description' => 'Content-type and taxonomy distribution.',
            ],
            [
                'key' => 'keywords',
                'description' => 'Keyword landscape and keyword relationship intelligence.',
            ],
            [
                'key' => 'gsc',
                'description' => 'Google Search Console performance and SEO opportunities.',
            ],
        ];
    }

    /**
     * @return list<array{key: string, description: string, href: string}>
     */
    public static function resourcesWithHrefs(string $tokenBasePath): array
    {
        $base = rtrim($tokenBasePath, '/');
        $out = [];
        foreach (self::resources() as $resource) {
            $out[] = [
                'key' => $resource['key'],
                'description' => $resource['description'],
                'href' => $base.'/'.$resource['key'],
            ];
        }

        return $out;
    }
}
