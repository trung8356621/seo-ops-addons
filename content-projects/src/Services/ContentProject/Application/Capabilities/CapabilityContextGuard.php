<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentCapabilityResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;

/**
 * Fail-closed required_context checks for Agent/MCP capability calls.
 * Never infers site/project from UI route or first accessible site.
 */
final class CapabilityContextGuard
{
    /**
     * @param  array<string, mixed>  $cap
     * @param  array{
     *     site_ref?: string,
     *     tenant_ref?: string,
     *     connection_ref?: string
     * }  $contextRefs
     * @param  array<string, mixed>  $input
     */
    public function assert(array $cap, array $contextRefs, array $input): ?AgentCapabilityResult
    {
        $required = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) ($cap['required_context'] ?? []),
        ), static fn (string $v): bool => $v !== ''));

        if ($required === []) {
            return null;
        }

        $available = [
            'site_ref' => trim((string) ($contextRefs['site_ref'] ?? '')),
            'tenant_ref' => trim((string) ($contextRefs['tenant_ref'] ?? '')),
            'connection_ref' => trim((string) ($contextRefs['connection_ref'] ?? $input['connection_ref'] ?? '')),
            'project_ref' => trim((string) ($input['project_ref'] ?? '')),
            'workspace_ref' => trim((string) ($input['workspace_ref'] ?? '')),
            'property_ref' => trim((string) ($input['property_ref'] ?? '')),
            'item_ref' => trim((string) ($input['item_ref'] ?? '')),
        ];

        $missing = [];
        foreach ($required as $spec) {
            if (str_contains($spec, '|')) {
                $alts = array_values(array_filter(array_map(
                    static fn (string $a): string => trim($a),
                    explode('|', $spec),
                )));
                $satisfied = false;
                foreach ($alts as $alt) {
                    if (($available[$alt] ?? '') !== '') {
                        $satisfied = true;
                        break;
                    }
                }
                if (! $satisfied) {
                    $missing[] = $spec;
                }

                continue;
            }

            if (($available[$spec] ?? '') === '') {
                $missing[] = $spec;
            }
        }

        if ($missing === []) {
            return null;
        }

        return AgentCapabilityResult::fail(
            AgentErrorCodes::MISSING_REQUIRED_CONTEXT,
            'Missing required context.',
            data: [
                'required' => $missing,
            ],
        );
    }
}
