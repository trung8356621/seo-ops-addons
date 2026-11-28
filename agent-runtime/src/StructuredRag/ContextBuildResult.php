<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

final readonly class ContextBuildResult
{
    /** @param list<string> $limitations */
    public function __construct(
        public RetrievalBundle $bundle,
        public array $limitations,
        public int $sourceCount,
        public int $evidenceBytes,
        public int $recordsIncluded,
        public int $recordsOmitted,
    ) {}

    /** @return array<string, mixed> */
    public function budget(): array
    {
        return [
            'source_count' => $this->sourceCount,
            'evidence_bytes' => $this->evidenceBytes,
            'records_included' => $this->recordsIncluded,
            'records_omitted' => $this->recordsOmitted,
        ];
    }
}
