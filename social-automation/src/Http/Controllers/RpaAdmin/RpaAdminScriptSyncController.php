<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptCatalogService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseMediaService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptTombstoneService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\RpaAdminBackupService;
use Omnichannel\Addons\SocialAutomation\Models\SaRpaExecutionReport;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseAsset;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;

/**
 * Bidirectional Script sync for RPA Admin (user session auth).
 */
final class RpaAdminScriptSyncController extends Controller
{
    public function __construct(
        private readonly ScriptCatalogService $catalog,
        private readonly ScriptSyncPlanner $planner,
        private readonly PackageRegistryService $registry,
        private readonly ScriptTombstoneService $tombstones,
        private readonly ScriptReleaseMediaService $media,
        private readonly RpaAdminBackupService $backups,
    ) {}

    public function backup(Request $request): JsonResponse
    {
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $binary = base64_decode((string)$request->input('archive_base64', ''), true);
        if (!$user instanceof User || $binary === false) return response()->json(['ok' => false, 'errors' => ['backup_invalid']], 422);
        $result = $this->backups->put((int)$user->id, trim((string)$request->input('installation_id', '')),
            (int)$request->input('draft_revision', 0), $binary, strtolower((string)$request->input('sha256', '')),
            is_array($request->input('summary')) ? $request->input('summary') : []);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function backupInfo(Request $request): JsonResponse
    {
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $info = $user instanceof User ? $this->backups->info((int)$user->id, trim((string)$request->query('installation_id', ''))) : null;
        return response()->json(['ok' => true, 'backup' => $info]);
    }

    public function backupDownload(Request $request): JsonResponse
    {
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $found = $user instanceof User ? $this->backups->download((int)$user->id, trim((string)$request->input('installation_id', ''))) : null;
        if ($found === null) return response()->json(['ok' => false, 'errors' => ['backup_unavailable_or_corrupt']], 404);
        return response()->json(['ok' => true, 'archive_base64' => base64_encode($found['bytes']), 'sha256' => $found['checksum_sha256'], 'backup' => $found['info']]);
    }

    public function reports(Request $request): JsonResponse
    {
        $after = max(0, (int)$request->query('after_id', 0));
        $rows = SaRpaExecutionReport::query()->where('id', '>', $after)->orderBy('id')->limit(500)->get()->map(fn ($r) => [
            'id' => (int)$r->id, 'installation_id' => $r->installation_id, 'script_id' => $r->script_id,
            'script_version' => $r->script_version, 'step_id' => $r->step_id, 'step_version' => $r->step_version,
            'run_id' => $r->run_id, 'timestamp' => $r->occurred_at?->toIso8601String(), 'status' => $r->status,
            'error_category' => $r->error_category,
        ])->all();
        return response()->json(['ok' => true, 'reports' => $rows, 'next_after_id' => $rows === [] ? $after : $rows[array_key_last($rows)]['id']]);
    }

    public function storeReport(Request $request): JsonResponse
    {
        foreach (['installation_id', 'script_id', 'script_version', 'run_id', 'timestamp', 'status'] as $field) {
            if (trim((string)$request->input($field, '')) === '') return response()->json(['ok' => false, 'errors' => ['report_'.$field.'_required']], 422);
        }
        $row = SaRpaExecutionReport::query()->firstOrCreate([
            'installation_id' => (string)$request->input('installation_id'), 'run_id' => (string)$request->input('run_id'),
            'step_id' => $request->input('step_id'), 'step_version' => $request->input('step_version'),
        ], [
            'script_id' => (string)$request->input('script_id'), 'script_version' => (string)$request->input('script_version'),
            'occurred_at' => (string)$request->input('timestamp'), 'status' => (string)$request->input('status'),
            'error_category' => $request->input('error_category'), 'details' => is_array($request->input('details')) ? $request->input('details') : null,
        ]);
        return response()->json(['ok' => true, 'id' => (int)$row->id], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function attachAsset(Request $request): JsonResponse
    {
        $package = SaPackage::query()->where('package_key', (string)$request->input('package_key'))->where('kind', 'script')->first();
        $release = $package instanceof SaPackage ? SaPackageRelease::query()->where('package_id', $package->id)->where('version', (string)$request->input('version'))->first() : null;
        $assetPath = trim((string)$request->input('asset_path', '')); $sha = strtolower((string)$request->input('sha256', ''));
        $bytes = base64_decode((string)$request->input('asset_base64', ''), true);
        if (!$release instanceof SaPackageRelease || $assetPath === '' || str_contains($assetPath, '..') || $bytes === false || !hash_equals($sha, hash('sha256', $bytes)))
            return response()->json(['ok' => false, 'errors' => ['script_asset_invalid']], 422);
        $expected = collect(is_array($release->manifest['assets'] ?? null) ? $release->manifest['assets'] : [])->first(fn ($a) => is_array($a) && ($a['path'] ?? null) === $assetPath && ($a['sha256'] ?? null) === $sha);
        if (!$expected) return response()->json(['ok' => false, 'errors' => ['script_asset_not_declared']], 422);
        $storagePath = sprintf('social-automation/script-assets/%d/%s', $release->id, $sha); Storage::disk('local')->put($storagePath, $bytes);
        SaScriptReleaseAsset::query()->updateOrCreate(['release_id' => $release->id, 'asset_path' => $assetPath], [
            'checksum_sha256' => $sha, 'mime' => (string)$request->input('mime', 'application/octet-stream'), 'storage_path' => $storagePath, 'size_bytes' => strlen($bytes)]);
        return response()->json(['ok' => true, 'sha256' => $sha]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'scripts' => $this->catalog->listScripts(),
        ]);
    }

    public function plan(Request $request): JsonResponse
    {
        $local = $request->input('local_scripts', []);
        if (! is_array($local)) {
            return response()->json(['ok' => false, 'errors' => ['local_scripts_required']], 422);
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        foreach ($local as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = [
                'script_id' => (string) ($row['script_id'] ?? ''),
                'package_key' => (string) ($row['package_key'] ?? ''),
                'version' => (string) ($row['version'] ?? ''),
                'content_digest' => (string) ($row['content_digest'] ?? ''),
                'execution_digest' => isset($row['execution_digest']) ? (string) $row['execution_digest'] : null,
                'lineage' => is_array($row['lineage'] ?? null) ? $row['lineage'] : [],
                'assets_complete' => (bool) ($row['assets_complete'] ?? true),
            ];
        }

        $suppressed = $this->tombstones->suppressedScriptIds(ScriptTombstoneService::SOURCE_RPA_ADMIN);
        $plan = $this->planner->plan($normalized, $this->catalog->listScripts(), $suppressed);

        return response()->json(['ok' => true, 'plan' => $plan]);
    }

    public function publish(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $payload = $request->all();
        if (! is_array($payload)) {
            $payload = [];
        }
        unset($payload['user_id'], $payload['id']);

        $result = $this->registry->publish($payload, [
            'user_id' => $user instanceof User ? (int) $user->id : null,
            'sync_source' => 'rpa_admin',
        ]);

        return response()->json($result->toArray(), $result->httpStatus());
    }

    public function pull(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('version', ''));
        if ($packageKey === '' || $version === '') {
            return response()->json(['ok' => false, 'errors' => ['package_key_version_required']], 422);
        }

        $release = $this->registry->findRelease($packageKey, $version);
        if ($release === null || strtolower((string) ($release['kind'] ?? '')) !== 'script') {
            return response()->json(['ok' => false, 'errors' => ['script_not_found']], 404);
        }

        $manifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : [];
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
        $assetsComplete = true;
        foreach ($assets as $asset) {
            if (! is_array($asset) || trim((string) ($asset['path'] ?? '')) === ''
                || ! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($asset['sha256'] ?? '')))) {
                $assetsComplete = false;
                break;
            }
        }

        return response()->json([
            'ok' => true,
            'release' => $release,
            'assets_complete' => $assetsComplete,
            'ready_to_execute' => $assetsComplete,
        ], SocialAutomationApiStatus::fromResult(true));
    }

