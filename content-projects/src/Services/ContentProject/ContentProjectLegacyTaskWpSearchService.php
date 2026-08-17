<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only WordPress candidate search via existing bridge/site-sync surfaces.
 * Never auto-attaches.
 */
final class ContentProjectLegacyTaskWpSearchService
{
    /**
     * @param  array<string, mixed>  $forensic
     * @return list<array<string, mixed>>
     */
    public function search(Site $site, array $forensic): array
    {
        $auth = $this->readAuth($site);
        if ($auth['error'] !== null) {
            return [[
                'ok' => false,
                'evidence' => 'auth',
                'message' => $auth['error'],
            ]];
        }

        $candidates = [];
        $seen = [];

        $historicalWpIds = $this->collectWpPostIds($forensic);
        foreach ($historicalWpIds as $wpPostId) {
            $observed = $this->observe($auth, $wpPostId);
            if ($observed !== null) {
                $key = 'id:'.(int) ($observed['wp_post_id'] ?? $wpPostId);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $candidates[] = $observed;
                }
            }
        }

        $articleId = (int) ($forensic['task']['article_id'] ?? 0);
        if ($articleId > 0) {
            $byArticle = $this->findByArticle($auth, $articleId);
            if ($byArticle !== null) {
                $key = 'id:'.(int) ($byArticle['wp_post_id'] ?? 0);
                if ($key !== 'id:0' && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $candidates[] = $byArticle;
                }
            }
        }

        $slugs = $this->collectSlugs($forensic);
        foreach ($slugs as $slug) {
            foreach ($this->wpV2($auth, ['slug' => $slug]) as $row) {
                $key = 'id:'.(int) ($row['wp_post_id'] ?? 0);
                if ($key !== 'id:0' && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $candidates[] = $row;
                }
            }
        }

