<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Kết quả phân tích cấu trúc thẻ &lt;a&gt; trong HTML bài viết.
 *
 * @phpstan-type SplitGroup array{href: string, count: int, sample: string}
 */
final class InlineLinkAnalysisResult
{
    /**
     * @param  list<SplitGroup>  $splitGroups
     * @param  list<string>  $invalidHrefs
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly int $anchorCount = 0,
        public readonly int $duplicateAdjacentCount = 0,
        public readonly int $nestedAnchorCount = 0,
        public readonly int $invalidHrefCount = 0,
        public readonly array $splitGroups = [],
        public readonly array $invalidHrefs = [],
        public readonly array $warnings = [],
    ) {}

    public function hasIssues(): bool
    {
        return $this->duplicateAdjacentCount > 0
            || $this->nestedAnchorCount > 0
            || $this->invalidHrefCount > 0;
    }

    /**
     * @return array{
     *     anchors: int,
     *     duplicate_adjacent_anchors: int,
     *     nested_anchors: int,
     *     invalid_href: int,
     *     split_groups: list<SplitGroup>,
     *     invalid_hrefs: list<string>,
     *     warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'anchors' => $this->anchorCount,
            'duplicate_adjacent_anchors' => $this->duplicateAdjacentCount,
            'nested_anchors' => $this->nestedAnchorCount,
            'invalid_href' => $this->invalidHrefCount,
            'split_groups' => $this->splitGroups,
            'invalid_hrefs' => $this->invalidHrefs,
            'warnings' => $this->warnings,
        ];
    }
}
