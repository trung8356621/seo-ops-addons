<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class WordPressKeywordDictionaryClient
{
    /**
     * @param  array{version: string, hash: string, clusters: list<array<string, mixed>>}  $dictionary
     * @return array<string, mixed>
     */
    public function apply(Site $site, array $dictionary, ?string $operationId = null): array
    {
        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        $domain = trim((string) $site->domain);
        if ($token === '' || $domain === '') {
            return ['ok' => false, 'message' => 'Missing write token or domain.'];
        }

        $base = preg_match('#^https?://#i', $domain) === 1
            ? rtrim($domain, '/')
            : 'https://'.ltrim($domain, '/');
        $operationId = $operationId !== null && $operationId !== ''
            ? $operationId
            : (string) Str::uuid();

        try {
            $response = Http::timeout(25)
                ->acceptJson()
                ->withToken($token)
                ->post($base.'/wp-json/omi-seo-ai/v1/keyword-dictionary/apply', [
                    'operation_id' => $operationId,
                    'dictionary_version' => $dictionary['version'],
                    'dictionary_hash' => $dictionary['hash'],
                    'clusters' => $dictionary['clusters'],
                ]);
        } catch (ConnectionException|Throwable $e) {
            RuntimeLogger::warning('wordpress.keyword_dictionary_apply_failed', [
                'site_id' => $site->getKey(),
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return ['ok' => false, 'message' => 'Invalid dictionary response.'];
        }

        return array_merge(['ok' => $response->successful()], $json);
    }
}
