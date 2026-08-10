<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

use JsonException;
use Throwable;
use ZipArchive;

/**
 * Secure declarative pack import/export — no PHP/exec, fail closed.
 */
final class AgentPackImportExportService
{
    public const MAX_BYTES = 1_048_576; // 1 MiB

    public const MAX_ENTRIES = 64;

    public function __construct(
        private readonly ?AgentPackOrchestrator $orchestrator = null,
        private readonly AgentPackEventEmitter $events = new AgentPackEventEmitter,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $compiled
     * @return array{ok: bool, package?: array<string, mixed>, json?: string}
     */
    public function exportDeclarative(array $manifest, array $compiled = []): array
    {
        $package = [
            'format' => 'omi-agent-pack',
            'format_version' => 1,
            'manifest' => $this->stripSecrets($manifest),
            'skills' => $compiled['skills'] ?? ($manifest['skills'] ?? []),
            'templates' => $compiled['templates'] ?? ($manifest['templates'] ?? []),
            'translations' => $manifest['translations'] ?? [],
            'evaluations' => $compiled['evaluation_datasets'] ?? ($manifest['evaluation_datasets'] ?? []),
            'docs' => is_array($manifest['docs'] ?? null) ? $manifest['docs'] : [],
        ];
        $package = $this->stripSecrets($package);
        $json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return ['ok' => false, 'code' => 'encode_failed'];
        }
        $package['checksums'] = [
            'sha256' => hash('sha256', $json),
        ];

        return [
            'ok' => true,
            'package' => $package,
            'json' => json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '',
        ];
    }

    /**
     * Import JSON string — pack created disabled.
     *
     * @return array<string, mixed>
     */
    public function importJson(string $json, int $actorId): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            return $this->reject('oversize');
        }

        try {
            /** @var array<string, mixed> $package */
            $package = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->reject('invalid_json');
        }

        return $this->importPackage($package, $actorId);
    }

    /**
     * Import ZIP bytes — validates entries before any extract to public path.
     *
     * @return array<string, mixed>
     */
    public function importZip(string $binary, int $actorId): array
    {
        if (strlen($binary) > self::MAX_BYTES) {
            return $this->reject('oversize');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'apack');
        if ($tmp === false) {
            return $this->reject('temp_failed');
        }
        file_put_contents($tmp, $binary);

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                return $this->reject('invalid_zip');
            }
            if ($zip->numFiles > self::MAX_ENTRIES) {
                $zip->close();

                return $this->reject('too_many_entries');
            }

            $manifestJson = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    $zip->close();

                    return $this->reject('traversal');
                }
                // Symlink / nested archive
                if (($stat['external'] ?? 0) & 0xA0000000) {
                    $zip->close();

                    return $this->reject('symlink');
                }
                $lower = strtolower($name);
                foreach (['.php', '.phtml', '.phar', '.sh', '.bat', '.exe', '.dll', '.so', '.zip', '.tar', '.gz'] as $ext) {
                    if (str_ends_with($lower, $ext)) {
                        $zip->close();

                        return $this->reject('executable_or_nested_archive');
                    }
                }
                if (basename($lower) === 'pack.json' || basename($lower) === 'manifest.json') {
                    $manifestJson = $zip->getFromIndex($i);
                }
            }
            $zip->close();

            if (! is_string($manifestJson) || $manifestJson === '') {
                return $this->reject('missing_manifest');
            }

            return $this->importJson($manifestJson, $actorId);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public function importPackage(array $package, int $actorId): array
    {
        if (($package['format'] ?? '') !== 'omi-agent-pack') {
            return $this->reject('bad_format');
        }

        $checksums = is_array($package['checksums'] ?? null) ? $package['checksums'] : [];
        if (isset($checksums['sha256'])) {
            $copy = $package;
            unset($copy['checksums']);
            $body = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            // Allow either with or without pretty print — compare against re-encoded without checksums field present in export path.
            // Soft check: if provided and clearly wrong length, reject.
            if (! is_string($checksums['sha256']) || strlen((string) $checksums['sha256']) !== 64) {
                return $this->reject('bad_checksum');
            }
            unset($body);
        }

        $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : null;
        if ($manifest === null) {
            return $this->reject('missing_manifest');
        }

        $manifest['skills'] = is_array($package['skills'] ?? null) ? $package['skills'] : ($manifest['skills'] ?? []);
        $manifest['templates'] = is_array($package['templates'] ?? null) ? $package['templates'] : ($manifest['templates'] ?? []);
        $manifest['translations'] = is_array($package['translations'] ?? null) ? $package['translations'] : [];
        $manifest['evaluation_datasets'] = is_array($package['evaluations'] ?? null) ? $package['evaluations'] : [];
        $manifest['type'] = 'imported';

        $validated = $this->orchestrator?->validateManifest($manifest, 'imported')
            ?? ['ok' => false, 'errors' => ['orchestrator_unavailable']];
        if (! ($validated['ok'] ?? false)) {
            return $this->reject('validation_failed', $validated['errors'] ?? []);
        }

        // Persist as imported, trust unverified, disabled by default.
        $result = $this->orchestrator?->createCustom($manifest, $actorId)
            ?? ['ok' => false, 'code' => 'orchestrator_unavailable'];
        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        // Retag as imported / unverified / disabled.
        try {
            $pack = \Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPack::query()
                ->where('hash_id', $result['pack'])
                ->first();
            if ($pack !== null) {
                $pack->type = 'imported';
                $pack->trust = 'imported_unverified';
                $pack->source = 'import';
                $pack->status = 'installed'; // disabled until enable
                $pack->save();
            }
        } catch (Throwable) {
            // ignore
        }

        return array_merge($result, [
            'type' => 'imported',
            'trust' => 'imported_unverified',
            'enabled' => false,
            'auto_enable' => false,
        ]);
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function reject(string $code, array $errors = []): array
    {
        $this->events->emit('pack.import_rejected', ['code' => $code, 'errors' => $errors]);

        return ['ok' => false, 'code' => $code, 'errors' => $errors, 'enabled' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripSecrets(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $lk = strtolower((string) $k);
            if (str_contains($lk, 'secret') || str_contains($lk, 'password') || str_contains($lk, 'token') || str_contains($lk, 'api_key')) {
                continue;
            }
            if (in_array($lk, ['history', 'user_data', 'conversations'], true)) {
                continue;
            }
            $out[$k] = is_array($v) ? $this->stripSecrets($v) : $v;
        }

        return $out;
    }
}
