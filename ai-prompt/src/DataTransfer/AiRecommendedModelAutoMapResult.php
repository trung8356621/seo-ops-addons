<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

/**
 * Structured result from {@see \Omnichannel\Addons\AiPrompt\Services\AiRecommendedModelMapper}.
 */
final class AiRecommendedModelAutoMapResult
{
    /**
     * @param  array<string, int>  $byArea
     * @param  array<int, array<string, mixed>>  $byConnection
     * @param  list<array<string, mixed>>  $modelSamples
     */
    public function __construct(
        public readonly int $scanned = 0,
        public readonly int $recognized = 0,
        public readonly int $enabled = 0,
        public readonly int $alreadyEnabled = 0,
        public readonly int $manualPreserved = 0,
        public readonly int $manualDisabledPreserved = 0,
        public readonly int $unknown = 0,
        public readonly int $unsupported = 0,
        public readonly int $heuristicRanked = 0,
        public readonly int $curatedMatched = 0,
        public readonly int $rejected = 0,
        public readonly array $byArea = [],
        public readonly array $byConnection = [],
        public readonly array $modelSamples = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'recognized' => $this->recognized,
            'enabled' => $this->enabled,
            'already_enabled' => $this->alreadyEnabled,
            'manual_preserved' => $this->manualPreserved,
            'manual_disabled_preserved' => $this->manualDisabledPreserved,
            'unknown' => $this->unknown,
            'unsupported' => $this->unsupported,
            'heuristic_ranked' => $this->heuristicRanked,
            'curated_matched' => $this->curatedMatched,
            'rejected' => $this->rejected,
            'by_area' => $this->byArea,
            'by_connection' => $this->byConnection,
            'model_samples' => $this->modelSamples,
        ];
    }

    public function notificationBody(): string
    {
        $lines = [
            $this->curatedMatched.' curated matched',
            $this->heuristicRanked.' heuristic ranked',
            $this->enabled.' enabled',
            $this->alreadyEnabled.' already configured',
        ];
        $manual = $this->manualPreserved + $this->manualDisabledPreserved;
        if ($manual > 0) {
            $lines[] = $manual.' manual choice preserved';
        }
        if ($this->unknown > 0) {
            $lines[] = $this->unknown.' unknown remain Available';
        }
        if ($this->rejected > 0) {
            $lines[] = $this->rejected.' rejected';
        }
        if ($this->unsupported > 0) {
            $lines[] = $this->unsupported.' unsupported skipped';
        }

        return implode("\n", array_map(
            static fn (string $line): string => '- '.$line,
            $lines,
        ));
    }

    public function withIncrement(
        string $field,
        int $amount = 1,
        ?string $areaKey = null,
    ): self {
        $byArea = $this->byArea;
        if ($areaKey !== null && $field === 'enabled') {
            $byArea[$areaKey] = ($byArea[$areaKey] ?? 0) + $amount;
        }

        return new self(
            scanned: $field === 'scanned' ? $this->scanned + $amount : $this->scanned,
            recognized: $field === 'recognized' ? $this->recognized + $amount : $this->recognized,
            enabled: $field === 'enabled' ? $this->enabled + $amount : $this->enabled,
            alreadyEnabled: $field === 'already_enabled' ? $this->alreadyEnabled + $amount : $this->alreadyEnabled,
            manualPreserved: $field === 'manual_preserved' ? $this->manualPreserved + $amount : $this->manualPreserved,
            manualDisabledPreserved: $field === 'manual_disabled_preserved' ? $this->manualDisabledPreserved + $amount : $this->manualDisabledPreserved,
            unknown: $field === 'unknown' ? $this->unknown + $amount : $this->unknown,
            unsupported: $field === 'unsupported' ? $this->unsupported + $amount : $this->unsupported,
            heuristicRanked: $field === 'heuristic_ranked' ? $this->heuristicRanked + $amount : $this->heuristicRanked,
            curatedMatched: $field === 'curated_matched' ? $this->curatedMatched + $amount : $this->curatedMatched,
            rejected: $field === 'rejected' ? $this->rejected + $amount : $this->rejected,
            byArea: $byArea,
            byConnection: $this->byConnection,
            modelSamples: $this->modelSamples,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $byConnection
     * @param  list<array<string, mixed>>  $samples
     */
    public function withDiagnostics(array $byConnection, array $samples): self
    {
        return new self(
            scanned: $this->scanned,
            recognized: $this->recognized,
            enabled: $this->enabled,
            alreadyEnabled: $this->alreadyEnabled,
            manualPreserved: $this->manualPreserved,
            manualDisabledPreserved: $this->manualDisabledPreserved,
            unknown: $this->unknown,
            unsupported: $this->unsupported,
            heuristicRanked: $this->heuristicRanked,
            curatedMatched: $this->curatedMatched,
            rejected: $this->rejected,
            byArea: $this->byArea,
            byConnection: $byConnection,
            modelSamples: $samples,
        );
    }
}
