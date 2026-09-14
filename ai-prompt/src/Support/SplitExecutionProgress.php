<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Presentation + resume read-model for SPLIT / sectioned writing progress.
 * Technical PromptResult.status may remain "failed"; UI uses presentation_state.
 *
 * @phpstan-type SectionRow array{
 *   section_id: string,
 *   label: string,
 *   status: string,
 *   order: int,
 *   reusable: bool,
 *   retryable: bool
 * }
 */
final class SplitExecutionProgress
{
    public const PRESENTATION_SUCCESS = 'success';

    public const PRESENTATION_RUNNING = 'running';

    public const PRESENTATION_PARTIAL_FAILED = 'partial_failed';

    public const PRESENTATION_FAILED_BEFORE_ANY_PROGRESS = 'failed_before_any_progress';

    public const PRESENTATION_PENDING = 'pending';

    public const PRESENTATION_ASSEMBLING = 'assembling';

    public const ASSEMBLE_SUCCESS = 'success';

    public const ASSEMBLE_PENDING = 'pending';

    public const ASSEMBLE_NOT_RUN = 'not_run';

    public const ASSEMBLE_FAILED = 'failed';

    public const ASSEMBLE_RUNNING = 'running';

    /**
     * @param  list<SectionRow>  $sections
     */
    public function __construct(
        public readonly int $total,
        public readonly int $completed,
        public readonly int $failed,
        public readonly int $pending,
        public readonly int $running,
        public readonly int $reusable,
        public readonly int $retryable,
        public readonly string $assembleStatus,
        public readonly string $presentationState,
        public readonly array $sections = [],
        public readonly ?string $failedSectionId = null,
        public readonly bool $technicalFailed = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $runState  SectionedFreeRunState::toArray()
     * @param  list<array{status?: string, section_id?: string, section_order?: int, prompt_name?: string}>  $childRows
     * @param  array<string, mixed>|null  $failurePayload  sectioned_free_failure
     */
    public static function fromPersisted(
        ?array $runState,
        array $childRows = [],
        ?array $failurePayload = null,
        ?string $parentStatus = null,
        ?array $breadcrumbs = null,
        int $plannedTotal = 0,
    ): self {
        $sections = [];
        $stateSections = is_array($runState['sections'] ?? null) ? $runState['sections'] : [];

        if ($stateSections !== []) {
            foreach ($stateSections as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
                $id = trim((string) ($row['section_id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $sections[] = [
                    'section_id' => $id,
                    'label' => trim((string) ($row['label'] ?? $id)),
                    'status' => $status,
                    'order' => (int) ($row['section_order'] ?? 0),
                    'reusable' => $status === 'completed',
                    'retryable' => $status === 'failed',
                ];
            }
        } elseif ($childRows !== []) {
            foreach ($childRows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
                $mapped = match (true) {
                    in_array($status, ['success', 'completed'], true) => 'completed',
                    in_array($status, ['failed', 'error'], true) => 'failed',
                    in_array($status, ['running', 'processing', 'pending'], true) => $status === 'pending' ? 'pending' : 'running',
                    default => 'pending',
                };
                $id = trim((string) ($row['section_id'] ?? ''));
                if ($id === '') {
                    $id = 'section_'.((int) ($row['section_order'] ?? $index) + 1);
                }
                $sections[] = [
                    'section_id' => $id,
                    'label' => trim((string) ($row['prompt_name'] ?? $row['label'] ?? $id)),
                    'status' => $mapped,
                    'order' => (int) ($row['section_order'] ?? $index),
                    'reusable' => $mapped === 'completed',
                    'retryable' => $mapped === 'failed',
                ];
            }
        }

        usort($sections, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $completed = 0;
        $failed = 0;
        $pending = 0;
        $running = 0;
        $failedSectionId = null;
        foreach ($sections as $row) {
            match ($row['status']) {
                'completed' => $completed++,
                'failed' => $failed++,
                'running' => $running++,
                default => $pending++,
            };
            if ($failedSectionId === null && $row['status'] === 'failed') {
                $failedSectionId = $row['section_id'];
            }
        }

        if ($failedSectionId === null && is_array($failurePayload)) {
            $failedSectionId = trim((string) ($failurePayload['section_id'] ?? '')) ?: null;
        }

        $totalFromFailure = is_array($failurePayload)
            ? (int) ($failurePayload['total_sections'] ?? 0)
            : 0;
        $completedFromFailure = is_array($failurePayload)
            ? (int) ($failurePayload['completed_sections'] ?? 0)
            : 0;

        $total = max(count($sections), $plannedTotal, $totalFromFailure);
        if ($sections === [] && $totalFromFailure > 0) {
            // Failure payload alone: one failed section + completed; remainder is pending — never
            // treat all unfinished sections as failed (would look like total failure).
            $completed = max(0, $completedFromFailure);
            $failedFromPayload = is_array($failurePayload)
                ? (int) ($failurePayload['failed_sections'] ?? 0)
                : 0;
            $failed = max(1, $failedFromPayload);
            $pending = max(0, $total - $completed - $failed);
        } elseif ($total > count($sections) && $sections !== []) {
            $pending += $total - count($sections);
        }

        $parent = strtolower(trim((string) ($parentStatus ?? '')));
        $technicalFailed = in_array($parent, ['failed', 'error'], true);
        $assembleStatus = self::resolveAssembleStatus(
            $breadcrumbs,
            $technicalFailed,
            $completed,
            $total,
            $failed,
            $running,
        );

        $presentation = self::resolvePresentationState(
            $technicalFailed,
            $completed,
            $failed,
            $pending,
            $running,
            $total,
            $assembleStatus,
            $parent,
        );

        return new self(
            total: max(0, $total),
            completed: max(0, $completed),
            failed: max(0, $failed),
            pending: max(0, $pending),
            running: max(0, $running),
            reusable: max(0, $completed),
            retryable: max(0, $failed),
            assembleStatus: $assembleStatus,
            presentationState: $presentation,
            sections: $sections,
            failedSectionId: $failedSectionId,
            technicalFailed: $technicalFailed,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_sections' => $this->total,
            'completed_sections' => $this->completed,
            'failed_sections' => $this->failed,
            'pending_sections' => $this->pending,
            'running_sections' => $this->running,
            'reusable_sections' => $this->reusable,
            'retryable_sections' => $this->retryable,
            'assemble_status' => $this->assembleStatus,
            'presentation_state' => $this->presentationState,
            'presentation_label' => $this->presentationLabel(),
            'summary' => $this->summary(),
            'resume_hint' => $this->resumeHint(),
            'failed_section_id' => $this->failedSectionId,
            'technical_failed' => $this->technicalFailed,
            'sections' => $this->sections,
            'cta' => $this->supportsResume() ? 'continue_from_failed_step' : null,
            'cta_label' => $this->supportsResume() ? 'Tiếp tục từ bước lỗi' : null,
        ];
    }

    public function presentationLabel(): string
    {
        return match ($this->presentationState) {
            self::PRESENTATION_SUCCESS => 'SUCCESS',
            self::PRESENTATION_RUNNING => 'RUNNING',
            self::PRESENTATION_ASSEMBLING => 'ASSEMBLING',
            self::PRESENTATION_PARTIAL_FAILED => 'INCOMPLETE',
            self::PRESENTATION_FAILED_BEFORE_ANY_PROGRESS => 'FAILED',
            self::PRESENTATION_PENDING => 'PENDING',
            default => strtoupper($this->presentationState),
        };
    }

    public function summary(): string
    {
        if ($this->presentationState === self::PRESENTATION_FAILED_BEFORE_ANY_PROGRESS) {
            return 'Thất bại ở bước đầu · 0/'.$this->total.' bước đã xong';
        }
        if ($this->presentationState === self::PRESENTATION_PARTIAL_FAILED) {
            $parts = ['Chưa hoàn tất · '.$this->completed.'/'.$this->total.' bước đã xong'];
            if ($this->failed > 0) {
                $parts[] = $this->failed.' bước lỗi';
            }
            if ($this->pending > 0) {
                $parts[] = $this->pending.' bước còn lại';
            }
            $assemble = match ($this->assembleStatus) {
                self::ASSEMBLE_SUCCESS => 'Assemble: xong',
                self::ASSEMBLE_RUNNING => 'Assemble: đang chạy',
                self::ASSEMBLE_FAILED => 'Assemble: lỗi',
                default => 'Assemble: chưa chạy',
            };

            return implode(' · ', $parts).' · '.$assemble;
        }
        if ($this->presentationState === self::PRESENTATION_SUCCESS) {
            return $this->completed.'/'.$this->total.' bước hoàn tất · Assemble: xong';
        }
        if ($this->presentationState === self::PRESENTATION_RUNNING
            || $this->presentationState === self::PRESENTATION_ASSEMBLING) {
            return 'Đang chạy · '.$this->completed.'/'.$this->total.' bước đã xong';
        }

        return $this->completed.'/'.$this->total.' bước';
    }

    public function resumeHint(): string
    {
        if (! $this->supportsResume()) {
            return '';
        }

        return 'Giữ lại '.$this->reusable.' bước đã hoàn tất. '
            .'Chạy lại '.$this->retryable.' bước lỗi'
            .($this->pending > 0 ? ' và tiếp tục '.$this->pending.' bước còn lại' : '')
            .'.';
    }

    public function supportsResume(): bool
    {
        return $this->presentationState === self::PRESENTATION_PARTIAL_FAILED
            || ($this->technicalFailed && $this->completed > 0);
    }

    /**
     * @param  list<array<string, mixed>>|null  $breadcrumbs
     */
    private static function resolveAssembleStatus(
        ?array $breadcrumbs,
        bool $technicalFailed,
        int $completed,
        int $total,
        int $failed,
        int $running,
    ): string {
        $events = [];
        foreach (is_array($breadcrumbs) ? $breadcrumbs : [] as $row) {
            if (is_array($row)) {
                $events[] = strtolower(trim((string) ($row['event'] ?? $row['type'] ?? '')));
            }
        }
        if (in_array('assemble_completed', $events, true)) {
            return self::ASSEMBLE_SUCCESS;
        }
        if (in_array('assemble_started', $events, true) && ! $technicalFailed) {
            return self::ASSEMBLE_RUNNING;
        }
        if ($technicalFailed && $failed > 0) {
            return self::ASSEMBLE_NOT_RUN;
        }
        if ($completed >= $total && $total > 0 && ! $technicalFailed) {
            return self::ASSEMBLE_SUCCESS;
        }
        if ($running > 0) {
            return self::ASSEMBLE_PENDING;
        }

        return self::ASSEMBLE_NOT_RUN;
    }

    private static function resolvePresentationState(
        bool $technicalFailed,
        int $completed,
        int $failed,
        int $pending,
        int $running,
        int $total,
        string $assembleStatus,
        string $parentStatus,
    ): string {
        if (in_array($parentStatus, ['running', 'processing', 'pending'], true) || $running > 0) {
            if ($assembleStatus === self::ASSEMBLE_RUNNING) {
                return self::PRESENTATION_ASSEMBLING;
            }

            return $completed > 0 || $failed > 0
                ? self::PRESENTATION_RUNNING
                : self::PRESENTATION_PENDING;
        }

        if ($assembleStatus === self::ASSEMBLE_SUCCESS && $failed === 0 && $completed >= $total && $total > 0) {
            return self::PRESENTATION_SUCCESS;
        }

        if ($technicalFailed || $failed > 0) {
            if ($completed <= 0) {
                return self::PRESENTATION_FAILED_BEFORE_ANY_PROGRESS;
            }

            return self::PRESENTATION_PARTIAL_FAILED;
        }

        if ($completed >= $total && $total > 0) {
            return self::PRESENTATION_SUCCESS;
        }

        return $pending > 0 ? self::PRESENTATION_PENDING : self::PRESENTATION_SUCCESS;
    }
}
