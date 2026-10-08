<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup;

use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;

final class TopicGroupRetrievalClient
{
    public function __construct(private readonly SemanticAnalyticsClient $client) {}

    /**
     * @param  list<array{ref: string, label?: string, examples: list<string>}>  $groups
     */
    public function match(string $scopeRef, string $query, array $groups, ?string $language = null, int $limit = 3): TopicGroupMatchResult
    {
        $payload = [
            'scope_ref' => $scopeRef,
            'query' => $query,
            'language' => $language,
            'groups' => $groups,
            'policy' => [
                'min_score' => 0.55,
                'limit' => max(1, min(20, $limit)),
            ],
        ];

        return TopicGroupMatchResult::fromApi($this->client->postJson('/v1/topic-groups/matches', $payload));
    }
}
