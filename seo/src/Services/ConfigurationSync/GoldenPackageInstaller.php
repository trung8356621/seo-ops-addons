<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\ConfigurationCanonicalizer;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackageIntegrityVerifier;

/**
 * Stages a downloaded bundle, verifies it, then promotes it onto the fixed Golden path.
 * A failed step leaves the previous verified tree in place.
 */
final class GoldenPackageInstaller
{
    public function __construct(
        private readonly GoldenPackageIntegrityVerifier $verifier = new GoldenPackageIntegrityVerifier(),
        private readonly ConfigurationCanonicalizer $canonicalizer = new ConfigurationCanonicalizer(),
        private readonly ?\Closure $writableProbe = null,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function stageAndPromote(array $bundle, string $targetRoot): array
    {
        $targetRoot = rtrim(str_replace('\\', '/', $targetRoot), '/');
        $files = $bundle['files'] ?? null;
        if (! is_array($files)) {
            throw new ConfigurationSyncException('invalid_manifest', 'Package bundle is missing files.');
        }

        $this->assertFileMap($files);
        $manifest = $this->decodeJsonObject((string) $files['manifest.json'], 'manifest.json');
        $this->assertManifest($manifest, $bundle);

        $staging = $this->stagingPath($targetRoot);
        $this->deleteTree($staging);
        if (! mkdir($staging, 0775, true) && ! is_dir($staging)) {
            throw new ConfigurationSyncException('package_stage_failed', 'Unable to create a staging directory for the Golden package.');
        }

        try {
            foreach ($files as $relative => $contents) {
                $this->writeStagedFile($staging, (string) $relative, (string) $contents);
            }
            $verified = $this->verifier->verify($staging);
            $entrypoint = $staging.'/payload/full-configuration.json';
            $payload = $this->decodeJsonObject((string) file_get_contents($entrypoint), 'payload/full-configuration.json');
            $fingerprint = $this->canonicalizer->fingerprint($payload);
            $expected = strtolower(trim((string) ($bundle['canonical_hash'] ?? '')));
            if ($expected === '' || ! hash_equals($expected, $fingerprint)) {
                throw new ConfigurationSyncException('canonical_hash_mismatch', 'Authoritative full-configuration.json does not match the published canonical hash.');
            }
            $this->assertWritable(dirname($targetRoot));
            $this->promote($staging, $targetRoot);
        } catch (\Throwable $e) {
            $this->deleteTree($staging);
            if ($e instanceof ConfigurationSyncException) {
                throw $e;
            }
            throw new ConfigurationSyncException('package_rejected', 'Golden package verification failed.');
        }

        return [
            'package_root' => $targetRoot,
            'package_id' => (string) ($manifest['package_id'] ?? ''),
            'package_version' => (string) ($manifest['package_version'] ?? ''),
            'canonical_hash' => strtolower((string) $bundle['canonical_hash']),
        ];
    }

    /**
     * @param  array<mixed>  $files
     */
    private function assertFileMap(array $files): void
    {
        $keys = [];
        foreach ($files as $relative => $contents) {
            if (! is_string($relative) || ! is_string($contents)) {
                throw new ConfigurationSyncException('invalid_manifest', 'Package file entries must be strings.');
            }
            $normalized = $this->normalizeRelative($relative);
            if ($normalized !== $relative) {
                throw new ConfigurationSyncException('path_traversal', 'Package path is not a strict relative file path.');
            }
            if (! in_array($normalized, ConfigProtocol::ALLOWED_FILES, true)) {
                throw new ConfigurationSyncException('path_traversal', 'Package file is not in the allowed Golden file list.');
            }
            $keys[] = $normalized;
        }
        foreach (ConfigProtocol::ALLOWED_FILES as $required) {
            if (! in_array($required, $keys, true)) {
                throw new ConfigurationSyncException('invalid_manifest', 'Package is missing required file: '.$required);
            }
        }
    }

    private function normalizeRelative(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            return '';
        }
        if (str_contains($relative, "\0") || str_contains($relative, '..')) {
            return '';
        }
        if (str_contains($relative, '//')) {
            return '';
        }

        return $relative;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $bundle
     */
    private function assertManifest(array $manifest, array $bundle): void
    {
        $packageId = (string) ($manifest['package_id'] ?? '');
        if ($packageId !== ConfigProtocol::PACKAGE_ID || (string) ($bundle['package_id'] ?? '') !== ConfigProtocol::PACKAGE_ID) {
            throw new ConfigurationSyncException('invalid_manifest', 'Golden package identity is not seo-ops-golden-configuration.');
        }
        $version = (string) ($manifest['package_version'] ?? '');
        if ($version === '' || $version !== (string) ($bundle['package_version'] ?? '')) {
            throw new ConfigurationSyncException('invalid_manifest', 'Golden package version does not match the bundle.');
        }
        if ((string) ($manifest['entrypoint'] ?? '') !== 'payload/full-configuration.json') {
            throw new ConfigurationSyncException('invalid_manifest', 'Golden package entrypoint is not authoritative.');
        }
        $schema = (string) ($manifest['configuration_schema_version'] ?? '');
        if (! in_array($schema, ['1.0', '1.1'], true)) {
            throw new ConfigurationSyncException('invalid_manifest', 'Golden package schema version is not supported.');
        }
        $compatibility = $manifest['compatibility'] ?? null;
        if (! is_array($compatibility)) {
            throw new ConfigurationSyncException('invalid_manifest', 'Golden package compatibility block is missing.');
        }
        $expectedClient = (string) config('configuration-sync.client_version', '12.50.0');
        $expectedAddons = (string) config('configuration-sync.addons_version', '0.1.0');
        if ((string) ($compatibility['client'] ?? '') !== $expectedClient || (string) ($compatibility['addons'] ?? '') !== $expectedAddons) {
            throw new ConfigurationSyncException('compatibility_mismatch', 'Golden package compatibility does not match this Client.');
        }
    }

    private function writeStagedFile(string $staging, string $relative, string $contents): void
    {
        $path = $staging.'/'.$relative;
        $parent = dirname($path);
        if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new ConfigurationSyncException('package_stage_failed', 'Unable to create a staged package directory.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new ConfigurationSyncException('package_stage_failed', 'Unable to write a staged package file.');
        }
    }

    private function assertWritable(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new ConfigurationSyncException(
                'golden_directory_read_only',
                'Golden baseline directory is not writable. Mount '.$directory.' as writable. The logical path stays resources/golden-configuration.',
            );
        }
        $writable = $this->writableProbe !== null
            ? (bool) ($this->writableProbe)($directory)
            : is_writable($directory);
        if (! $writable) {
            throw new ConfigurationSyncException(
                'golden_directory_read_only',
                'Golden baseline directory is not writable. The logical path stays resources/golden-configuration.',
            );
        }
    }

    private function promote(string $staging, string $target): void
    {
        $parent = dirname($target);
        $hold = $target.'.__hold';
        $previous = $target.'.__previous';
        $this->deleteTree($hold);

        $movedAside = false;
        if (is_dir($target)) {
            if (! rename($target, $hold)) {
                throw new ConfigurationSyncException('package_promote_failed', 'Unable to retain the previous Golden package.');
            }
            $movedAside = true;
        }

        if (! rename($staging, $target)) {
            if ($movedAside && is_dir($hold) && ! is_dir($target)) {
                rename($hold, $target);
            }
            throw new ConfigurationSyncException('package_promote_failed', 'Unable to promote the verified Golden package.');
        }

        if ($movedAside && is_dir($hold)) {
            if (is_dir($previous)) {
                $this->deleteTree($previous);
            }
            rename($hold, $previous);
        }
    }

    private function stagingPath(string $targetRoot): string
    {
        return dirname($targetRoot).'/'.basename($targetRoot).'.__incoming-'.bin2hex(random_bytes(4));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $raw, string $label): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConfigurationSyncException('invalid_manifest', 'Invalid JSON in '.$label.'.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ConfigurationSyncException('invalid_manifest', $label.' must be a JSON object.');
        }

        return $decoded;
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
