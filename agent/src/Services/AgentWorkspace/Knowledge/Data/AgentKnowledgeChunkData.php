<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentKnowledgeChunkData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $chunkIndex,
        public string $content,
        public int $tokenEstimate,
        public string $contentHash,
        public ?string $heading = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunk_index' => $this->chunkIndex,
            'heading' => $this->heading,
            'content' => $this->content,
            'token_estimate' => $this->tokenEstimate,
            'content_hash' => $this->contentHash,
            'metadata' => $this->metadata,
        ];
    }
}
