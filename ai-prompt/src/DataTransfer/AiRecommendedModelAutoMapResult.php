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
        public readonly array $byArea = [],
    ) {}

    /**
     * @return array{
     *   scanned: int,
     *   recognized: int,
     *   enabled: int,
     *   already_enabled: int,
     *   manual_preserved: int,
     *   manual_disabled_preserved: int,
     *   unknown: int,
     *   unsupported: int,
     *   by_area: array<string, int>
     * }
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
            'by_area' => $this->byArea,
        ];
    }

    public function notificationBody(): string
    {
        $lines = [
            $this->recognized.' recognized',
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
            byArea: $byArea,
        );
    }
}
