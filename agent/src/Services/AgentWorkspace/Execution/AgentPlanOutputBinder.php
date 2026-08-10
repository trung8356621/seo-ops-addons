<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

/**
 * Allowlisted bindings from prior plan step outputs into next step form input.
 */
final class AgentPlanOutputBinder
{
    /** @var list<string> */
    public const ALLOWED_KEYS = [
        'project_ref',
        'workspace_ref',
        'article_ref',
        'selected_item_refs',
        'keyword_workspace_ref',
        'serp_workspace_ref',
        'operation_ref',
        'topical_map_ref',
    ];

    /**
     * @param  array<string, mixed>  $priorOutput
     * @param  array<string, mixed>  $stepInput
     * @return array<string, mixed>
     */
    public function bind(array $priorOutput, array $stepInput = []): array
    {
        $out = $stepInput;
        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $priorOutput)) {
                continue;
            }
            $value = $priorOutput[$key];
            if (is_string($value) && trim($value) !== '') {
                $out[$key] = trim($value);
            } elseif (is_array($value) && $key === 'selected_item_refs') {
                $refs = [];
                foreach ($value as $ref) {
                    if (is_string($ref) && trim($ref) !== '') {
                        $refs[] = trim($ref);
                    }
                }
                if ($refs !== []) {
                    $out[$key] = array_values(array_unique($refs));
                }
            }
        }

        return $out;
    }
}
