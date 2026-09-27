<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

/**
 * Numbers the answer model is allowed to chart or tabulate.
 * Unavailable sources are excluded so missing data cannot become zero.
 */
final class EvidenceNumberIndex
{
    /** @var array<string, true> */
    private array $values = [];

    public static function fromBundle(RetrievalBundle $bundle): self
    {
        $index = new self();
        foreach ($bundle->sources as $source) {
            if ($source->status !== 'ok') {
                continue;
            }
            if (array_key_exists('available', $source->data) && $source->data['available'] === false) {
                continue;
            }
            $index->walk($source->data);
        }

        return $index;
    }

    public function contains(int|float $value): bool
    {
        foreach ($this->keys($value) as $key) {
            if (isset($this->values[$key])) {
                return true;
            }
        }

        return false;
    }

    private function walk(mixed $node): void
    {
        if (is_int($node) || is_float($node)) {
            foreach ($this->keys($node) as $key) {
                $this->values[$key] = true;
            }

            return;
        }
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $child) {
            $this->walk($child);
        }
    }

    /**
     * @return list<string>
     */
    private function keys(int|float $value): array
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value && abs($value) < 1_000_000_000_000)) {
            return [(string) (int) $value];
        }

        $plain = rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.');

        return [$plain, (string) $value];
    }
}
