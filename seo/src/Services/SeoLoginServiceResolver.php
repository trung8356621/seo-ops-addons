<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\SeoDatabaseConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

/**
 * Post-auth service/workspace resolution for SEO panel login.
 *
 * Explicit hash login preserves that hash.
 * Short /seo/login uses Main Service when configured; never picks a random first()
 * connection for multi-service accounts without Main Service.
 */
class SeoLoginServiceResolver
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly SeoMainDomainService $mainDomainService,
    ) {}

    /**
     * @return array{hash: ?string, use_short_url: bool, needs_selection: bool}
     */
    public function resolveAfterLogin(User $user, ?string $explicitHash = null): array
    {
        if (is_string($explicitHash) && SeoConnectionContext::isValidHashFormat($explicitHash)) {
            $connection = SeoDatabaseConnection::query()
                ->where('hash_id', $explicitHash)
                ->where('is_active', true)
                ->first();

            if (
                $connection instanceof SeoDatabaseConnection
                && $this->databaseConnection->userCanAccessConnection($user, $connection)
            ) {
                return [
                    'hash' => (string) $connection->hash_id,
                    'use_short_url' => false,
                    'needs_selection' => false,
                ];
            }
        }

        $connections = $this->accessibleConnections($user);

        if ($connections->isEmpty()) {
            return [
                'hash' => null,
                'use_short_url' => false,
                'needs_selection' => true,
            ];
        }

        if ($connections->count() === 1) {
            /** @var SeoDatabaseConnection $only */
            $only = $connections->first();

            return [
                'hash' => (string) $only->hash_id,
                'use_short_url' => true,
                'needs_selection' => false,
            ];
        }

        $mainHash = $this->resolveMainConnectionHash($user, $connections);
        if ($mainHash !== null) {
            return [
                'hash' => $mainHash,
                'use_short_url' => true,
                'needs_selection' => false,
            ];
        }

        return [
            'hash' => null,
            'use_short_url' => false,
            'needs_selection' => true,
        ];
    }

    public function redirectUrlAfterLogin(User $user, ?string $explicitHash = null, ?string $intended = null): string
    {
        if (is_string($intended) && $intended !== '' && $this->isSafeInternalUrl($intended)) {
            $resolution = $this->resolveAfterLogin($user, $explicitHash);
            if ($resolution['hash'] !== null) {
                SeoConnectionContext::rememberHash($resolution['hash']);
            }

            return $intended;
        }

        $resolution = $this->resolveAfterLogin($user, $explicitHash);

        if ($resolution['hash'] === null) {
            return url('/seo');
        }

        SeoConnectionContext::rememberHash($resolution['hash']);

        if ($resolution['use_short_url'] === true) {
            return url('/seo');
        }

        return url('/seo/'.$resolution['hash']);
    }

    public function resolveMainConnectionHashForUser(User $user): ?string
    {
        $connections = $this->accessibleConnections($user);
        if ($connections->isEmpty()) {
            return null;
        }

        if ($connections->count() === 1) {
            /** @var SeoDatabaseConnection $only */
            $only = $connections->first();

            return (string) $only->hash_id;
        }

        return $this->resolveMainConnectionHash($user, $connections);
    }

    public function isMainConnectionHash(User $user, string $hash): bool
    {
        $main = $this->resolveMainConnectionHashForUser($user);

        return $main !== null && hash_equals($main, $hash);
    }

    /**
     * @return Collection<int, SeoDatabaseConnection>
     */
    public function accessibleConnections(User $user): Collection
    {
        $query = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->orderBy('id');

        $ownerId = $this->databaseConnection->resolveOwnerIdForUser($user);
        if ($ownerId <= 0) {
            return collect();
        }

        $query->whereHas(
            'users',
            static fn ($builder) => $builder->where('users.id', $ownerId),
        );

        return $query->get();
    }

    /**
     * @param  Collection<int, SeoDatabaseConnection>  $connections
     */
    private function resolveMainConnectionHash(User $user, Collection $connections): ?string
    {
        $ownerId = $this->databaseConnection->resolveOwnerIdForUser($user);
        if ($ownerId <= 0) {
            return null;
        }

        $mainSiteId = $this->mainDomainService->primarySiteIdForOwner($ownerId);
        if ($mainSiteId === null) {
            return null;
        }

        $site = Site::query()->find($mainSiteId);
        if (! $site instanceof Site) {
            return null;
        }

        $siteOwnerId = (int) $site->user_id;
        if ($siteOwnerId <= 0) {
            return null;
        }

        $mainConnection = $this->databaseConnection->resolveActiveConnectionForOwner($siteOwnerId);
        if (! $mainConnection instanceof SeoDatabaseConnection) {
            return null;
        }

        $hash = (string) $mainConnection->hash_id;
        $allowed = $connections->contains(
            static fn (SeoDatabaseConnection $connection): bool => (string) $connection->hash_id === $hash,
        );

        return $allowed ? $hash : null;
    }

    private function isSafeInternalUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);

        return is_string($appHost)
            && is_string($urlHost)
            && strcasecmp($appHost, $urlHost) === 0;
    }
}
