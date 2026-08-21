<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalog;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalogResult;
use Throwable;

final class WordPressTaxonomyCatalogClient
{
    /**
     * @return array{token: string, base: string, error: ?string}
     */
    public function readAuth(Site $site): array
    {
        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($token === '') {
            return ['token' => '', 'base' => '', 'error' => 'Thiếu SEO Read Token.'];
        }

        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return ['token' => '', 'base' => '', 'error' => 'Domain site không hợp lệ.'];
        }

        $base = preg_match('#^https?://#i', $domain) === 1
            ? rtrim($domain, '/')
            : 'https://'.ltrim($domain, '/');

        return ['token' => $token, 'base' => rtrim($base, '/'), 'error' => null];
    }

    public function fetch(Site $site, string $taxonomy): PublishingTaxonomyCatalogResult
    {
        $taxonomy = strtolower(trim($taxonomy));
        if (! in_array($taxonomy, PublishingTaxonomyCatalog::SUPPORTED, true)) {
            return PublishingTaxonomyCatalogResult::unavailable(
                $taxonomy,
                'unsupported_taxonomy',
                'Unsupported taxonomy.',
            );
        }

        $auth = $this->readAuth($site);
        if ($auth['error'] !== null) {
            RuntimeLogger::warning('wordpress.taxonomy_catalog_failed', [
                'site_id' => $site->getKey(),
                'taxonomy' => $taxonomy,
                'code' => 'auth',
                'error' => $auth['error'],
            ]);

            return PublishingTaxonomyCatalogResult::unavailable($taxonomy, 'auth', $auth['error']);
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/taxonomy-catalog/'.$taxonomy);
        } catch (ConnectionException $e) {
            RuntimeLogger::warning('wordpress.taxonomy_catalog_failed', [
                'site_id' => $site->getKey(),
                'taxonomy' => $taxonomy,
                'code' => 'timeout',
                'error' => $e->getMessage(),
            ]);

            return PublishingTaxonomyCatalogResult::unavailable($taxonomy, 'timeout', 'WordPress taxonomy catalog timed out.');
        } catch (Throwable $e) {
            RuntimeLogger::warning('wordpress.taxonomy_catalog_failed', [
                'site_id' => $site->getKey(),
                'taxonomy' => $taxonomy,
                'code' => 'error',
                'error' => $e->getMessage(),
            ]);

            return PublishingTaxonomyCatalogResult::unavailable($taxonomy, 'error', $e->getMessage());
        }

        if ($response->status() === 404) {
            RuntimeLogger::warning('wordpress.taxonomy_catalog_failed', [
                'site_id' => $site->getKey(),
                'taxonomy' => $taxonomy,
                'code' => 'unsupported',
                'error' => 'taxonomy_catalog_v1 missing',
            ]);

            return PublishingTaxonomyCatalogResult::unavailable(
                $taxonomy,
                'unsupported',
                'Plugin chưa hỗ trợ taxonomy catalog.',
            );
        }

        if (! $response->successful()) {
            RuntimeLogger::warning('wordpress.taxonomy_catalog_failed', [
                'site_id' => $site->getKey(),
                'taxonomy' => $taxonomy,
                'code' => 'http',
                'error' => 'HTTP '.$response->status(),
            ]);

            return PublishingTaxonomyCatalogResult::unavailable(
                $taxonomy,
                'http',
                'taxonomy catalog HTTP '.$response->status(),
            );
        }

        $json = $response->json();
        if (! is_array($json) || ! is_array($json['items'] ?? null)) {
            RuntimeLogger::warning('wordpress.taxonomy_catalog_failed', [
                'site_id' => $site->getKey(),
                'taxonomy' => $taxonomy,
                'code' => 'invalid_payload',
                'error' => 'Invalid taxonomy catalog payload.',
            ]);

            return PublishingTaxonomyCatalogResult::unavailable(
                $taxonomy,
                'invalid_payload',
                'Invalid taxonomy catalog payload.',
            );
        }

        $hierarchical = in_array($taxonomy, ['category', 'product_cat'], true);
        $items = [];
        foreach ($json['items'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $items[] = [
                'id' => $id,
                'name' => $name,
                'parent' => $hierarchical ? max(0, (int) ($row['parent'] ?? 0)) : 0,
            ];
        }

        return PublishingTaxonomyCatalogResult::ok($taxonomy, $items);
    }
}
