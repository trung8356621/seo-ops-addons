<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * @phpstan-type ChangeEntry array{type: string, href?: string, detail?: string}
 */
final class InlineLinkNormalizationResult
{
    /**
     * @param  list<ChangeEntry>  $changes
     */
    public function __construct(
        public readonly string $html,
        public readonly InlineLinkAnalysisResult $before,
        public readonly InlineLinkAnalysisResult $after,
        public readonly array $changes = [],
        public readonly bool $changed = false,
    ) {}

    /**
     * @return array{
     *     html: string,
     *     changed: bool,
     *     before: array<string, mixed>,
     *     after: array<string, mixed>,
     *     changes: list<ChangeEntry>
     * }
     */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'changed' => $this->changed,
            'before' => $this->before->toArray(),
            'after' => $this->after->toArray(),
            'changes' => $this->changes,
        ];
    }
}
