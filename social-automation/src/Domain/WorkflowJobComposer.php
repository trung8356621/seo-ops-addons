<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Prepare Runner-facing job payload from Widget or Workflow package pins.
 * Reuses sequential I/O mapping; does not execute Scripts.
 *
 * Adapter note: RPA Admin Workflow Builder React UI is Electron-bound.
 * SEO-OPS reuses composition schema/logic here; UI ownership moves to Filament later.
 */
final class WorkflowJobComposer
{
    public function __construct(
        private readonly WorkflowOutputMapper $mapper = new WorkflowOutputMapper,
        private readonly WidgetFormSchema $widgetForms = new WidgetFormSchema,
    ) {}

    /**
     * @param  array<string, mixed>  $taskInputs
     * @param  array<string, mixed>|null  $packageRelease  findRelease() shape
     * @param  array<string, array<string, mixed>>  $priorOutputs
     * @return array{ok: bool, payload: array<string, mixed>, errors: list<string>}
     */
    public function compose(
        string $packageKind,
        array $taskInputs,
        ?array $packageRelease,
        array $priorOutputs = [],
    ): array {
        $kind = strtolower(trim($packageKind));
        $payload = [
            'inputs' => $taskInputs,
            'package_kind' => $kind,
        ];

        if ($packageRelease === null) {
            return ['ok' => true, 'payload' => $payload, 'errors' => []];
        }

        $manifest = is_array($packageRelease['manifest'] ?? null) ? $packageRelease['manifest'] : [];
        $payload['package_key'] = $packageRelease['package_key'] ?? null;
        $payload['package_version'] = $packageRelease['version'] ?? null;
        $payload['content_digest'] = $packageRelease['content_digest'] ?? null;

        if ($kind === 'workflow') {
            $steps = is_array($manifest['steps'] ?? null) ? $manifest['steps'] : [];
            $built = $this->mapper->buildRunnerPayload($steps, $taskInputs, $priorOutputs);
            $payload['workflow'] = [
                'steps' => $built['steps'],
                'version_pin' => $packageRelease['dependencies'] ?? [],
            ];

            return [
                'ok' => $built['ok'],
                'payload' => $payload,
                'errors' => $built['errors'],
            ];
        }

        if ($kind === 'widget') {
            $parsed = $this->widgetForms->parse($manifest);
            $payload['widget'] = [
                'script_ref' => $parsed['script_ref'],
                'form_fields' => $parsed['fields'],
                'legacy_steps' => is_array($manifest['steps'] ?? null) ? $manifest['steps'] : null,
            ];

            return [
                'ok' => $parsed['ok'],
                'payload' => $payload,
                'errors' => $parsed['errors'],
            ];
        }

        if ($kind === 'script') {
            // Scripts are not dispatched as Tasks; surface for completeness.
            return [
                'ok' => false,
                'payload' => $payload,
                'errors' => ['script_not_dispatchable'],
            ];
        }

        return ['ok' => true, 'payload' => $payload, 'errors' => []];
    }
}
