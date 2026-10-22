<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Pure remove gates for Script Registry (SEO-OPS only).
 *
 * @phpstan-type DepRow array{package_key: string, name: string, kind: string}
 */
final class ScriptRemovePolicy
{
    /**
     * @param  list<DepRow>  $dependencies  Widget/Workflow dependents
     * @param  list<array{ref_type: string, ref_id: string}>  $historyRefs  Job/Task history pins
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     dependencies: list<DepRow>,
     *     history_refs: list<array{ref_type: string, ref_id: string}>
     * }
     */
    public function evaluate(array $dependencies, array $historyRefs): array
    {
        $errors = [];
        if ($dependencies !== []) {
            $errors[] = 'has_dependencies';
        }
        if ($historyRefs !== []) {
            $errors[] = 'referenced_by_job_history';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'dependencies' => array_values($dependencies),
            'history_refs' => array_values($historyRefs),
        ];
    }
}
