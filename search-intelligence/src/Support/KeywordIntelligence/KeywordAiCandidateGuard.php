<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordAiCandidateGuard
{
    public function __construct(
        private readonly KeywordNormalizer $normalizer = new KeywordNormalizer(),
        private readonly KeywordRuleClassifier $classifier = new KeywordRuleClassifier(),
        private readonly KeywordCanonicalizer $canonicalizer = new KeywordCanonicalizer(),
        private readonly KeywordClusterKey $clusterKey = new KeywordClusterKey(),
    ) {}

    /**
     * @param  list<string>  $candidates
     * @param  list<array{normalized_text: string, folded_text: string, cluster_key?: string, seo_intent?: string}>  $existing
     * @return list<array<string, mixed>>
     */
    public function evaluate(array $candidates, array $existing, string $sourceKind = KeywordSourceNormalizer::AI_GENERATED): array
    {
        $out = [];
        foreach ($candidates as $raw) {
            $norm = $this->normalizer->normalize($raw);
            $classified = $this->classifier->classify($raw, $norm['normalized_text'], [
                'source_kind' => $sourceKind,
            ]);
            $cluster = $this->clusterKey->make($norm['normalized_text'], $norm['folded_text']);
            $decision = 'accept';
            $duplicateOf = null;
            $reason = 'new_valid';

            if (! $classified['is_seo_keyword'] || in_array($classified['phrase_kind'], ['sentence', 'noise', 'url_domain', 'descriptive_phrase'], true)) {
                $decision = 'reject';
                $reason = $classified['phrase_kind'] === 'sentence' ? 'sentence' : 'not_seo_keyword';
            } else {
                foreach ($existing as $row) {
                    $folded = (string) ($row['folded_text'] ?? '');
                    $normalized = (string) ($row['normalized_text'] ?? '');
                    if ($folded !== '' && $this->canonicalizer->isNearDuplicate($norm['folded_text'], $folded)) {
                        $decision = 'reject';
                        $duplicateOf = $normalized !== '' ? $normalized : $folded;
                        $reason = $norm['folded_text'] === $this->canonicalizer->exactKey($folded)
                            ? 'exact_canonical'
                            : 'near_duplicate';
                        break;
                    }
                    $existingCluster = (string) ($row['cluster_key'] ?? '');
                    $existingIntent = (string) ($row['seo_intent'] ?? '');
                    if ($existingCluster !== '' && $existingCluster === $cluster && $existingIntent !== '' && $existingIntent === $classified['seo_intent']) {
                        $decision = 'reject';
                        $duplicateOf = $normalized;
                        $reason = 'cluster_intent_represented';
                        break;
                    }
                }
            }

            $out[] = [
                'raw' => $raw,
                'normalized_text' => $norm['normalized_text'],
                'folded_text' => $norm['folded_text'],
                'phrase_kind' => $classified['phrase_kind'],
                'seo_intent' => $classified['seo_intent'],
                'cluster_key' => $cluster,
                'is_seo_keyword' => $classified['is_seo_keyword'],
                'decision' => $decision,
                'reason' => $reason,
                'duplicate_of' => $duplicateOf,
                'novelty' => $decision === 'accept' ? 0.8 : 0.1,
                'duplicate_risk' => $decision === 'reject' && str_contains($reason, 'duplicate') ? 0.9 : 0.2,
            ];
        }

        return $out;
    }
}
