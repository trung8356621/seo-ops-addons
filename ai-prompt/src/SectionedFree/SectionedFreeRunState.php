<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Persisted run state for sectioned_free — reusable PromptChunkLedger-compatible shape.
 *
 * @phpstan-type SectionRow array{
 *   section_id: string,
 *   section_order: int,
 *   label: string,
 *   role: string,
 *   status: string,
 *   output: ?string,
 *   word_count: int,
 *   model: ?string,
 *   provider: ?string,
 *   connection_id: ?int,
 *   attempt_count: int,
 *   first_attempt_success: bool,
 *   fallback_count: int,
 *   input_hash: string,
 *   requested_scope: string,
 *   error: ?string,
 *   updated_at: ?string
 * }
 */
final class SectionedFreeRunState
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  array{
     *   run_id?: string,
     *   generation_strategy?: string,
     *   isolation_mode?: string,
     *   model_tier?: string,
     *   sections?: array<string, SectionRow>,
     *   metrics?: array<string, mixed>
     * }  $state
     */
    public function __construct(
        private array $state = [],
    ) {
        $this->state['sections'] = is_array($this->state['sections'] ?? null) ? $this->state['sections'] : [];
        $this->state['metrics'] = is_array($this->state['metrics'] ?? null) ? $this->state['metrics'] : [];
        $this->state['generation_strategy'] = (string) ($this->state['generation_strategy'] ?? 'sectioned_free');
        $this->state['isolation_mode'] = (string) ($this->state['isolation_mode'] ?? 'free_test');
        $this->state['model_tier'] = (string) ($this->state['model_tier'] ?? 'free');
    }

    public static function fromArray(?array $raw): self
    {
        return new self(is_array($raw) ? $raw : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->state;
    }

    public function setRun(string $runId): void
    {
        $this->state['run_id'] = $runId;
    }

    public function planSection(SectionedFreeSectionUnit $unit): void
    {
        $existing = $this->state['sections'][$unit->sectionId] ?? null;
        if (is_array($existing) && ($existing['status'] ?? '') === self::STATUS_COMPLETED) {
            // Keep successful output; refresh metadata that does not wipe output.
            $this->state['sections'][$unit->sectionId] = array_merge($existing, [
                'section_order' => $unit->order,
                'label' => $unit->label,
                'role' => $unit->role,
                'input_hash' => $unit->inputHash(),
                'requested_scope' => $unit->scopeMarkdown(),
            ]);

            return;
        }

        $this->state['sections'][$unit->sectionId] = [
            'section_id' => $unit->sectionId,
            'section_order' => $unit->order,
            'label' => $unit->label,
            'role' => $unit->role,
            'status' => self::STATUS_PENDING,
            'output' => null,
            'word_count' => 0,
            'model' => null,
            'provider' => null,
            'connection_id' => null,
            'attempt_count' => 0,
            'first_attempt_success' => false,
            'fallback_count' => 0,
            'input_hash' => $unit->inputHash(),
            'requested_scope' => $unit->scopeMarkdown(),
            'error' => null,
            'updated_at' => gmdate('c'),
        ];
    }

    public function markRunning(string $sectionId): void
    {
        if (! isset($this->state['sections'][$sectionId])) {
            return;
        }
        $this->state['sections'][$sectionId]['status'] = self::STATUS_RUNNING;
        $this->state['sections'][$sectionId]['updated_at'] = gmdate('c');
    }

    /**
     * @param  array{
     *   model?: string|null,
     *   provider?: string|null,
     *   connection_id?: int|null,
     *   attempt_count?: int,
     *   fallback_count?: int,
     *   first_attempt_success?: bool
     * }  $meta
     */
    public function markCompleted(string $sectionId, string $output, int $wordCount, array $meta = []): void
    {
        if (! isset($this->state['sections'][$sectionId])) {
            return;
        }
        $row = $this->state['sections'][$sectionId];
        $this->state['sections'][$sectionId] = array_merge($row, [
            'status' => self::STATUS_COMPLETED,
            'output' => $output,
            'word_count' => $wordCount,
            'model' => $meta['model'] ?? $row['model'] ?? null,
            'provider' => $meta['provider'] ?? $row['provider'] ?? null,
            'connection_id' => $meta['connection_id'] ?? $row['connection_id'] ?? null,
            'attempt_count' => (int) ($meta['attempt_count'] ?? $row['attempt_count'] ?? 1),
            'fallback_count' => (int) ($meta['fallback_count'] ?? $row['fallback_count'] ?? 0),
            'first_attempt_success' => (bool) ($meta['first_attempt_success'] ?? $row['first_attempt_success'] ?? false),
            'prompt_character_count' => $meta['prompt_character_count'] ?? $row['prompt_character_count'] ?? null,
            'target_words' => $meta['target_words'] ?? $row['target_words'] ?? null,
            'minimum_words' => $meta['minimum_words'] ?? $row['minimum_words'] ?? null,
            'error' => null,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function markFailed(string $sectionId, string $error, int $attemptCount = 0): void
    {
        if (! isset($this->state['sections'][$sectionId])) {
            return;
        }
        $this->state['sections'][$sectionId]['status'] = self::STATUS_FAILED;
        $this->state['sections'][$sectionId]['error'] = $error;
        if ($attemptCount > 0) {
            $this->state['sections'][$sectionId]['attempt_count'] = $attemptCount;
        }
        $this->state['sections'][$sectionId]['updated_at'] = gmdate('c');
    }

    public function invalidateSection(string $sectionId): void
    {
        if (! isset($this->state['sections'][$sectionId])) {
            return;
        }
        $row = $this->state['sections'][$sectionId];
        $this->state['sections'][$sectionId] = array_merge($row, [
            'status' => self::STATUS_PENDING,
            'output' => null,
            'word_count' => 0,
            'error' => null,
            'attempt_count' => 0,
            'fallback_count' => 0,
            'first_attempt_success' => false,
            'model' => null,
            'provider' => null,
            'connection_id' => null,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function isCompleted(string $sectionId): bool
    {
        $row = $this->state['sections'][$sectionId] ?? null;

        return is_array($row)
            && ($row['status'] ?? '') === self::STATUS_COMPLETED
            && is_string($row['output'] ?? null)
            && trim((string) $row['output']) !== '';
    }

    /**
     * @return list<SectionRow>
     */
    public function sectionsSorted(): array
    {
        $rows = array_values($this->state['sections']);
        usort(
            $rows,
            static fn (array $a, array $b): int => ((int) ($a['section_order'] ?? 0)) <=> ((int) ($b['section_order'] ?? 0)),
        );

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function setMetrics(array $metrics): void
    {
        $this->state['metrics'] = $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        return is_array($this->state['metrics'] ?? null) ? $this->state['metrics'] : [];
    }

    /**
     * Trace nodes for AI History / Workflow Graph.
     *
     * @return list<array<string, mixed>>
     */
    public function traceChildren(): array
    {
        $children = [];
        foreach ($this->sectionsSorted() as $row) {
            $children[] = [
                'type' => 'sectioned_free_section',
                'section_id' => $row['section_id'],
                'section_order' => $row['section_order'],
                'label' => $row['label'],
                'status' => $row['status'],
                'word_count' => $row['word_count'],
                'model' => $row['model'],
                'provider' => $row['provider'],
                'connection_id' => $row['connection_id'],
                'attempt_count' => $row['attempt_count'],
                'fallback_count' => $row['fallback_count'],
            ];
        }

        return $children;
    }
}
