<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

/**
 * View-model helpers for Prompt list Usage column.
 *
 * @phpstan-type UsageRow array{type: string, label: string, detail: string, name: string}
 */
final class PromptPreviewSectionViewModel
{
    /**
     * @param  list<array{type: string, label: string, detail: string}>  $usages
     * @return list<UsageRow>
     */
    public function orderedUsages(array $usages): array
    {
        $normalized = [];
        foreach ($usages as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $label = trim((string) ($row['label'] ?? ''));
            $name = $this->displayNameFromLabel($label, $type);
            $normalized[] = [
                'type' => $type,
                'label' => $label,
                'detail' => (string) ($row['detail'] ?? ''),
                'name' => $name,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            $rank = static fn (string $type): int => match ($type) {
                'workflow' => 0,
                'settings' => 1,
                'agent' => 2,
                'quick_chat' => 3,
                default => 9,
            };
            $byType = $rank($a['type']) <=> $rank($b['type']);
            if ($byType !== 0) {
                return $byType;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $normalized;
    }

    /**
     * @param  list<UsageRow>  $ordered
     * @return array{badge: string, tooltip: ?string, kind: string}
     */
    public function badge(array $ordered): array
    {
        if ($ordered === []) {
            return $this->unusedBadge();
        }

        $tooltipLines = array_map(
            static fn (array $row): string => $row['label'],
            $ordered,
        );
        $tooltip = implode("\n", $tooltipLines);

        $workflows = array_values(array_filter($ordered, static fn (array $r): bool => $r['type'] === 'workflow'));
        $settings = array_values(array_filter($ordered, static fn (array $r): bool => $r['type'] === 'settings'));
        $wfCount = count($workflows);
        $setCount = count($settings);

        if ($wfCount > 0 && $setCount === 0) {
            return [
                'badge' => $this->singleKindBadge('Workflow', $wfCount),
                'tooltip' => $tooltip,
                'kind' => 'workflow',
            ];
        }

        if ($setCount > 0 && $wfCount === 0) {
            return [
                'badge' => $this->singleKindBadge('Settings', $setCount),
                'tooltip' => $tooltip,
                'kind' => 'settings',
            ];
        }

        return [
            'badge' => 'Workflow + Settings',
            'tooltip' => $tooltip,
            'kind' => 'mixed',
        ];
    }

    private function singleKindBadge(string $kind, int $count): string
    {
        if ($count <= 1) {
            return $kind;
        }

        return $kind.' +'.($count - 1);
    }

    /**
     * @param  list<UsageRow>  $ordered
     * @return array{badge: string, tooltip: ?string, kind: string}
     */
    private function unusedBadge(): array
    {
        return [
            'badge' => 'Unused',
            'tooltip' => null,
            'kind' => 'unused',
        ];
    }

    private function displayNameFromLabel(string $label, string $type): string
    {
        foreach (['Workflow: ', 'Settings: ', 'Agent: ', 'Quick Chat: '] as $prefix) {
            if (str_starts_with($label, $prefix)) {
                return trim(substr($label, strlen($prefix)));
            }
        }

        return $label !== '' ? $label : $type;
    }
}
