<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

/**
 * One semantic unit for split execution (never a compiled-string slice).
 */
final readonly class SemanticContentChunk
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $logicalId,
        public string $kind,
        public string $body,
        public int $order,
        public array $meta = [],
        public ?string $parentId = null,
        public string $inputHash = '',
    ) {}

    public function withHash(): self
    {
        if ($this->inputHash !== '') {
            return $this;
        }

        return new self(
            logicalId: $this->logicalId,
            kind: $this->kind,
            body: $this->body,
            order: $this->order,
            meta: $this->meta,
            parentId: $this->parentId,
            inputHash: hash('sha256', $this->kind.'|'.$this->logicalId.'|'.$this->body),
        );
    }
}
