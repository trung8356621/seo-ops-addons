<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;

/**
 * Verifies Golden Package manifest metadata and SHA-256 file checksums.
 */
final class GoldenPackageIntegrityVerifier
{
    /**
     * @return array{
     *     package_root: string,
     *     manifest: array<string, mixed>,
     *     checksums: array<string, string>,
     *     verified_files: list<string>
     * }
     */
    public function verify(?string $packageRoot = null): array
    {
        $root = rtrim(str_replace('\\', '/', $packageRoot ?? GoldenPackagePath::absolute()), '/');
        if (! is_dir($root)) {
            throw ConfigurationPackageException::rejected('Golden package folder is missing: '.$root);
        }
        if (! is_readable($root)) {
            throw ConfigurationPackageException::rejected('Golden package folder is not readable: '.$root);
        }

        $manifestPath = $root.'/manifest.json';
        $checksumsPath = $root.'/metadata/checksums.json';
        if (! is_file($manifestPath) || ! is_file($checksumsPath)) {
            throw ConfigurationPackageException::rejected('Golden package manifest or checksums file is missing.');
        }

        $manifest = $this->decodeObjectFile($manifestPath, 'manifest.json');
        $checksums = $this->decodeObjectFile($checksumsPath, 'metadata/checksums.json');

        $algorithm = strtolower(trim((string) ($manifest['checksum_algorithm'] ?? '')));
        if ($algorithm !== 'sha256') {
            throw ConfigurationPackageException::rejected('Unsupported Golden package checksum_algorithm.');
        }

        $entrypoint = trim((string) ($manifest['entrypoint'] ?? ''));
        if ($entrypoint === '' || ! isset($checksums[$entrypoint])) {
            throw ConfigurationPackageException::rejected('Golden package entrypoint is missing from checksums.');
        }

        $verified = [];
        foreach ($checksums as $relative => $expectedHash) {
            if (! is_string($relative) || ! is_string($expectedHash)) {
                throw ConfigurationPackageException::rejected('Golden package checksums.json has invalid entries.');
            }
            $relative = str_replace('\\', '/', $relative);
            if (str_contains($relative, '..')) {
                throw ConfigurationPackageException::rejected('Golden package checksum path escapes package root.');
            }
            $absolute = $root.'/'.$relative;
            if (! is_file($absolute)) {
                throw ConfigurationPackageException::rejected('Golden package file missing for checksum: '.$relative);
            }
            $actual = hash_file('sha256', $absolute);
            if (! is_string($actual) || ! hash_equals(strtolower($expectedHash), strtolower($actual))) {
                throw ConfigurationPackageException::rejected('Golden package checksum mismatch: '.$relative);
            }
            $verified[] = $relative;
        }

        if (! in_array('manifest.json', $verified, true)) {
            throw ConfigurationPackageException::rejected('manifest.json must be covered by checksums.json.');
        }

        return [
            'package_root' => $root,
            'manifest' => $manifest,
            'checksums' => $checksums,
            'verified_files' => $verified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObjectFile(string $path, string $label): array
    {
        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            throw ConfigurationPackageException::rejected('Unable to read Golden package file: '.$label);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ConfigurationPackageException::rejected('Invalid JSON in Golden package file: '.$label);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ConfigurationPackageException::rejected('Golden package file must be a JSON object: '.$label);
        }

        return $decoded;
    }
}
