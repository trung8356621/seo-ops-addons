<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;

final class LocalAgentToolRouter
{
    public function __construct(
        private readonly ToolIntentMatcher $matcher,
        private readonly ExplicitToolIntentRules $explicit = new ExplicitToolIntentRules(),
        private readonly CatalogToolRouteAuthority $authority = new CatalogToolRouteAuthority(),
    ) {}

    public function route(string $message): LocalToolRoute
    {
        $allowed = [];
        foreach (AgentCapabilityCatalog::all() as $key => $metadata) {
            if (($metadata['jev_selectable'] ?? false) === true && ($metadata['status'] ?? null) === 'available') {
                $allowed[] = $key;
            }
        }
        $intents = ToolIntentExamples::forKeys($allowed);
        $explicit = $this->explicit->match($message, $intents);
        if ($explicit['status'] !== 'none') {
            return $this->authorize($explicit['status'], $explicit['matches'], 'explicit');
        }

        $semantic = $this->matcher->match($message, $intents);

        return $this->authorize($semantic->status, $semantic->matches, 'semantic');
    }

    /**
     * @param  list<array{ref: string, score: float, lexical?: bool, semantic_score?: float|null}>  $matches
     */
    private function authorize(string $status, array $matches, string $evidenceKind): LocalToolRoute
    {
        $normalized = [];
        foreach ($matches as $match) {
            $normalized[] = [
                'ref' => (string) $match['ref'],
                'score' => (float) $match['score'],
            ];
        }

        if ($status === 'unavailable') {
            return new LocalToolRoute('unavailable', null, null, false, $normalized, $evidenceKind);
        }
        if ($status !== 'confident' || $normalized === []) {
            $outcome = $status === 'ambiguous' ? 'ambiguous' : 'none';

            return new LocalToolRoute($outcome, null, null, false, $normalized, $evidenceKind);
        }

        $top = $normalized[0];
        if (! $this->authority->accepts($top['ref'])) {
            return new LocalToolRoute('rejected', $top['ref'], $top['score'], false, $normalized, $evidenceKind);
        }

        return new LocalToolRoute('confident', $top['ref'], $top['score'], true, $normalized, $evidenceKind);
    }
}
