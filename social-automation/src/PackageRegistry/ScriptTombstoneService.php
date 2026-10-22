<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptTombstone;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Suppress re-import of Scripts removed on SEO-OPS (identity = script_id + source).
 */
final class ScriptTombstoneService
{
    public const SOURCE_RPA_ADMIN = 'rpa_admin';

    public function isSuppressed(string $scriptId, string $source): bool
    {
        $scriptId = trim($scriptId);
        $source = trim($source);
        if ($scriptId === '' || $source === '' || ! $this->ready()) {
            return false;
        }

        return SaScriptTombstone::query()
            ->where('script_id', $scriptId)
            ->where('source', $source)
            ->whereNull('restored_at')
            ->exists();
    }

    /**
     * @return list<string> active suppressed script_ids for source
     */
    public function suppressedScriptIds(string $source): array
    {
        if (! $this->ready()) {
            return [];
        }

        return SaScriptTombstone::query()
            ->where('source', trim($source))
            ->whereNull('restored_at')
            ->orderBy('script_id')
            ->pluck('script_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{ok: bool, script_id: string, source: string, already: bool}
     */
    public function record(
        string $scriptId,
        string $source,
        ?string $packageKey = null,
        ?string $contentDigest = null,
        ?int $removedByUserId = null,
    ): array {
        $scriptId = trim($scriptId);
        $source = trim($source);
        if ($scriptId === '' || $source === '' || ! $this->ready()) {
            return ['ok' => false, 'script_id' => $scriptId, 'source' => $source, 'already' => false];
        }

        $row = SaScriptTombstone::query()->firstOrNew([
            'script_id' => $scriptId,
            'source' => $source,
        ]);
        $already = $row->exists && $row->restored_at === null;

        $row->fill([
            'package_key' => $packageKey,
            'content_digest' => $contentDigest,
            'removed_by_user_id' => $removedByUserId,
            'removed_at' => now(),
            'restored_at' => null,
        ])->save();

        return ['ok' => true, 'script_id' => $scriptId, 'source' => $source, 'already' => $already];
    }

    public function restore(string $scriptId, string $source): bool
    {
        $scriptId = trim($scriptId);
        $source = trim($source);
        if ($scriptId === '' || $source === '' || ! $this->ready()) {
            return false;
        }

        $row = SaScriptTombstone::query()
            ->where('script_id', $scriptId)
            ->where('source', $source)
            ->whereNull('restored_at')
            ->first();
        if (! $row instanceof SaScriptTombstone) {
            return false;
        }
        $row->fill(['restored_at' => now()])->save();

        return true;
    }

    private function ready(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_tombstones');
        } catch (Throwable) {
            return false;
        }
    }
}
