<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Omnichannel\Addons\SocialAutomation\Domain\WidgetFormSchema;

/**
 * Validate Admin → SEO-OPS package publish payloads (no DB).
 *
 * Kinds:
 * - script  — immutable RPA Script release (variables/steps/assets/video demo)
 * - widget  — form layer over a Script (or legacy steps-only widget)
 * - workflow — sequential Widget composition
 */
final class PackagePublishValidator
{
    public const MAX_MANIFEST_BYTES = 1_048_576; // 1 MiB

    public const KINDS = ['script', 'widget', 'workflow'];

    /** @var list<string> */
    private const FORBIDDEN_EXECUTABLE = [
        'code', 'executable', 'eval', 'cookies', 'credentials',
        'account_switch', 'switch_account', 'change_account',
    ];

    public function __construct(
        private readonly WidgetFormSchema $widgetForms = new WidgetFormSchema,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, errors: list<string>}
     */
    public function validate(array $input): array
    {
        $errors = [];

        $packageKey = trim((string) ($input['package_key'] ?? ''));
        if ($packageKey === '' || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,126}[a-z0-9]$/', $packageKey)) {
            $errors[] = 'package_key_invalid';
        }

        $kind = strtolower(trim((string) ($input['kind'] ?? '')));
        if (! in_array($kind, self::KINDS, true)) {
            $errors[] = 'kind_invalid';
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $errors[] = 'name_invalid';
        }

        $version = trim((string) ($input['version'] ?? ''));
        if ($version === '' || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$/', $version)) {
            $errors[] = 'version_invalid';
        }

        $schemaVersion = (int) ($input['schema_version'] ?? 0);
        if ($schemaVersion < 1) {
            $errors[] = 'schema_version_invalid';
        }

        $manifest = $input['manifest'] ?? null;
        if (! is_array($manifest)) {
            $errors[] = 'manifest_required';
            $manifest = [];
        }

        $encoded = json_encode($manifest);
        if ($encoded === false || strlen($encoded) > self::MAX_MANIFEST_BYTES) {
            $errors[] = 'manifest_too_large';
        }

        foreach (self::FORBIDDEN_EXECUTABLE as $forbidden) {
            if (array_key_exists($forbidden, $manifest)) {
                $errors[] = 'manifest_forbidden_field:'.$forbidden;
            }
        }
        // Top-level "script" remains forbidden (executable handoff). Script kind uses script_id / variables / steps.
        if (array_key_exists('script', $manifest)) {
            $errors[] = 'manifest_forbidden_field:script';
        }

        $providedDigest = strtolower(trim((string) ($input['content_digest'] ?? '')));
        if ($providedDigest !== '' && ! PackageDigest::isValidHex($providedDigest)) {
            $errors[] = 'content_digest_invalid';
        }

        $runtime = $input['runtime_compatibility'] ?? null;
        if ($runtime !== null && ! is_array($runtime)) {
            $errors[] = 'runtime_compatibility_invalid';
        }

        $deps = $input['dependencies'] ?? [];
        if (! is_array($deps)) {
            $errors[] = 'dependencies_invalid';
            $deps = [];
        }

        $normalizedDeps = [];
        foreach ($deps as $i => $dep) {
            if (! is_array($dep)) {
                $errors[] = 'dependency_invalid:'.$i;
                continue;
            }
            $depKey = trim((string) ($dep['package_key'] ?? ''));
            $depVersion = trim((string) ($dep['version'] ?? ''));
            $depDigest = strtolower(trim((string) ($dep['content_digest'] ?? '')));
            if ($depKey === '' || $depVersion === '' || ! PackageDigest::isValidHex($depDigest)) {
                $errors[] = 'dependency_invalid:'.$i;
                continue;
            }
            if ($depKey === $packageKey) {
                $errors[] = 'dependency_self_reference:'.$i;
                continue;
            }
            $normalizedDeps[] = [
                'package_key' => $depKey,
                'version' => $depVersion,
                'content_digest' => $depDigest,
            ];
        }

