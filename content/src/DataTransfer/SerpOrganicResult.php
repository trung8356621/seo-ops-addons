<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\DataTransfer;

final readonly class SerpOrganicResult
{
    public function __construct(
        public int $position,
        public string $title,
        public string $link,
        public ?string $displayedLink,
        public ?string $snippet,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item, int $fallbackPosition): self
    {
        $position = isset($item['position']) && is_numeric($item['position'])
            ? (int) $item['position']
            : $fallbackPosition;

        return new self(
            position: max(1, $position),
            title: (string) ($item['title'] ?? ''),
            link: (string) ($item['link'] ?? $item['url'] ?? ''),
            displayedLink: isset($item['displayed_link']) ? (string) $item['displayed_link'] : (isset($item['displayLink']) ? (string) $item['displayLink'] : null),
            snippet: isset($item['snippet']) ? (string) $item['snippet'] : null,
        );
    }
}
