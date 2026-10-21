<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\ConfigurationCanonicalizer;

/**
 * Compares a live full-configuration export with the local Golden baseline.
 * Connection-skeleton credential/status noise is classified only after an explicit check.
 * Anything else that is not a prompt, workflow, or non-connection settings change stays unresolved.
 */
final class LiveConfigurationComparer
{
    public function __construct(
        private readonly ConfigurationCanonicalizer $canonicalizer = new ConfigurationCanonicalizer(),
        private readonly ConfigurationDiffBuilder $diffs = new ConfigurationDiffBuilder(),
    ) {}

    /**
     * @param  array<string, mixed>  $golden
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    public function compare(array $golden, array $live): array
    {
        $goldenCanonical = $this->canonicalizer->canonicalize($golden);
        $liveCanonical = $this->canonicalizer->canonicalize($live);
        $baselineHash = $this->canonicalizer->fingerprint($golden);
        $currentHash = $this->canonicalizer->fingerprint($live);

        if (hash_equals($baselineHash, $currentHash)) {
            return $this->result('in_sync', 'exact', $baselineHash, $currentHash, $this->emptyDiff());
        }

        $goldenNeutral = $this->canonicalizer->canonicalize($this->neutralizeNonSemanticEnvelope($goldenCanonical));
        $liveNeutral = $this->canonicalizer->canonicalize($this->neutralizeNonSemanticEnvelope($liveCanonical));
        $neutralBaseline = $this->canonicalizer->fingerprint($goldenNeutral);
        $neutralCurrent = $this->canonicalizer->fingerprint($liveNeutral);
        $diff = $this->diffs->build($goldenNeutral, $liveNeutral);

        if (hash_equals($neutralBaseline, $neutralCurrent)) {
            return $this->result('in_sync', 'non_semantic_envelope', $baselineHash, $currentHash, $this->emptyDiff());
        }

        if ($this->diffs->isEmpty($diff)) {
            return $this->result(
                'comparison_unresolved',
                'unclassified_difference',
                $baselineHash,
                $currentHash,
                $this->emptyDiff(),
            );
        }

        return $this->result('customized', 'semantic_difference', $baselineHash, $currentHash, $diff);
    }

    /**
     * Export envelope and connection skeletons are not customer customizations.
     * meta.kind is golden_configuration on the package and configuration_backup on a live export.
     * Imported connections are stored without secrets, so credential.configured and active/inactive flip.
     *
     * @param  array<string, mixed>  $canonical
     * @return array<string, mixed>
     */
    private function neutralizeNonSemanticEnvelope(array $canonical): array
    {
        if (isset($canonical['meta']) && is_array($canonical['meta'])) {
            unset($canonical['meta']['kind']);
        }

        $connections = $canonical['settings']['ai']['connections'] ?? null;
        if (! is_array($connections)) {
            return $canonical;
        }
        foreach ($connections as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['credential'] = [
                'configured' => false,
                'exported' => false,
            ];
            $status = (string) ($row['status'] ?? '');
            if ($status === 'active' || $status === 'inactive') {
                $row['status'] = 'inactive';
            }
            $connections[$index] = $row;
        }
        $canonical['settings']['ai']['connections'] = $connections;

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $diff
     * @return array<string, mixed>
     */
    private function result(string $outcome, string $reason, string $baselineHash, string $currentHash, array $diff): array
    {
        return [
            'outcome' => $outcome,
            'reason' => $reason,
            'baseline_hash' => $baselineHash,
            'current_hash' => $currentHash,
            'diff' => $diff,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDiff(): array
    {
        return [
            'settings' => [],
            'prompts' => [],
            'workflows' => [],
        ];
    }
}
