<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use App\Models\User;
use App\Models\UserMeta;

/**
 * LEGACY per-user preference store for «Chia bài theo dàn ý».
 *
 * Runtime authority for new runs is route_cost_auto
 * ({@see \Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver}).
 * Values may remain in user_meta for BC/data safety but must not decide
 * generation_shape. Legacy article_meta.writing_split_enabled is ignored.
 *
 * @deprecated Prefer GenerationShapeResolver / generation_shape snapshot.
 */
final class WritingSplitPreference
{
    public const META_KEY = 'writing_split_enabled';

    /**
     * @deprecated Not runtime authority — retained for BC readers / History.
     *
     * @param  array<string, mixed>  $variables
     */
    public static function resolveForRun(array $variables, ?int $actorUserId = null): bool
    {
        if (array_key_exists(self::META_KEY, $variables)) {
            return self::coerceBool($variables[self::META_KEY]);
        }

        $userId = $actorUserId !== null && $actorUserId > 0
            ? $actorUserId
            : self::resolveUserIdFromVariables($variables);

        return self::enabledForUserId($userId);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function enabledFromVariables(array $variables): bool
    {
        return self::resolveForRun($variables, null);
    }

    public static function enabledForUserId(?int $userId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }

        try {
            $raw = UserMeta::query()
                ->where('user_id', $userId)
                ->where('meta_key', self::META_KEY)
                ->value('meta_value');
        } catch (\Throwable) {
            return false;
        }

        if ($raw === null) {
            return false;
        }

        return self::coerceBool($raw);
    }

    /**
     * Persist legacy preference only — does not control new execution shape.
     */
    public static function persistForUserId(int $userId, bool $enabled): void
    {
        if ($userId <= 0) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            return;
        }

        $user->setMeta(self::META_KEY, $enabled ? '1' : '0');
    }

    /**
     * @deprecated Article meta is no longer authority — kept for static discovery only.
     */
    public static function enabledForArticleId(int $articleId): bool
    {
        unset($articleId);

        return false;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private static function resolveUserIdFromVariables(array $variables): ?int
    {
        foreach (['preference_user_id', 'actor_user_id', 'initiated_by_user_id', 'user_id'] as $key) {
            $id = (int) ($variables[$key] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        try {
            $authId = (int) (auth()->id() ?? 0);
            if ($authId > 0) {
                return $authId;
            }
        } catch (\Throwable) {
            // queue / unbound auth
        }

        return null;
    }

    public static function coerceBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
