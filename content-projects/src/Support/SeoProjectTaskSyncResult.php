<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Sync diagnostics / result (Phase 3C2).
 */
final class SeoProjectTaskSyncResult
{
    /**
     * @param  list<int>  $createdTaskIds
     * @param  list<int>  $updatedTaskIds
     * @param  list<int>  $reactivatedTaskIds
     * @param  list<int>  $cancelledTaskIds
     * @param  list<int>  $unchangedTaskIds
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $errors
     * @param  list<array<string, mixed>>  $duplicateCandidates
     */
    public function __construct(
        public readonly array $createdTaskIds = [],
        public readonly array $updatedTaskIds = [],
        public readonly array $reactivatedTaskIds = [],
        public readonly array $cancelledTaskIds = [],
        public readonly array $unchangedTaskIds = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly array $duplicateCandidates = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
