<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Semantic status một hàng bài trên Content Project Run.
 */
final class ContentProjectArticleRowStatus
{
    public const CODE_PENDING = 'pending';

    public const CODE_RUNNING = 'running';

    public const CODE_FAILED = 'failed';

    public const CODE_COMPLETED = 'completed';

    public const CODE_MANUAL_EDIT = 'manual_edit';

    public const CODE_IGNORED_STALE = 'ignored_stale';

    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly ?string $tooltip = null,
        public readonly ?string $stepLabel = null,
    ) {}

    /**
     * @return array{code: string, label: string, tooltip: ?string, step_label: ?string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'tooltip' => $this->tooltip,
            'step_label' => $this->stepLabel,
        ];
    }
}
