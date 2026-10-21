<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Validate Admin → SEO-OPS package publish payloads (no DB).
 */
final class PackagePublishValidator
{
    public const MAX_MANIFEST_BYTES = 1_048_576; // 1 MiB

    public const KINDS = ['widget', 'workflow'];

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

        // Reject obvious executable handoff fields.
        foreach (['code', 'script', 'executable', 'eval', 'cookies', 'credentials'] as $forbidden) {
            if (array_key_exists($forbidden, $manifest)) {
                $errors[] = 'manifest_forbidden_field:'.$forbidden;
            }
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
            $normalizedDeps[] = [
                'package_key' => $depKey,
                'version' => $depVersion,
                'content_digest' => $depDigest,
            ];
        }

        if ($kind === 'workflow' && $normalizedDeps === []) {
            // Workflow may reference widgets via dependencies; warn as soft? Task says pinned deps.
            // Allow empty for stub workflows that inline steps in manifest.
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
}
