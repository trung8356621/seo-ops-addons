<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningAttributionWriter;

/**
 * Gate note-driven AI candidates against allowed cluster_ref set.
 * Multi-cluster batches must not silently persist unattributed/wrong-cluster rows.
 */
final class NewContentClusterAttributionValidator
{
    public const CODE_ATTRIBUTION_INVALID = 'structured_attribution_invalid';

    public function __construct(
        private readonly PlanningAttributionWriter $attributionWriter = new PlanningAttributionWriter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $noteItems
     * @return array{
     *   accepted: list<array<string, mixed>>,
     *   rejected: list<array<string, mixed>>,
     *   allowed_refs: list<string>,
     *   requires_attribution: bool,
     *   multi_cluster: bool
     * }
     */
    public function filter(array $candidates, array $noteItems): array
    {
        $allowed = $this->allowedClusterRefs($noteItems);
        $requiresAttribution = $allowed !== [];
        $multiCluster = count($allowed) > 1;
        $singleRef = count($allowed) === 1 ? $allowed[0] : null;
        $allowedMap = array_fill_keys($allowed, true);

        $accepted = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                $rejected[] = ['reason' => 'not_array'];

                continue;
            }

            if (! $requiresAttribution) {
                $accepted[] = $candidate;

                continue;
            }

            $clusterRef = trim((string) ($candidate['cluster_ref'] ?? ''));
            if ($clusterRef === '' && $singleRef !== null) {
                $candidate['cluster_ref'] = $singleRef;
                $clusterRef = $singleRef;
            }

            if ($clusterRef === '' || ! isset($allowedMap[$clusterRef])) {
                $rejected[] = $candidate + ['_reject_reason' => self::CODE_ATTRIBUTION_INVALID];

                continue;
            }

            $dna = $this->attributionWriter->normalizeDnaPhrases(
                is_array($candidate['dna_phrases'] ?? null) ? $candidate['dna_phrases'] : [],
            );
            $candidate['cluster_ref'] = $clusterRef;
            $candidate['dna_phrases'] = $dna;
            $accepted[] = $candidate;
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'allowed_refs' => $allowed,
            'requires_attribution' => $requiresAttribution,
            'multi_cluster' => $multiCluster,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $noteItems
     * @return list<string>
     */
    public function allowedClusterRefs(array $noteItems): array
    {
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $ref = trim((string) ($item['cluster_ref'] ?? ''));
            if ($ref === '' || isset($seen[$ref])) {
                continue;
            }
            // Manual seeds use synthetic refs — still attribution targets.
            $seen[$ref] = true;
            $out[] = $ref;
        }

        return $out;
    }
}
