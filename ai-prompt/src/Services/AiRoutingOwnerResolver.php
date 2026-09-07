<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use App\Models\ApiConnection;

/**
 * Deterministic routing/health owner — never rely on auth() in queue workers.
 */
final class AiRoutingOwnerResolver
{
    public function resolve(
        ?int $explicitUserId = null,
        ?SeoPrompt $prompt = null,
        ?ApiConnection $connection = null,
    ): int {
        if ($explicitUserId !== null && $explicitUserId > 0 && $this->userExists($explicitUserId)) {
            return $explicitUserId;
        }

        if ($connection instanceof ApiConnection
            && ! (bool) $connection->is_global
            && (int) $connection->user_id > 0
            && $this->userExists((int) $connection->user_id)) {
            return (int) $connection->user_id;
        }

        $promptUserId = (int) ($prompt?->user_id ?? 0);
        if ($promptUserId > 0 && $this->userExists($promptUserId)) {
            return $promptUserId;
        }

        if ($connection instanceof ApiConnection
            && (int) $connection->user_id > 0
            && $this->userExists((int) $connection->user_id)) {
            return (int) $connection->user_id;
        }

        // Interactive HTTP only — never the primary queue source.
        $authId = (int) (auth()->id() ?? 0);

        return $authId > 0 ? $authId : 0;
    }

    /**
     * Owner id used for connection-scoped health unlock/display.
     * Falls back to auth when the connection owner row is orphaned.
     */
    public function forConnection(ApiConnection $connection, ?int $fallbackUserId = null): int
    {
        $owner = $this->resolve(
            explicitUserId: null,
            prompt: null,
            connection: $connection,
        );
        if ($owner > 0) {
            return $owner;
        }
        $fallback = $fallbackUserId ?? (int) (auth()->id() ?? 0);

        return $fallback > 0 ? $fallback : 0;
    }

    protected function userExists(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            if (! class_exists(\App\Models\User::class)) {
                return true;
            }
            $model = new \App\Models\User();
            $table = $model->getTable();
            $connection = $model->getConnectionName();
            $schema = $connection !== null && $connection !== ''
                ? \Illuminate\Support\Facades\Schema::connection($connection)
                : \Illuminate\Support\Facades\Schema::getFacadeRoot();
            if (! $schema->hasTable($table)) {
                // Unit tests often omit users — treat ids as valid.
                return true;
            }

            return $model->newQuery()->whereKey($userId)->exists();
        } catch (\Throwable) {
            return true;
        }
    }
}