        if ($kind === 'workflow') {
            foreach ($this->validateWorkflowComposition($manifest, $normalizedDeps) as $err) {
                $errors[] = $err;
            }
        } elseif ($kind === 'widget') {
            foreach ($this->validateWidgetManifest($manifest, $normalizedDeps) as $err) {
                $errors[] = $err;
            }
        } elseif ($kind === 'script') {
            foreach ($this->validateScriptManifest($manifest) as $err) {
                $errors[] = $err;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }

        $canonical = [
            'package_key' => $packageKey,
            'kind' => $kind,
            'version' => $version,
            'schema_version' => $schemaVersion,
            'manifest' => $manifest,
            'dependencies' => $normalizedDeps,
            'runtime_compatibility' => is_array($runtime) ? $runtime : [],
        ];
        $computedDigest = PackageDigest::sha256OfCanonicalJson($canonical);

        if ($providedDigest !== '' && ! PackageDigest::equals($providedDigest, $computedDigest)) {
            return ['ok' => false, 'errors' => ['content_digest_mismatch']];
        }

        return [
            'ok' => true,
            'data' => [
                'package_key' => $packageKey,
                'kind' => $kind,
                'name' => $name,
                'description' => isset($input['description']) ? trim((string) $input['description']) : null,
                'version' => $version,
                'schema_version' => $schemaVersion,
                'manifest' => $manifest,
                'runtime_compatibility' => is_array($runtime) ? $runtime : null,
                'dependencies' => $normalizedDeps,
                'content_digest' => $computedDigest,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function validateScriptManifest(array $manifest): array
    {
        $errors = [];
        $scriptId = trim((string) ($manifest['script_id'] ?? ''));
        if ($scriptId === '' || mb_strlen($scriptId) > 128) {
            $errors[] = 'script_id_required';
        }

        $variables = $manifest['variables'] ?? null;
        if (! is_array($variables) || ! array_is_list($variables)) {
            $errors[] = 'script_variables_required';
        } else {
            $keys = [];
            foreach ($variables as $i => $variable) {
                if (! is_array($variable)) {
                    $errors[] = 'script_variable_invalid:'.$i;
                    continue;
                }
                $key = trim((string) ($variable['key'] ?? ''));
                $type = strtolower(trim((string) ($variable['type'] ?? '')));
                if ($key === '' || isset($keys[$key])) {
                    $errors[] = 'script_variable_key_invalid:'.$i;
                    continue;
                }
                if (! in_array($type, ['text', 'file', 'files', 'integer', 'number'], true)) {
                    $errors[] = 'script_variable_type_invalid:'.$i;
                }
                $keys[$key] = true;
            }
        }

        $steps = $manifest['steps'] ?? null;
        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            $errors[] = 'script_steps_required';
        } else {
            foreach ($steps as $i => $step) {
                if (! is_array($step)) {
                    $errors[] = 'script_step_invalid:'.$i;
                    continue;
                }
                foreach (['code', 'executable', 'eval', 'account_switch', 'switch_account', 'change_account'] as $forbidden) {
                    if (array_key_exists($forbidden, $step)) {
                        $errors[] = 'script_step_forbidden_field:'.$forbidden;
                    }
                }
            }
        }

        $video = $manifest['video_demo'] ?? null;
        if ($video !== null) {
            if (! is_array($video)) {
                $errors[] = 'script_video_demo_invalid';
            } else {
                $ref = trim((string) ($video['artifact_ref'] ?? $video['path'] ?? ''));
                $sha = strtolower(trim((string) ($video['sha256'] ?? '')));
                $mime = strtolower(trim((string) ($video['mime'] ?? 'video/webm')));
                $testRunId = trim((string) ($video['test_run_id'] ?? ''));
                if ($ref === '') {
                    $errors[] = 'script_video_demo_ref_required';
                }
                if ($sha !== '' && ! PackageDigest::isValidHex($sha)) {
                    $errors[] = 'script_video_demo_digest_invalid';
                }
                if (! in_array($mime, ['video/webm', 'video/mp4'], true)) {
                    $errors[] = 'script_video_demo_mime_invalid';
                }
                if ($testRunId === '') {
                    $errors[] = 'script_video_demo_test_run_required';
                }
                if (array_key_exists('audio', $video) && $video['audio'] === true) {
                    $errors[] = 'script_video_demo_audio_forbidden';
                }
                if (array_key_exists('desktop_capture', $video) && $video['desktop_capture'] === true) {
                    $errors[] = 'script_video_demo_desktop_forbidden';
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{package_key: string, version: string, content_digest: string}>  $deps
     * @return list<string>
     */
    private function validateWidgetManifest(array $manifest, array $deps): array
    {
        $errors = [];

        $steps = $manifest['steps'] ?? null;
        if (is_array($steps)) {
            foreach ($steps as $i => $step) {
                if (! is_array($step)) {
                    $errors[] = 'widget_step_invalid:'.$i;
                    continue;
                }
                foreach (['account_switch', 'switch_account', 'change_account', 'account_id'] as $forbidden) {
                    if (array_key_exists($forbidden, $step)) {
                        $errors[] = 'widget_step_forbidden_field:'.$forbidden;
                    }
                }
            }
        }

        $parsed = $this->widgetForms->parse($manifest);
        foreach ($parsed['errors'] as $err) {
            $errors[] = $err;
        }

        $scriptRef = $parsed['script_ref'];
        if (is_array($scriptRef)) {
            $matched = false;
            foreach ($deps as $dep) {
                if (
                    $dep['package_key'] === $scriptRef['package_key']
                    && $dep['version'] === $scriptRef['version']
                    && PackageDigest::equals($dep['content_digest'], $scriptRef['content_digest'])
                ) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $errors[] = 'widget_script_dependency_required';
            }
        }

        // New form widgets must bind a Script; legacy steps-only widgets remain valid.
        if ($parsed['fields'] !== [] && $scriptRef === null) {
            $errors[] = 'widget_script_ref_required';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{package_key: string, version: string, content_digest: string}>  $deps
     * @return list<string>
     */
    private function validateWorkflowComposition(array $manifest, array $deps): array
    {
        $errors = [];
        $steps = $manifest['steps'] ?? null;
        if (! is_array($steps) || $steps === [] || ! array_is_list($steps)) {
            $errors[] = 'workflow_steps_required';

            return $errors;
        }

        foreach ($steps as $i => $step) {
            if (! is_array($step)) {
                $errors[] = 'workflow_step_invalid:'.$i;
                continue;
            }
            foreach (['account_switch', 'switch_account', 'change_account', 'account_id'] as $forbidden) {
                if (array_key_exists($forbidden, $step)) {
                    $errors[] = 'workflow_step_forbidden_field:'.$forbidden;
                }
            }
            $widgetKey = trim((string) ($step['widget_key'] ?? $step['package_key'] ?? ''));
            $action = trim((string) ($step['action'] ?? ''));
            if ($widgetKey === '' && $action === '') {
                $errors[] = 'workflow_step_empty:'.$i;
            }
        }

        $refsWidgetPackages = false;
        foreach ($steps as $step) {
            if (is_array($step) && trim((string) ($step['widget_key'] ?? $step['package_key'] ?? '')) !== '') {
                $refsWidgetPackages = true;
                break;
            }
        }
        if ($refsWidgetPackages && $deps === []) {
            $errors[] = 'workflow_dependencies_required';
        }

        return $errors;
    }
}
