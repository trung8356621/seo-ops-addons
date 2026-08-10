<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentMemoryCandidate
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $content,
        public string $proposedScopeType,
        public ?string $proposedScopeRef,
        public string $reason,
        public float $confidence,
        public array $warnings = [],
        public ?string $sourceMessage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'content' => $this->content,
            'proposed_scope_type' => $this->proposedScopeType,
            'proposed_scope_ref' => $this->proposedScopeRef,
            'reason' => $this->reason,
            'confidence' => $this->confidence,
            'warnings' => $this->warnings,
            'source_message' => $this->sourceMessage,
        ];
    }
}
