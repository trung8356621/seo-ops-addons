<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentKnowledgeItemData
{
    /**
     * @param  array<string, mixed>  $sourceMetadata
     */
    public function __construct(
        public string $hashId,
        public string $scopeType,
        public ?string $scopeRef,
        public string $type,
        public string $title,
        public string $content,
        public ?string $summary,
        public string $sourceType,
        public ?string $sourceRef,
        public string $trustLevel,
        public string $status,
        public int $priority,
        public int $version,
        public string $contentHash,
        public ?string $indexStatus = null,
        public array $sourceMetadata = [],
        public ?string $validUntil = null,
        public ?string $lastVerifiedAt = null,
        public ?int $supersedesId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hash_id' => $this->hashId,
            'scope_type' => $this->scopeType,
            'scope_ref' => $this->scopeRef,
            'type' => $this->type,
            'title' => $this->title,
            'content' => $this->content,
            'summary' => $this->summary,
            'source_type' => $this->sourceType,
            'source_ref' => $this->sourceRef,
            'trust_level' => $this->trustLevel,
            'status' => $this->status,
            'priority' => $this->priority,
            'version' => $this->version,
            'content_hash' => $this->contentHash,
            'index_status' => $this->indexStatus,
            'source_metadata' => $this->sourceMetadata,
            'valid_until' => $this->validUntil,
            'last_verified_at' => $this->lastVerifiedAt,
            'supersedes_id' => $this->supersedesId,
        ];
    }
}