    public function attachVideoDemo(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('version', ''));
        $mime = strtolower(trim((string) $request->input('mime', 'video/webm')));
        $checksum = trim((string) $request->input('sha256', ''));

        $file = $request->file('video');
        $binary = null;
        if ($file !== null && $file->isValid()) {
            $binary = (string) file_get_contents($file->getRealPath());
            if ($mime === 'video/webm' || $mime === 'video/mp4') {
                // keep request mime
            } elseif (is_string($file->getMimeType()) && $file->getMimeType() !== '') {
                $mime = strtolower($file->getMimeType());
            }
        } else {
            $raw = $request->input('video_base64');
            if (is_string($raw) && $raw !== '') {
                $decoded = base64_decode($raw, true);
                $binary = $decoded === false ? null : $decoded;
            }
        }

        if ($packageKey === '' || $version === '' || ! is_string($binary) || $binary === '') {
            return response()->json(['ok' => false, 'errors' => ['video_demo_upload_required']], 422);
        }

        $result = $this->media->attachVideoDemo(
            $packageKey,
            $version,
            $binary,
            $mime !== '' ? $mime : 'video/webm',
            $checksum !== '' ? $checksum : null,
        );

        return response()->json($result, ($result['ok'] ?? false) ? 201 : 422);
    }
}
