<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;

final class EvidenceContextBuilder
{
    public function __construct(
        private readonly int $maxSources = 5,
        private readonly int $maxRecordsPerList = 25,
        private readonly int $maxEvidenceBytes = 120_000,
    ) {}

    public function build(RetrievalBundle $bundle): ContextBuildResult
    {
        $sources = [];
        $seen = [];
        $limitations = $bundle->warnings;
        $included = 0;
        $omitted = 0;

        foreach ($bundle->sources as $source) {
            if (! $source instanceof RetrievalSource) {
                continue;
            }
            $identity = $source->name.'|'.$source->request;
            if (isset($seen[$identity])) {
                $limitations[] = 'duplicate_source_removed:'.$source->name;
                continue;
            }
            $seen[$identity] = true;
            if ($source->status !== 'ok') {
                $limitations[] = 'source_'.$source->status.':'.$source->name.($source->reason === null ? '' : ':'.$source->reason);
            }
            if (count($sources) >= $this->maxSources) {
                $limitations[] = 'source_budget_reached';
                break;
            }

            [$data, $sourceIncluded, $sourceOmitted] = $this->boundValue($source->data);
            $entities = $this->entityCounts($source->data, $data);
            if (is_array($data) && ($sourceIncluded > 0 || $sourceOmitted > 0 || $this->sourceTotal($source->data) !== null)) {
                $data['_context_meta'] = [
                    'truncated' => $sourceOmitted > 0,
                    'stage' => 'evidence_context',
                    'scope' => $bundle->scope->siteRef,
                    'source_total' => $this->sourceTotal($source->data),
                    'requested' => $this->requested($source->data, $source->request),
                    'matched' => $this->sourceTotal($source->data),
                    'retrieved' => $entities['retrieved'],
                    'included' => $entities['included'],
                    'omitted' => $entities['omitted'],
                    'nested' => [
                        'included' => $sourceIncluded - $entities['included'],
                        'omitted' => $sourceOmitted - $entities['omitted'],
                    ],
                    'records_included' => $sourceIncluded,
                    'records_omitted' => $sourceOmitted,
                    'selection_reason' => 'source_order_with_nested_list_budget',
                    'next_page' => $this->nextPage($source->data),
                ];
                if ($sourceOmitted > 0) {
                    $limitations[] = 'records_truncated:'.$source->name;
                }
            }

            $candidate = new RetrievalSource($source->name, $source->status, $source->request, is_array($data) ? $data : [], $source->reason);
            $candidateSources = [...$sources, $candidate];
            $bytes = $this->bytes($bundle, $candidateSources);
            if ($bytes > $this->maxEvidenceBytes) {
                $limitations[] = 'evidence_byte_budget_reached:'.$source->name;
                continue;
            }

            $sources[] = $candidate;
            $included += $sourceIncluded;
            $omitted += $sourceOmitted;
        }

        $bounded = new RetrievalBundle($bundle->scope, $sources, array_values(array_unique($limitations)));

        return new ContextBuildResult(
            $bounded,
            array_values(array_unique($limitations)),
            count($sources),
            $this->bytes($bundle, $sources),
            $included,
            $omitted,
        );
    }

    /** @return array{retrieved: int, included: int, omitted: int} */
    private function entityCounts(array $before, array $after): array
    {
        foreach (['items', 'articles', 'topics', 'links', 'projects'] as $key) {
            if (! isset($before[$key]) || ! is_array($before[$key]) || ! array_is_list($before[$key])) {
                continue;
            }
            $retrieved = count($before[$key]);
            $included = count($after[$key] ?? []);

            return ['retrieved' => $retrieved, 'included' => $included, 'omitted' => $retrieved - $included];
        }

        return ['retrieved' => 0, 'included' => 0, 'omitted' => 0];
    }

    /** @return array{mixed, int, int} */
    private function boundValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [$value, 0, 0];
        }

        if (array_is_list($value)) {
            $total = count($value);
            $slice = array_slice($value, 0, $this->maxRecordsPerList);
            $out = [];
            $included = 0;
            $omitted = max(0, $total - count($slice));
            foreach ($slice as $item) {
                [$bounded, $nestedIncluded, $nestedOmitted] = $this->boundValue($item);
                $out[] = $bounded;
                $included += 1 + $nestedIncluded;
                $omitted += $nestedOmitted;
            }

            return [$out, $included, $omitted];
        }

        $out = [];
        $included = 0;
        $omitted = 0;
        foreach ($value as $key => $item) {
            [$bounded, $nestedIncluded, $nestedOmitted] = $this->boundValue($item);
            $out[$key] = $bounded;
            $included += $nestedIncluded;
            $omitted += $nestedOmitted;
        }

        return [$out, $included, $omitted];
    }

    /** @param list<RetrievalSource> $sources */
    private function bytes(RetrievalBundle $original, array $sources): int
    {
        $encoded = json_encode(new RetrievalBundle($original->scope, $sources, $original->warnings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? strlen($encoded) : 0;
    }

    /** @param array<string, mixed> $data */
    private function sourceTotal(array $data): ?int
    {
        $value = $data['pagination']['total'] ?? $data['total'] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $data */
    private function requested(array $data, string $request): ?int
    {
        $value = $data['pagination']['requested'] ?? null;
        if (is_numeric($value)) return (int) $value;
        parse_str((string) parse_url($request, PHP_URL_QUERY), $query);
        foreach (['limit', 'per_page'] as $key) if (is_numeric($query[$key] ?? null)) return (int) $query[$key];
        return null;
    }

    /** @param array<string, mixed> $data */
    private function nextPage(array $data): ?int
    {
        $page = $data['pagination']['page'] ?? null;
        $pages = $data['pagination']['total_pages'] ?? null;
        return is_numeric($page) && is_numeric($pages) && (int) $page < (int) $pages ? (int) $page + 1 : null;
    }
}
