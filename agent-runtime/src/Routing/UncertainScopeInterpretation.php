<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

/**
 * Reads candidates the current router already produced.
 * It does not rescore, call a model, or authorize a tool.
 */
final class UncertainScopeInterpretation
{
    /**
     * @return array{kind: 'unrelated'}|array{kind: 'clarify', vi: string, en: string, refs: list<string>}
     */
    public function interpret(LocalToolRoute $route): array
    {
        if ($route->outcome !== 'none') {
            return ['kind' => 'unrelated'];
        }

        $labels = [];
        $refs = [];
        foreach ($this->candidates($route) as $candidate) {
            if (isset($labels[$candidate['ref']])) {
                continue;
            }
            $labels[$candidate['ref']] = $candidate['label'];
            $refs[] = $candidate['ref'];
            if (count($refs) === 2) {
                break;
            }
        }
        if ($refs === []) {
            return ['kind' => 'unrelated'];
        }

        $shown = array_values($labels);

        return [
            'kind' => 'clarify',
            'vi' => $this->question($shown, 'vi'),
            'en' => $this->question($shown, 'en'),
            'refs' => $refs,
        ];
    }

    /**
     * @return list<array{ref: string, label: string}>
     */
    private function candidates(LocalToolRoute $route): array
    {
        $rows = [];
        foreach ([
            $route->diagnostics['internal']['candidates'] ?? null,
            $route->diagnostics['global']['candidates'] ?? null,
            $route->matches,
        ] as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $ref = trim((string) ($row['operation'] ?? $row['ref'] ?? ''));
                $label = trim((string) ($row['example'] ?? $row['label'] ?? ''));
                if ($label === '') {
                    $label = $ref;
                }
                if ($ref === '') {
                    $ref = $label;
                }
                if ($ref === '' || $label === '') {
                    continue;
                }
                $rows[] = ['ref' => $ref, 'label' => $this->short($label)];
            }
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $labels
     */
    private function question(array $labels, string $language): string
    {
        if ($language === 'vi') {
            return count($labels) === 1
                ? 'Bạn muốn xem '.$labels[0].' không?'
                : 'Bạn muốn xem '.$labels[0].' hay '.$labels[1].'?';
        }

        return count($labels) === 1
            ? 'Do you want to see '.$labels[0].'?'
            : 'Do you want to see '.$labels[0].' or '.$labels[1].'?';
    }

    private function short(string $label): string
    {
        if (mb_strlen($label) <= 80) {
            return $label;
        }

        return rtrim(mb_substr($label, 0, 79)).'…';
    }
}
