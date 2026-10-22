<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Pure bidirectional Script catalog reconciliation.
 * Never silently overwrites divergent digests.
 *
 * @phpstan-type ScriptRow array{
 *     script_id: string,
 *     package_key: string,
 *     version: string,
 *     content_digest: string,
 *     execution_digest?: string|null,
 *     lineage?: list<string>|null,
 *     assets_complete?: bool|null
 * }
 */
final class ScriptSyncPlanner
{
    /**
     * @param  list<ScriptRow>  $local   RPA Admin catalog
     * @param  list<ScriptRow>  $remote  SEO-OPS Script Registry catalog
     * @param  list<string>  $suppressedScriptIds  Active SEO-OPS tombstones (stable script_id)
     * @return array{
     *     push_to_remote: list<ScriptRow>,
     *     pull_to_local: list<ScriptRow>,
     *     skip: list<array{script_id: string, reason: string}>,
     *     conflicts: list<array{script_id: string, local: ScriptRow, remote: ScriptRow, reason: string}>
     * }
     */
    public function plan(array $local, array $remote, array $suppressedScriptIds = []): array
    {
        $localById = $this->indexByScriptId($local);
        $remoteById = $this->indexByScriptId($remote);
        $suppressed = [];
        foreach ($suppressedScriptIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $suppressed[$id] = true;
            }
        }

        $push = [];
        $pull = [];
        $skip = [];
        $conflicts = [];

        $allIds = array_values(array_unique(array_merge(array_keys($localById), array_keys($remoteById))));
        sort($allIds);

        foreach ($allIds as $scriptId) {
            $l = $localById[$scriptId] ?? null;
            $r = $remoteById[$scriptId] ?? null;

            if (isset($suppressed[$scriptId])) {
                // Removed on SEO-OPS — never auto push/pull recreate for this script_id.
                $skip[] = ['script_id' => $scriptId, 'reason' => 'removed_on_server'];
                continue;
            }

            if ($l !== null && $r === null) {
                $push[] = $l;
                continue;
            }
            if ($l === null && $r !== null) {
                $pull[] = $r;
                continue;
            }
            if ($l === null || $r === null) {
                continue;
            }

            if (PackageDigest::equals((string) $l['content_digest'], (string) $r['content_digest'])) {
                $skip[] = ['script_id' => $scriptId, 'reason' => 'same_digest'];
                continue;
            }

            $lineage = $this->resolveLineage($l, $r);
            if ($lineage === 'local_ahead') {
                $push[] = $l;
                continue;
            }
            if ($lineage === 'remote_ahead') {
                $pull[] = $r;
                continue;
            }

            $conflicts[] = [
                'script_id' => $scriptId,
                'local' => $l,
                'remote' => $r,
                'reason' => 'divergent_edits',
            ];
        }

        return [
            'push_to_remote' => $push,
            'pull_to_local' => $pull,
            'skip' => $skip,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  list<ScriptRow>  $rows
     * @return array<string, ScriptRow>
     */
    private function indexByScriptId(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['script_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * @param  ScriptRow  $local
     * @param  ScriptRow  $remote
     */
    private function resolveLineage(array $local, array $remote): string
    {
        $localLineage = is_array($local['lineage'] ?? null) ? $local['lineage'] : [];
        $remoteLineage = is_array($remote['lineage'] ?? null) ? $remote['lineage'] : [];

        $remoteDigest = strtolower((string) $remote['content_digest']);
        $localDigest = strtolower((string) $local['content_digest']);

        foreach ($localLineage as $ancestor) {
            if (is_string($ancestor) && hash_equals(strtolower($ancestor), $remoteDigest)) {
                return 'local_ahead';
            }
        }
        foreach ($remoteLineage as $ancestor) {
            if (is_string($ancestor) && hash_equals(strtolower($ancestor), $localDigest)) {
                return 'remote_ahead';
            }
        }

        // Semver compare only when one side is a strict descendant version AND digests differ
        // without lineage — still conflict (independent edits). Lineage is authoritative.
        return 'conflict';
    }
}
