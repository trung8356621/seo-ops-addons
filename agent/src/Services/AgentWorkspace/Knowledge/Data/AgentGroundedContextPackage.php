<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentGroundedContextPackage
{
    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<string, mixed>>  $preferences
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<string>  $warnings
     * @param  list<AgentKnowledgeCitation>  $citations
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public array $facts = [],
        public array $rules = [],
        public array $preferences = [],
        public array $conflicts = [],
        public array $warnings = [],
        public array $citations = [],
        public int $omittedCount = 0,
        public array $diagnostics = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'facts' => $this->facts,
            'rules' => $this->rules,
            'preferences' => $this->preferences,
            'conflicts' => $this->conflicts,
            'warnings' => $this->warnings,
            'citations' => array_map(
                static fn (AgentKnowledgeCitation $c): array => $c->toArray(),
                $this->citations,
            ),
            'omitted_count' => $this->omittedCount,
            'diagnostics' => $this->diagnostics,
            'untrusted' => true,
        ];
    }
}
