<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\WorkflowJobComposer;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;

/**
 * Validate/preview Workflow → Widget → Script mapping without dispatching a Job.
 * Reuses RPA Admin composition semantics (step order, I/O bindings, version pins).
 */
final class WorkflowComposeController extends Controller
{
    public function __construct(
        private readonly PackageRegistryService $packages,
        private readonly WorkflowJobComposer $composer = new WorkflowJobComposer,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('package_version', ''));
        if ($packageKey === '' || $version === '') {
            return response()->json(['ok' => false, 'errors' => ['validation_failed']], 422);
        }

        $release = $this->packages->findRelease($packageKey, $version);
        if ($release === null) {
            return response()->json(['ok' => false, 'errors' => ['package_not_found']], 404);
        }

        $kind = strtolower((string) ($release['kind'] ?? ''));
        if (! in_array($kind, ['widget', 'workflow'], true)) {
            return response()->json(['ok' => false, 'errors' => ['compose_kind_unsupported']], 422);
        }

        $prior = is_array($request->input('prior_outputs')) ? $request->input('prior_outputs') : [];
        $inputs = is_array($request->input('inputs')) ? $request->input('inputs') : [];

        $composed = $this->composer->compose($kind, $inputs, $release, $prior);
        $mapping = $this->scriptMapping($release);

        return response()->json([
            'ok' => $composed['ok'],
            'errors' => $composed['errors'],
            'payload' => $composed['payload'],
            'script_widget_workflow' => $mapping,
            'reuse' => [
                'workflow_builder_ui' => 'rpa_admin_electron_only',
                'seo_ops_adapter' => 'schema_and_mapper',
                'canvas_rewritten' => false,
            ],
        ], ($composed['ok'] ?? false) ? 200 : 422);
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function scriptMapping(array $release): array
    {
        $kind = strtolower((string) ($release['kind'] ?? ''));
        $manifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : [];

        if ($kind === 'widget') {
            return [
                'widget_key' => $release['package_key'] ?? null,
                'script_key' => $manifest['script_key'] ?? null,
                'script_version' => $manifest['script_version'] ?? null,
            ];
        }

        $steps = [];
        foreach (is_array($manifest['steps'] ?? null) ? $manifest['steps'] : [] as $step) {
            if (! is_array($step)) {
                continue;
            }
            $widgetKey = trim((string) ($step['widget_key'] ?? $step['package_key'] ?? ''));
            $widgetRelease = $widgetKey !== ''
                ? $this->resolveWidget($widgetKey, is_array($release['dependencies'] ?? null) ? $release['dependencies'] : [])
                : null;
            $widgetManifest = is_array($widgetRelease['manifest'] ?? null) ? $widgetRelease['manifest'] : [];
            $steps[] = [
                'widget_key' => $widgetKey,
                'script_key' => $widgetManifest['script_key'] ?? null,
                'script_version' => $widgetManifest['script_version'] ?? null,
                'output_bindings' => $step['output_bindings'] ?? null,
            ];
        }

        return [
            'workflow_key' => $release['package_key'] ?? null,
            'steps' => $steps,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dependencies
     * @return array<string, mixed>|null
     */
    private function resolveWidget(string $widgetKey, array $dependencies): ?array
    {
        foreach ($dependencies as $dep) {
            if (! is_array($dep)) {
                continue;
            }
            if (trim((string) ($dep['package_key'] ?? '')) !== $widgetKey) {
                continue;
            }
            $version = trim((string) ($dep['version'] ?? ''));
            if ($version === '') {
                continue;
            }

            return $this->packages->findRelease($widgetKey, $version);
        }

        return null;
    }
}
