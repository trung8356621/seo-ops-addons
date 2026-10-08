<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Throwable;

/**
 * Suggestion-only Wikipedia links. Does not insert anchors.
 */
final class ExternalWikiSuggestionService
{
    /**
     * @return array{status: string, suggestions: list<array<string, mixed>>}
     */
    public function suggest(SeoArticle $article, string $content): array
    {
        $plain = trim(strip_tags($content));
        if ($plain === '' || ! (bool) config('semantic.enabled', false)) {
            return ['status' => 'unavailable', 'suggestions' => []];
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('semantic.url'), '/'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('semantic.timeout', 30))
                ->post('/v1/wiki-suggestions', [
                    'article_ref' => 'article:'.$article->id,
                    'content' => mb_substr($plain, 0, 8000),
                    'language' => (string) ($article->language ?? ''),
                    'policy' => [
                        'max_suggestions' => 2,
                        'lookup' => 'wikipedia',
                    ],
                ]);
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'suggestions' => [], 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['status' => 'unavailable', 'suggestions' => []];
        }

        $suggestions = [];
        foreach ((array) ($response->json('suggestions') ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $term = trim((string) ($row['term'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($term === '' || mb_stripos($plain, $term) === false) {
                continue;
            }
            if (! str_starts_with($url, 'https://en.wikipedia.org/wiki/') && ! str_starts_with($url, 'https://vi.wikipedia.org/wiki/')) {
                continue;
            }
            $suggestions[] = [
                'text' => $term,
                'href' => $url,
                'target_url' => $url,
                'is_suggestion' => true,
                'can_insert' => true,
                'source' => 'wiki_v2',
                'bucket' => 'external',
                'match_reason' => (string) ($row['evidence'] ?? 'canonical_alias'),
            ];
        }

        return ['status' => 'ok', 'suggestions' => array_slice($suggestions, 0, 2)];
    }
}
