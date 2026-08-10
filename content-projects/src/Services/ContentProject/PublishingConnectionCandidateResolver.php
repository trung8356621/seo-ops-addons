<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Which seo_database_connections rows the publishing cron may touch.
 *
 * Does NOT bootstrap PDO here — eligibility is from core metadata only.
 * Orphan/demo rows (no users + demo-like DB name) are skipped so a stale
 * manual connection cannot poison scans or global health.
 */
final class PublishingConnectionCandidateResolver
{
    /**
     * @return Collection<int, SeoDatabaseConnection>
     */
    public function eligibleForPublishingScan(): Collection
    {
        if (! Schema::hasTable('seo_database_connections')) {
            return collect();
        }

        $query = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->withCount('users')
            ->orderBy('id');

        return $query->get()->filter(
            fn (SeoDatabaseConnection $connection): bool => $this->isEligible($connection) === null,
        )->values();
    }

    /**
     * @return list<array{connection: SeoDatabaseConnection, skip_reason: string}>
     */
    public function skippedActiveConnections(): array
    {
        if (! Schema::hasTable('seo_database_connections')) {
            return [];
        }

        $skipped = [];
        $rows = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->withCount('users')
            ->orderBy('id')
            ->get();

        foreach ($rows as $connection) {
            $reason = $this->isEligible($connection);
            if ($reason !== null) {
                $skipped[] = [
                    'connection' => $connection,
                    'skip_reason' => $reason,
                ];
            }
        }

        return $skipped;
    }

    /**
     * Null = eligible. Non-null = skip reason code.
     */
    public function isEligible(SeoDatabaseConnection $connection): ?string
    {
        if (! (bool) ($connection->is_active ?? false)) {
            return 'inactive';
        }

        $database = strtolower(trim((string) ($connection->database ?? '')));
        if ($database === '') {
            return 'empty_database';
        }

        $userCount = $connection->users_count ?? null;
        if ($userCount === null) {
            $userCount = $connection->users()->count();
        }
        $userCount = (int) $userCount;

        if ($this->looksLikeDemoOrLegacyOrphanDatabase($database)) {
            return $userCount === 0 ? 'orphan_demo_no_users' : 'demo_database';
        }

        // Manual rows with zero user assignments are orphan for publishing scan.
        if ($connection->isManual() && $userCount === 0) {
            return 'manual_orphan_no_users';
        }

        return null;
    }

    public function looksLikeDemoOrLegacyOrphanDatabase(string $database): bool
    {
        $database = strtolower(trim($database));
        if ($database === '') {
            return true;
        }

        if (str_contains($database, '_demo_')
            || str_starts_with($database, 'demo_')
            || str_ends_with($database, '_demo')
            || str_contains($database, 'demo_keywords')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Audit payload for one core row (no password).
     *
     * @return array<string, mixed>
     */
    public function auditRow(SeoDatabaseConnection $connection): array
    {
        $userCount = (int) ($connection->users_count ?? $connection->users()->count());
        $skip = $this->isEligible($connection);

        return [
            'connection_id' => (int) $connection->getKey(),
            'hash_id' => (string) $connection->hash_id,
            'name' => (string) $connection->name,
            'type' => (string) ($connection->type ?? ''),
            'database' => (string) ($connection->database ?? ''),
            'username' => (string) ($connection->username ?? ''),
            'host' => (string) ($connection->host ?? ''),
            'is_active' => (bool) $connection->is_active,
            'soft_deletes' => false,
            'users_count' => $userCount,
            'looks_like_demo' => $this->looksLikeDemoOrLegacyOrphanDatabase(
                (string) ($connection->database ?? ''),
            ),
            'publishing_eligible' => $skip === null,
            'skip_reason' => $skip,
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
            'created_via' => 'Admin Filament SEO Database Connections / site-service sync / legacy migration seed',
        ];
    }
}
