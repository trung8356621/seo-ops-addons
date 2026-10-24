<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Models\SaRpaAdminBackup;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class RpaAdminBackupService
{
    /** @param array<string,mixed> $summary @return array<string,mixed> */
    public function put(int $userId, string $installationId, int $revision, string $archive, string $sha256, array $summary): array
    {
        if ($installationId === '' || $revision < 1 || $archive === '' || !PackageDigest::isValidHex($sha256) || !hash_equals($sha256, hash('sha256', $archive))) {
            return ['ok' => false, 'errors' => ['backup_invalid']];
        }
        return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($userId, $installationId, $revision, $archive, $sha256, $summary): array {
            $current = SaRpaAdminBackup::query()->where('user_id', $userId)->where('installation_id', $installationId)->lockForUpdate()->first();
            if ($current instanceof SaRpaAdminBackup && (int)$current->draft_revision >= $revision) {
                return ['ok' => true, 'stale' => true, 'draft_revision' => (int)$current->draft_revision];
            }
            $path = sprintf('social-automation/admin-backups/%d/%s/%d-%s.zip', $userId, hash('sha256', $installationId), $revision, substr($sha256, 0, 12));
            Storage::disk('local')->put($path, $archive);
            $oldPath = $current instanceof SaRpaAdminBackup ? (string)$current->storage_path : null;
            $row = SaRpaAdminBackup::query()->updateOrCreate(['user_id' => $userId, 'installation_id' => $installationId], [
                'draft_revision' => $revision, 'storage_disk' => 'local', 'storage_path' => $path,
                'checksum_sha256' => $sha256, 'size_bytes' => strlen($archive), 'summary' => $summary, 'backed_up_at' => now(),
            ]);
            if ($oldPath && $oldPath !== $path) Storage::disk('local')->delete($oldPath);
            return ['ok' => true, 'stale' => false, 'draft_revision' => (int)$row->draft_revision, 'backed_up_at' => $row->backed_up_at?->toIso8601String()];
        });
    }

    /** @return array<string,mixed>|null */
    public function info(int $userId, string $installationId): ?array
    {
        $query = SaRpaAdminBackup::query()->where('user_id', $userId);
        $row = $installationId !== '' ? $query->where('installation_id', $installationId)->first() : $query->orderByDesc('backed_up_at')->first();
        if (!$row instanceof SaRpaAdminBackup) return null;
        return ['installation_id' => $row->installation_id, 'draft_revision' => (int)$row->draft_revision,
            'checksum_sha256' => $row->checksum_sha256, 'size_bytes' => (int)$row->size_bytes,
            'summary' => $row->summary, 'backed_up_at' => $row->backed_up_at?->toIso8601String()];
    }

    /** @return array{bytes:string,checksum_sha256:string,info:array<string,mixed>}|null */
    public function download(int $userId, string $installationId): ?array
    {
        $query = SaRpaAdminBackup::query()->where('user_id', $userId);
        $row = $installationId !== '' ? $query->where('installation_id', $installationId)->first() : $query->orderByDesc('backed_up_at')->first();
        if (!$row instanceof SaRpaAdminBackup || !Storage::disk($row->storage_disk)->exists($row->storage_path)) return null;
        $bytes = Storage::disk($row->storage_disk)->get($row->storage_path);
        if (!hash_equals((string)$row->checksum_sha256, hash('sha256', $bytes))) return null;
        return ['bytes' => $bytes, 'checksum_sha256' => (string)$row->checksum_sha256, 'info' => $this->info($userId, $installationId) ?? []];
    }
}
