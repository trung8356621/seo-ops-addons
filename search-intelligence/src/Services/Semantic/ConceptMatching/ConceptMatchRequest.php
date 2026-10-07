<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

final class ConceptMatchRequest
{
    /**
     * @param  list<ConceptMatchEntity>  $entities
     * @param  list<ConceptDefinition>  $concepts
     */
    public function __construct(
        public readonly string $scopeRef,
        public readonly array $entities,
        public readonly array $concepts,
        public readonly ?string $language = null,
        public readonly ?ConceptDecisionPolicy $decisionPolicy = null,
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        $payload = [
            'scope_ref' => $this->scopeRef,
            'entities' => array_map(
                static fn (ConceptMatchEntity $e): array => $e->toApiArray(),
                $this->entities,
            ),
            'concepts' => array_map(
                static fn (ConceptDefinition $c): array => $c->toApiArray(),
                $this->concepts,
            ),
        ];
        if ($this->language !== null && $this->language !== '') {
            $payload['language'] = $this->language;
        }
        if ($this->decisionPolicy !== null) {
            $payload['decision_policy'] = $this->decisionPolicy->toApiArray();
        }

        return $payload;
    }
}
