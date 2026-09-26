<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice;

/**
 * Deterministic request for one context slice.
 */
final class ContextSliceRequest
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $key,
        public readonly ContextView $view = ContextView::Summary,
        public readonly array $parameters = [],
    ) {}

    public function periodKey(): ?string
    {
        $period = $this->parameters['period'] ?? $this->parameters['period_key'] ?? null;

        return is_string($period) && $period !== '' ? $period : null;
    }

    public function limit(?int $default = null): ?int
    {
        if (! array_key_exists('limit', $this->parameters)) {
            return $default;
        }
        if (! is_numeric($this->parameters['limit'])) {
            return $default;
        }

        return max(1, min(200, (int) $this->parameters['limit']));
    }

    public function keywordRef(): ?string
    {
        $ref = $this->parameters['keyword_ref'] ?? null;
        if (is_string($ref) && $ref !== '') {
            return $ref;
        }
        if (isset($this->parameters['keyword_id']) && is_numeric($this->parameters['keyword_id'])) {
            return 'keyword:'.(int) $this->parameters['keyword_id'];
        }

        return null;
    }

    /**
     * @return array{keyword_ref?: string, keyword_id?: int}
     */
    public function keywordInput(): array
    {
        $input = [];
        if (isset($this->parameters['keyword_id']) && is_numeric($this->parameters['keyword_id'])) {
            $input['keyword_id'] = (int) $this->parameters['keyword_id'];
        }
        $ref = $this->keywordRef();
        if ($ref !== null) {
            $input['keyword_ref'] = $ref;
        }

        return $input;
    }

    /**
     * Optional allowlisted section filter (keywords.relationship).
     * Null means "all sections allowed by the current view".
     *
     * @return list<string>|null
     */
    public function sections(): ?array
    {
        if (! array_key_exists('sections', $this->parameters)) {
            return null;
        }

        $raw = $this->parameters['sections'];
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('sections must be a list of strings.');
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new \InvalidArgumentException('sections must be a list of strings.');
            }
            $out[] = trim($item);
        }

        return array_values(array_unique($out));
    }
}
