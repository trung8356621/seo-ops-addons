<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use InvalidArgumentException;

/**
 * Resource URLs may only be subpaths of the minted temporary access URL.
 */
final class SeoAccessUrlPolicy
{
    public function mintUrl(string $apiBase): string
    {
        $base = rtrim(trim($apiBase), '/');
        $parts = parse_url($base);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('SEO Access base URL is invalid.');
        }
        $this->assertPublicHost((string) ($parts['host'] ?? ''), (string) ($parts['user'] ?? ''));

        return $base.'/api/v1/services/seo/access';
    }

    public function assertResourceUrl(string $accessUrl, string $resourceUrl): void
    {
        $access = $this->parts($accessUrl);
        $resource = $this->parts($resourceUrl);
        if ($access['scheme'] !== $resource['scheme']
            || $access['host'] !== $resource['host']
            || $access['port'] !== $resource['port']) {
            throw new InvalidArgumentException('SEO Access resource URL is outside the minted access host.');
        }
        $basePath = rtrim($access['path'], '/');
        if ($resource['path'] !== $basePath && ! str_starts_with($resource['path'], $basePath.'/')) {
            throw new InvalidArgumentException('SEO Access resource URL is outside the minted access path.');
        }
        $this->assertPublicHost($resource['host'], '');
    }

    /**
     * @return array{scheme: string, host: string, port: string, path: string}
     */
    private function parts(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            throw new InvalidArgumentException('SEO Access URL is invalid.');
        }
        if (isset($parts['user']) && $parts['user'] !== '') {
            throw new InvalidArgumentException('SEO Access URL must not contain userinfo.');
        }

        return [
            'scheme' => strtolower((string) $parts['scheme']),
            'host' => strtolower((string) $parts['host']),
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'path' => (string) ($parts['path'] ?? '/'),
        ];
    }

    private function assertPublicHost(string $host, string $user): void
    {
        $host = strtolower(trim($host));
        if ($host === '' || $user !== '') {
            throw new InvalidArgumentException('SEO Access host is not allowed.');
        }
        if (in_array($host, ['metadata', 'metadata.google.internal'], true) || str_starts_with($host, '169.254.')) {
            throw new InvalidArgumentException('SEO Access metadata host is not allowed.');
        }
    }
}