        $searches = $this->collectSearchTerms($forensic);
        foreach ($searches as $term) {
            foreach ($this->wpV2($auth, ['search' => $term, 'per_page' => 5]) as $row) {
                $key = 'id:'.(int) ($row['wp_post_id'] ?? 0);
                if ($key !== 'id:0' && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $candidates[] = $row;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array{status: 'none'|'ambiguous'|'exact', candidate: ?array<string, mixed>}
     */
    public function pickStrongUnambiguous(array $candidates, string $keyword, ?string $plannedTitle): array
    {
        $usable = array_values(array_filter(
            $candidates,
            static fn (array $row): bool => ! empty($row['ok']) && empty($row['missing']) && (int) ($row['wp_post_id'] ?? 0) > 0,
        ));
        if ($usable === []) {
            return ['status' => 'none', 'candidate' => null];
        }

        $strong = [];
        foreach ($usable as $row) {
            $evidence = (string) ($row['evidence'] ?? '');
            $title = mb_strtolower(trim((string) ($row['title'] ?? '')));
            $kw = mb_strtolower(trim($keyword));
            $planned = mb_strtolower(trim((string) $plannedTitle));
            $isId = str_starts_with($evidence, 'wp_post_id') || $evidence === 'observe';
            $isSlug = str_starts_with($evidence, 'slug');
            $titleExact = $planned !== '' && $title === $planned;
            $keywordExactTitle = $kw !== '' && $title === $kw;
            if ($isId || $isSlug || $titleExact || $keywordExactTitle) {
                $strong[] = $row;
            }
        }

        $strongExact = $strong;
        if (count($strongExact) === 1) {
            return ['status' => 'exact', 'candidate' => $strongExact[0]];
        }
        if (count($strongExact) > 1) {
            return ['status' => 'ambiguous', 'candidate' => null];
        }

        return ['status' => 'none', 'candidate' => null];
    }

    /**
     * @param  array{token: string, base: string, error: ?string}  $auth
     * @return array<string, mixed>|null
     */
    private function observe(array $auth, int $wpPostId): ?array
    {
        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/posts/'.$wpPostId.'/observe');
        } catch (ConnectionException|Throwable $e) {
            RuntimeLogger::warning('content_project.legacy_wp_observe_failed', [
                'wp_post_id' => $wpPostId,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'wp_post_id' => $wpPostId,
                'evidence' => 'observe',
                'message' => $e->getMessage(),
            ];
        }

        if ($response->status() === 404) {
            return [
                'ok' => true,
                'missing' => true,
                'wp_post_id' => $wpPostId,
                'evidence' => 'observe',
                'status' => 'missing',
            ];
        }
        if (! $response->successful()) {
            return [
                'ok' => false,
                'wp_post_id' => $wpPostId,
                'evidence' => 'observe',
                'message' => 'HTTP '.$response->status(),
            ];
        }

        $json = $response->json();
        $post = is_array($json['post'] ?? null) ? $json['post'] : (is_array($json) ? $json : []);

        return [
            'ok' => true,
            'missing' => false,
            'wp_post_id' => (int) ($post['wp_post_id'] ?? $post['wp_id'] ?? $wpPostId),
            'title' => (string) ($post['title'] ?? $post['post_title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? $post['post_name'] ?? ''),
            'permalink' => (string) ($post['permalink'] ?? ''),
            'status' => (string) ($post['status'] ?? $post['post_status'] ?? ''),
            'modified' => (string) ($post['post_modified'] ?? $post['modified_at'] ?? ''),
            'evidence' => 'observe',
        ];
    }

    /**
     * @param  array{token: string, base: string, error: ?string}  $auth
     * @return array<string, mixed>|null
     */
    private function findByArticle(array $auth, int $articleId): ?array
    {
        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/posts/find-by-article', [
                    'article_id' => $articleId,
                ]);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'evidence' => 'find-by-article',
                'message' => $e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        if (! is_array($json) || empty($json['found'])) {
            return null;
        }
        $post = is_array($json['post'] ?? null) ? $json['post'] : $json;

        return [
            'ok' => true,
            'missing' => false,
            'wp_post_id' => (int) ($post['wp_post_id'] ?? $post['id'] ?? 0) ?: null,
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'permalink' => (string) ($post['permalink'] ?? ''),
            'status' => (string) ($post['status'] ?? ''),
            'modified' => (string) ($post['modified'] ?? ''),
            'evidence' => 'find-by-article:'.$articleId,
        ];
    }

    /**
     * @param  array{token: string, base: string, error: ?string}  $auth
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function wpV2(array $auth, array $query): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/wp/v2/posts', $query);
        } catch (Throwable $e) {
            return [[
                'ok' => false,
                'evidence' => isset($query['slug']) ? 'slug' : 'wp_v2_search',
                'message' => $e->getMessage(),
            ]];
        }
        if (! $response->successful()) {
            return [[
                'ok' => false,
                'evidence' => isset($query['slug']) ? 'slug' : 'wp_v2_search',
                'message' => 'wp/v2 HTTP '.$response->status(),
            ]];
        }
        $json = $response->json();
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ($json as $post) {
            if (! is_array($post)) {
                continue;
            }
            $title = $post['title']['rendered'] ?? $post['title'] ?? '';
            $out[] = [
                'ok' => true,
                'missing' => false,
                'wp_post_id' => (int) ($post['id'] ?? 0) ?: null,
                'title' => html_entity_decode(strip_tags((string) $title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => (string) ($post['slug'] ?? ''),
                'permalink' => (string) ($post['link'] ?? ''),
                'status' => (string) ($post['status'] ?? ''),
                'modified' => (string) ($post['modified'] ?? ''),
                'evidence' => isset($query['slug']) ? 'slug' : 'wp_v2_search',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $forensic
     * @return list<int>
     */
    private function collectWpPostIds(array $forensic): array
    {
        $ids = [];
        $current = (int) ($forensic['current_article']['wp_post_id'] ?? 0);
        if ($current > 0) {
            $ids[] = $current;
        }
        foreach ($forensic['run_items'] ?? [] as $item) {
            $snap = is_array($item['output_snapshot'] ?? null) ? $item['output_snapshot'] : [];
            $wp = (int) data_get($snap, 'wp_post_id', data_get($snap, 'result.wp_post_id', 0));
            if ($wp > 0) {
                $ids[] = $wp;
            }
        }
        foreach ($forensic['task_events'] ?? [] as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $wp = (int) ($payload['wp_post_id'] ?? 0);
            if ($wp > 0) {
                $ids[] = $wp;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $forensic
     * @return list<string>
     */
    private function collectSlugs(array $forensic): array
    {
        $slugs = [];
        $current = trim((string) ($forensic['current_article']['slug'] ?? ''));
        if ($current !== '') {
            $slugs[] = $current;
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  array<string, mixed>  $forensic
     * @return list<string>
     */
    private function collectSearchTerms(array $forensic): array
    {
        $terms = [];
        $keyword = trim((string) ($forensic['task']['keyword'] ?? ''));
        $title = trim((string) ($forensic['task']['title'] ?? ''));
        $generated = trim((string) ($forensic['historical_prompt']['generated_title'] ?? ''));
        if ($keyword !== '') {
            $terms[] = $keyword;
        }
        if ($title !== '') {
            $terms[] = $title;
        }
        if ($generated !== '' && $generated !== $title) {
            $terms[] = $generated;
        }

        return array_values(array_unique($terms));
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
