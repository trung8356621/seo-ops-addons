<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup;

/**
 * External topic-group refs returned by seo-ops-semantic.
 * This object has no writer for keywords, articles, or permissions.
 */
final readonly class TopicGroupMatchResult
{
    /**
     * @param  list<array{ref: string, score: float}>  $matches
     */
    public function __construct(
        public string $scopeRef,
        public array $matches,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApi(array $payload): self
    {
        $matches = [];
        foreach ((array) ($payload['matches'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = trim((string) ($row['ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $matches[] = [
                'ref' => $ref,
                'score' => (float) ($row['score'] ?? 0),
            ];
        }

        return new self(
            scopeRef: trim((string) ($payload['scope_ref'] ?? '')),
            matches: $matches,
        );
    }
}
