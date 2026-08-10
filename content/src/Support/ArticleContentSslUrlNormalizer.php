<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use App\Models\Site;

/**
 * Nâng http → https cho URL thuộc domain site khi ssl=1 (tránh mixed content trên editor).
 */
final class ArticleContentSslUrlNormalizer
{
    public function normalizeForSite(string $html, ?Site $site): string
    {
        $html = trim($html);
        if ($html === '' || ! $site instanceof Site || empty($site->ssl)) {
            return $html;
        }

        $hosts = $this->resolveSiteHosts($site);
        if ($hosts === []) {
            return $html;
        }

        $normalized = $html;
        foreach ($hosts as $host) {
            $pattern = '#http://'.preg_quote($host, '#').'#i';
            $normalized = preg_replace($pattern, 'https://'.$host, $normalized) ?? $normalized;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function resolveSiteHosts(Site $site): array
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return [];
        }

        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        if ($domain === '') {
            return [];
        }

        $hosts = [strtolower($domain)];

        if (str_starts_with($domain, 'www.')) {
            $hosts[] = strtolower(substr($domain, 4));
        } else {
            $hosts[] = strtolower('www.'.$domain);
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
