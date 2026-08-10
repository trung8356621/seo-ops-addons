<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

use App\Models\Site;

final class WordPressSiteUrlMatcher
{
    public function siteUrlMatchesSite(Site $site, string $siteUrl): bool
    {
        $requestHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        if ($requestHost === '') {
            return false;
        }

        return $this->normalizeHost($requestHost) === $this->normalizeHost($this->siteHost($site));
    }

    public function resolveSiteBySiteUrl(string $siteUrl): ?Site
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '') {
            return null;
        }

        $sites = Site::query()
            ->with('metas')
            ->whereHas('metas', static function ($query): void {
                $query->where('meta_key', 'seo_platform')
                    ->where('meta_value', 'wordpress');
            })
            ->get();

        foreach ($sites as $site) {
            if ($this->siteUrlMatchesSite($site, $siteUrl)) {
                return $site;
            }
        }

        return null;
    }

    private function siteHost(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return strtolower((string) parse_url($domain, PHP_URL_HOST));
        }

        return strtolower(rtrim($domain, '/'));
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }
}
