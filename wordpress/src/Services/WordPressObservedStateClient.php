<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lightweight WP post observe — no post_content.
 */
final class WordPressObservedStateClient
{
    /**
     * @return array{
     *     ok: bool,
     *     missing?: bool,
     *     timeout?: bool,
     *     wp_post_id?: int|null,
     *     status?: string,
     *     permalink?: string,
     *     modified_at?: string|null,
     *     message?: string
     * }
     */
    public function observePost(Site $site, int $wpPostId): array
    {
        if ($wpPostId <= 0) {
            return ['ok' => false, 'missing' => true, 'message' => 'Missing wp_post_id.'];
        }

        $auth = $this->readAuth($site);
        if ($auth['error'] !== null) {
            return ['ok' => false, 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/posts/'.$wpPostId.'/observe');
        } catch (ConnectionException) {
            return ['ok' => false, 'timeout' => true, 'message' => 'WordPress observe timed out.'];
        } catch (Throwable $e) {
            RuntimeLogger::warning('wordpress.observe_post_failed', [
                'site_id' => $site->getKey(),
                'wp_post_id' => $wpPostId,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'timeout' => true, 'message' => $e->getMessage()];
        }

        if ($response->status() === 404) {
            return ['ok' => true, 'missing' => true, 'wp_post_id' => $wpPostId, 'status' => 'missing'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'observe HTTP '.$response->status()];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return ['ok' => false, 'message' => 'Invalid observe payload.'];
        }

        if (($json['found'] ?? true) === false || ($json['missing'] ?? false) === true) {
            return ['ok' => true, 'missing' => true, 'wp_post_id' => $wpPostId, 'status' => 'missing'];
        }

        $post = is_array($json['post'] ?? null) ? $json['post'] : $json;

        return [
            'ok' => true,
            'missing' => false,
            'wp_post_id' => (int) ($post['wp_post_id'] ?? $post['wp_id'] ?? $wpPostId),
            'status' => strtolower(trim((string) ($post['status'] ?? ''))),
            'permalink' => trim((string) ($post['permalink'] ?? '')),
            'modified_at' => isset($post['post_modified']) ? (string) $post['post_modified'] : null,
        ];
    }

    /**
     * @return array{token: string, base: string, error: ?string}
     */
    private function readAuth(Site $site): array
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
}
