<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Illuminate\Support\Carbon;

/**
 * Kết quả «Lần cuối lưu» — không chỉ Carbon.
 */
final class ArticleLastContentChange
{
    public function __construct(
        public readonly ?Carbon $occurredAt,
        public readonly ?string $source,
        public readonly ?string $sourceLabel = null,
        public readonly string $display = '—',
        public readonly string $relative = '—',
        public readonly ?string $absolute = null,
    ) {}

    /**
     * @return array{
     *     occurred_at: Carbon|null,
     *     at: Carbon|null,
     *     source: string|null,
     *     source_label: string|null,
     *     display: string,
     *     relative: string,
     *     absolute: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'occurred_at' => $this->occurredAt,
            'at' => $this->occurredAt,
            'source' => $this->source,
            'source_label' => $this->sourceLabel,
            'display' => $this->display,
            'relative' => $this->relative,
            'absolute' => $this->absolute,
        ];
    }
}
