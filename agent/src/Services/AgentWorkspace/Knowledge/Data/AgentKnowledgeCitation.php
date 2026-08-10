<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentKnowledgeCitation
{
    public function __construct(
        public string $handle,
        public string $knowledgeRef,
        public string $title,
        public int $version,
        public string $sourceType,
        public string $scopeType,
        public string $trustLevel,
        public string $excerpt,
        public ?string $lastVerifiedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'knowledge_ref' => $this->knowledgeRef,
            'title' => $this->title,
            'version' => $this->version,
            'source_type' => $this->sourceType,
            'scope_type' => $this->scopeType,
            'trust_level' => $this->trustLevel,
            'excerpt' => $this->excerpt,
            'last_verified_at' => $this->lastVerifiedAt,
        ];
    }
}
