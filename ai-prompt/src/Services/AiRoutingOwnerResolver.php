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
        if ($explicitUserId !== null && $explicitUserId > 0) {
            return $explicitUserId;
        }

        if ($connection instanceof ApiConnection
            && ! (bool) $connection->is_global
            && (int) $connection->user_id > 0) {
            return (int) $connection->user_id;
        }

        $promptUserId = (int) ($prompt?->user_id ?? 0);
        if ($promptUserId > 0) {
            return $promptUserId;
        }

        if ($connection instanceof ApiConnection && (int) $connection->user_id > 0) {
            return (int) $connection->user_id;
        }

        // Interactive HTTP only — never the primary queue source.
        $authId = (int) (auth()->id() ?? 0);

        return $authId > 0 ? $authId : 0;
    }
}
