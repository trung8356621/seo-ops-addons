<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Collection;

/**
 * Publishing connection eligibility.
 *
 * Legacy seo_database_connections scanning is retired. Cron uses canonical
 * ServiceDatabaseConnection via SeoDatabaseConnectionService shared bootstrap.
 */
final class PublishingConnectionCandidateResolver
{
    /**
     * @return Collection<int, SeoDatabaseConnection>
     */
    public function eligibleForPublishingScan(): Collection
    {
        return collect();
    }

    /**
     * @return list<array{connection: SeoDatabaseConnection, skip_reason: string}>
     */
    public function skippedActiveConnections(): array
    {
        return [];
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

        // In-memory adapter from ServiceDatabaseConnection — no user pivot required.
        if ((int) $connection->getKey() <= 0
            && (string) ($connection->name ?? '') === 'Canonical Service DB'
        ) {
            return $this->looksLikeDemoOrLegacyOrphanDatabase($database) ? 'orphan_demo_no_users' : null;
        }

        $userCount = $connection->users_count ?? null;
        if ($userCount === null) {
            try {
                $userCount = $connection->users()->count();
            } catch (\Throwable) {
                $userCount = 0;
            }
        }
        $userCount = (int) $userCount;

        if ($this->looksLikeDemoOrLegacyOrphanDatabase($database)) {
            return $userCount === 0 ? 'orphan_demo_no_users' : 'demo_database';
        }

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
     * @return array<string, mixed>
     */
    public function auditRow(SeoDatabaseConnection $connection): array
    {
        $userCount = 0;
        try {
            $userCount = (int) ($connection->users_count ?? $connection->users()->count());
        } catch (\Throwable) {
            $userCount = (int) ($connection->users_count ?? 0);
        }
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
            'created_via' => 'canonical ServiceDatabaseConnection',
        ];
    }
}
