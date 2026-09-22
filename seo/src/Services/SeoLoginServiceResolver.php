<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Support\Collection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;

/**
 * Post-auth service/workspace resolution for SEO panel login.
 *
 * Canonical plane: one Service → ServiceDatabaseConnection (shared omi_seo_ai).
 * Short /seo is the default; explicit hash is remembered for session only.
 */
class SeoLoginServiceResolver
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    /**
     * @return array{hash: ?string, use_short_url: bool, needs_selection: bool}
     */
    public function resolveAfterLogin(User $user, ?string $explicitHash = null): array
    {
        if (! $this->userHasSeoAccess($user)) {
            return [
                'hash' => null,
                'use_short_url' => false,
                'needs_selection' => true,
            ];
        }

        $canonical = $this->databaseConnection->bootstrapCanonicalSharedConnection();
        if ($canonical === null) {
            $this->databaseConnection->bootstrapLegacySharedConnection();
            $canonical = $this->databaseConnection->bootstrapCanonicalSharedConnection();
        }

        $canonicalHash = $canonical instanceof SeoDatabaseConnection
            ? (string) $canonical->hash_id
            : hash('sha256', 'service_database_connection:seo');

        if (is_string($explicitHash) && SeoConnectionContext::isValidHashFormat($explicitHash)) {
            return [
                'hash' => $explicitHash,
                'use_short_url' => false,
                'needs_selection' => false,
            ];
        }

        return [
            'hash' => $canonicalHash,
            'use_short_url' => true,
            'needs_selection' => false,
        ];
    }

    public function redirectUrlAfterLogin(User $user, ?string $explicitHash = null, ?string $intended = null): string
    {
        // Canonical auth does not preserve intended/return URLs across login.
        unset($intended);

        $resolution = $this->resolveAfterLogin($user, $explicitHash);

        if ($resolution['hash'] === null) {
            return url('/workspace');
        }

        SeoConnectionContext::rememberHash($resolution['hash']);

        if ($resolution['use_short_url'] === true) {
            return url('/seo');
        }

        return url('/seo/'.$resolution['hash']);
    }

    public function resolveMainConnectionHashForUser(User $user): ?string
    {
        $resolution = $this->resolveAfterLogin($user);

        return $resolution['hash'];
    }

    public function isMainConnectionHash(User $user, string $hash): bool
    {
        $main = $this->resolveMainConnectionHashForUser($user);

        return $main !== null && hash_equals($main, $hash);
    }

    /**
     * Canonical plane exposes at most one synthetic connection adapter.
     *
     * @return Collection<int, SeoDatabaseConnection>
     */
    public function accessibleConnections(User $user): Collection
    {
        if (! $this->userHasSeoAccess($user)) {
            return collect();
        }

        $canonical = $this->databaseConnection->bootstrapCanonicalSharedConnection();
        if ($canonical instanceof SeoDatabaseConnection) {
            return collect([$canonical]);
        }

        return collect();
    }

    private function userHasSeoAccess(User $user): bool
    {
        $ownerId = $this->databaseConnection->resolveOwnerIdForUser($user);
        if ($ownerId <= 0) {
            return false;
        }

        try {
            if (app(SiteServiceBindingService::class)->ownerHasActiveSeoService($ownerId)) {
                return true;
            }
        } catch (\Throwable) {
            // site_services may be absent in disposable tests — fall through.
        }

        try {
            if ($this->databaseConnection->bootstrapCanonicalSharedConnection() instanceof SeoDatabaseConnection) {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }

        return in_array((string) ($user->role ?? ''), [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }
}
