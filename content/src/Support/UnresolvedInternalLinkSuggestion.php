<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPriorityMerger;

/**
 * Editor presentation for a valid anchor whose destination failed / is missing.
 * href="#" is never an authoritative destination URL.
 *
 * @phpstan-type UnresolvedRow array{
 *     text: string,
 *     keyword_id: int|null,
 *     href: string,
 *     target_url: null,
 *     target_article_id: int|null,
 *     destination_resolved: false,
 *     can_insert: true,
 *     is_suggestion: true,
 *     score: int,
 *     match_reason: string,
 *     source: string,
 *     candidate_source: string,
 *     provenance: array<string, mixed>
 * }
 */
final class UnresolvedInternalLinkSuggestion
{
    /**
     * @param  array{
     *     keyword_id?: int|null,
     *     target_article_id?: int|null,
     *     score?: int,
     *     match_reason?: string,
     *     source?: string,
     *     candidate_source?: string,
     *     destination_reject_reason?: string|null,
     *     provenance?: array<string, mixed>
     * }  $extra
     * @return UnresolvedRow
     */
    public static function make(string $phrase, array $extra = []): array
    {
        $phrase = trim($phrase);
        $source = (string) ($extra['source'] ?? ArticleInternalLinkPriorityMerger::STAGE_GENERIC);
        $reject = isset($extra['destination_reject_reason'])
            ? (is_string($extra['destination_reject_reason']) ? $extra['destination_reject_reason'] : null)
            : null;

        $provenance = is_array($extra['provenance'] ?? null) ? $extra['provenance'] : [];
        $provenance = array_merge([
            'candidate_source' => (string) ($extra['candidate_source'] ?? $source),
            'matched_phrase' => $phrase,
            'match_reason' => (string) ($extra['match_reason'] ?? 'unresolved_destination'),
            'url' => null,
            'destination_resolved' => false,
        ], $provenance);
        if ($reject !== null && $reject !== '') {
            $provenance['destination_reject_reason'] = $reject;
        }

        $targetArticleId = (int) ($extra['target_article_id'] ?? 0);

        return [
            'text' => $phrase,
            'keyword_id' => isset($extra['keyword_id']) ? (((int) $extra['keyword_id']) ?: null) : null,
            'href' => '#',
            'target_url' => null,
            'target_article_id' => $targetArticleId > 0 ? $targetArticleId : null,
            'destination_resolved' => false,
            'can_insert' => true,
            'is_suggestion' => true,
            'score' => (int) ($extra['score'] ?? 0),
            'match_reason' => (string) ($extra['match_reason'] ?? 'unresolved_destination'),
            'source' => $source,
            'candidate_source' => (string) ($extra['candidate_source'] ?? $source),
            'provenance' => $provenance,
        ];
    }
}
