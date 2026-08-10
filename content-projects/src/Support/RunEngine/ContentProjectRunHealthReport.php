<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

/**
 * Read-only health snapshot for Phase 1.5 ops (no DB writes).
 */
final class ContentProjectRunHealthReport
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $runId,
        public readonly bool $ok,
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'ok' => $this->ok,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'details' => $this->details,
        ];
    }
}
