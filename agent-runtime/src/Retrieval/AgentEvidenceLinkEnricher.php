<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Navigation\AgentInternalLinkResolver;

final class AgentEvidenceLinkEnricher
{
    public function __construct(private readonly AgentInternalLinkResolver $links) {}

    /**
     * @param  list<RetrievalSource>  $sources
     * @return list<RetrievalSource>
     */
    public function enrich(array $sources, AgentProjectScope $scope): array
    {
        return array_map(fn (RetrievalSource $source): RetrievalSource => new RetrievalSource(
            $source->name,
            $source->status,
            $source->request,
            $source->status === 'ok' ? $this->walk($source->data, $scope) : $source->data,
            $source->reason,
        ), $sources);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function walk(array $node, AgentProjectScope $scope): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $scope);
            }
        }

        $topicRef = $node['topic_ref'] ?? null;
        if (is_string($topicRef)) {
            $href = $this->links->resolve($topicRef, $scope);
            if ($href !== null) {
                $node['ui_href'] = $href;
            }
        }

        $coverage = strtolower(trim((string) ($node['coverage'] ?? '')));
        if (in_array($coverage, ['strong', 'medium', 'weak'], true)) {
            $href = $this->links->resolve('coverage:'.$coverage, $scope);
            if ($href !== null) {
                $node['coverage_href'] = $href;
            }
        }

        return $node;
    }
}
