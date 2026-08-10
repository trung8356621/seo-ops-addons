<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentMemoryProposal
{
    /**
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $sourceMetadata
     */
    public function __construct(
        public string $hashId,
        public string $status,
        public string $proposedType,
        public string $title,
        public string $content,
        public string $proposedScopeType,
        public ?string $proposedScopeRef,
        public string $reason,
        public float $confidence,
        public array $warnings = [],
        public array $sourceMetadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hash_id' => $this->hashId,
            'status' => $this->status,
            'proposed_type' => $this->proposedType,
            'title' => $this->title,
            'content' => $this->content,
            'proposed_scope_type' => $this->proposedScopeType,
            'proposed_scope_ref' => $this->proposedScopeRef,
            'reason' => $this->reason,
            'confidence' => $this->confidence,
            'warnings' => $this->warnings,
            'source_metadata' => $this->sourceMetadata,
        ];
    }
}
