<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

/**
 * Lightweight chunk ledger — array storage (PromptResult / planner metadata).
 * Atomic enough for single-process job ($tries=1) + resume after interrupt via persisted metadata.
 */
final class PromptChunkLedger
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @param  array{
     *   run_id?: string,
     *   hook?: string,
     *   chunks?: array<string, array<string, mixed>>,
     *   accepted_identities?: list<string>
     * }  $state
     */
    public function __construct(
        private array $state = [],
    ) {
        $this->state['chunks'] = is_array($this->state['chunks'] ?? null) ? $this->state['chunks'] : [];
        $this->state['accepted_identities'] = is_array($this->state['accepted_identities'] ?? null)
            ? $this->state['accepted_identities']
            : [];
    }

    public static function fromMetadata(?array $metadata): self
    {
        $ledger = is_array($metadata['chunk_ledger'] ?? null) ? $metadata['chunk_ledger'] : [];

        return new self(is_array($ledger) ? $ledger : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->state;
    }

    public function setRun(string $runId, string $hook): void
    {
        $this->state['run_id'] = $runId;
        $this->state['hook'] = $hook;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function planChunk(
        string $logicalId,
        string $inputHash,
        int $mergeOrder,
        ?string $parentId = null,
        array $meta = [],
    ): void {
        $this->state['chunks'][$logicalId] = [
            'logical_id' => $logicalId,
            'parent_id' => $parentId,
            'input_hash' => $inputHash,
            'route_attempt' => (int) ($meta['route_attempt'] ?? 0),
            'status' => self::STATUS_PLANNED,
            'output_hash' => null,
            'output' => null,
            'completed_at' => null,
            'merge_order' => $mergeOrder,
            'meta' => $meta,
        ];
    }

    public function markRunning(string $logicalId, int $routeAttempt = 0): void
    {
        if (! isset($this->state['chunks'][$logicalId])) {
            return;
        }
        $this->state['chunks'][$logicalId]['status'] = self::STATUS_RUNNING;
        $this->state['chunks'][$logicalId]['route_attempt'] = $routeAttempt;
    }

    public function markCompleted(string $logicalId, string $output, string $outputHash = ''): void
    {
        if (! isset($this->state['chunks'][$logicalId])) {
            return;
        }
        $hash = $outputHash !== '' ? $outputHash : hash('sha256', $output);
        $this->state['chunks'][$logicalId]['status'] = self::STATUS_COMPLETED;
        $this->state['chunks'][$logicalId]['output'] = $output;
        $this->state['chunks'][$logicalId]['output_hash'] = $hash;
        $this->state['chunks'][$logicalId]['completed_at'] = gmdate('c');
    }

    public function markFailed(string $logicalId): void
    {
        if (! isset($this->state['chunks'][$logicalId])) {
            return;
        }
        $this->state['chunks'][$logicalId]['status'] = self::STATUS_FAILED;
    }

    public function supersede(string $logicalId): void
    {
        if (! isset($this->state['chunks'][$logicalId])) {
            return;
        }
        $this->state['chunks'][$logicalId]['status'] = self::STATUS_SUPERSEDED;
    }

    public function isCompletedWithHash(string $logicalId, string $inputHash): bool
    {
        $row = $this->state['chunks'][$logicalId] ?? null;
        if (! is_array($row)) {
            return false;
        }

        return ($row['status'] ?? '') === self::STATUS_COMPLETED
            && (string) ($row['input_hash'] ?? '') === $inputHash
            && is_string($row['output'] ?? null);
    }

    public function completedOutput(string $logicalId): ?string
    {
        $row = $this->state['chunks'][$logicalId] ?? null;
        if (! is_array($row) || ($row['status'] ?? '') !== self::STATUS_COMPLETED) {
            return null;
        }

        return is_string($row['output'] ?? null) ? (string) $row['output'] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function completedLeavesForMerge(): array
    {
        $rows = [];
        foreach ($this->state['chunks'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['status'] ?? '') !== self::STATUS_COMPLETED) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => ((int) ($a['merge_order'] ?? 0)) <=> ((int) ($b['merge_order'] ?? 0)));

        return $rows;
    }

    public function rememberAcceptedIdentity(string $identity): bool
    {
        $identity = trim($identity);
        if ($identity === '') {
            return false;
        }
        if (in_array($identity, $this->state['accepted_identities'], true)) {
            return false;
        }
        $this->state['accepted_identities'][] = $identity;

        return true;
    }

    /**
     * @return list<string>
     */
    public function acceptedIdentities(): array
    {
        return $this->state['accepted_identities'];
    }

    public function acceptedCount(): int
    {
        return count($this->state['accepted_identities']);
    }
}
