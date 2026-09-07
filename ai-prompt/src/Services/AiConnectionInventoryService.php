<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Single source of truth for AI API connection inventory (Settings + AI Center + runtime).
 *
 * Canonical store: App\Models\ApiConnection → core mysql → physical omi_client.api_connections.
 * Health/locks affect badges, never whether a configured row is listed.
 */
final class AiConnectionInventoryService
{
    public const CACHE_TAG = 'ai_connection_inventory';

    /**
     * Configured AI connections visible to the viewer (includes unhealthy / locked / bad credentials).
     *
     * @return Collection<int, ApiConnection>
     */
    public function configuredAiConnections(int $viewerUserId): Collection
    {
        /** @var Collection<int, ApiConnection> $rows */
        $rows = $this->queryForViewer($viewerUserId)->orderBy('name')->get();

        return $rows
            ->filter(static function (ApiConnection $connection): bool {
                $provider = (string) $connection->provider;
                if ($provider === '') {
                    return false;
                }
                if (ApiConnectionProviders::isExternal($provider) || ApiConnectionProviders::isSeo($provider)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * Visibility-scoped query against the canonical ApiConnection model (core DB).
     */
    public function queryForViewer(int $viewerUserId): Builder
    {
        $query = ApiConnection::query();

        if (! $this->viewerSeesWorkspaceInventory($viewerUserId)) {
            $query->where(function (Builder $inner) use ($viewerUserId): void {
                $inner->where('user_id', $viewerUserId)
                    ->orWhere('is_global', true);
            });
        }

        return $query;
    }

    /**
     * @return array{
     *   configured: int,
     *   active: int,
     *   inactive: int,
     *   credential_usable: int,
     *   credential_unusable: int
     * }
     */
    public function counts(int $viewerUserId): array
    {
        $rows = $this->configuredAiConnections($viewerUserId);
        $configured = $rows->count();
        $active = 0;
        $inactive = 0;
        $usable = 0;
        $unusable = 0;
        foreach ($rows as $row) {
            if ((string) $row->status === 'inactive') {
                $inactive++;
            } else {
                $active++;
            }
            if (AiConnectionCredential::isUsable($row->api_key)) {
                $usable++;
            } else {
                $unusable++;
            }
        }

        return [
            'configured' => $configured,
            'active' => $active,
            'inactive' => $inactive,
            'credential_usable' => $usable,
            'credential_unusable' => $unusable,
        ];
    }

    /**
     * Owner/admin share one SEO workspace inventory (Settings must not show 0 while another
     * owner-owned OpenRouter is still the runtime credential).
     */
    public function viewerSeesWorkspaceInventory(int $viewerUserId): bool
    {
        if ($viewerUserId <= 0) {
            return false;
        }
        try {
            // Avoid SoftDeletes/Eloquent hydration requirements — role is enough.
            $role = (string) (\Illuminate\Support\Facades\DB::table('users')
                ->where('id', $viewerUserId)
                ->value('role') ?? '');

            return in_array($role, [
                defined(User::class.'::ROLE_OWNER') ? User::ROLE_OWNER : 'owner',
                defined(User::class.'::ROLE_ADMIN') ? User::ROLE_ADMIN : 'admin',
            ], true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function forgetCache(): void
    {
        try {
            Cache::forget(self::CACHE_TAG);
        } catch (\Throwable) {
        }
    }
}
