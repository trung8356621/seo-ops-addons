<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security;

/**
 * Strips untrusted / forbidden fields from model planning JSON.
 */
final class AgentPlanningOutputSanitizer
{
    /** @var list<string> */
    private const STRIP_ROOT = [
        'auto_execute',
        'auto_confirm',
        'command_class',
        'handler',
        'raw_tool',
        'internal_capability',
        'provider_credentials',
        'run_all',
        'disable_confirmation',
        'site_override',
        'tenant_override',
        'api_key',
        'confirmation_token',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, stripped: list<string>}
     */
    public function sanitize(array $payload): array
    {
        $stripped = [];
        foreach (self::STRIP_ROOT as $key) {
            if (array_key_exists($key, $payload)) {
                unset($payload[$key]);
                $stripped[] = $key;
            }
        }

        if (isset($payload['intent']) && is_array($payload['intent'])) {
            [$payload['intent'], $more] = $this->sanitizeNested($payload['intent']);
            $stripped = array_merge($stripped, $more);
        }
        if (isset($payload['plan']) && is_array($payload['plan'])) {
            [$payload['plan'], $more] = $this->sanitizeNested($payload['plan']);
            $stripped = array_merge($stripped, $more);
        }

        return ['payload' => $payload, 'stripped' => array_values(array_unique($stripped))];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function sanitizeNested(array $node): array
    {
        $stripped = [];
        foreach (self::STRIP_ROOT as $key) {
            if (array_key_exists($key, $node)) {
                unset($node[$key]);
                $stripped[] = $key;
            }
        }
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                [$node[$k], $more] = $this->sanitizeNested($v);
                $stripped = array_merge($stripped, $more);
            }
        }

        return [$node, $stripped];
    }
}
