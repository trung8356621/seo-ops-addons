<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use App\Models\User;
use App\Models\UserMeta;

/**
 * User-facing article generation mode: NORMAL | FREE_ONLY.
 *
 * Persists via existing {@see AiCostPolicy::SETTING_KEY} (`ai_cost_policy`) —
 * same SSOT as Content Project run snapshot. Not a universal account AI policy;
 * callers must stamp into run/variables at article-generation start.
 */
final class ArticleGenerationModePreference
{
    public const META_KEY = AiCostPolicy::SETTING_KEY;

    public static function forUserId(?int $userId): AiCostPolicy
    {
        if ($userId === null || $userId <= 0) {
            return AiCostPolicy::Default;
        }

        try {
            $raw = UserMeta::query()
                ->where('user_id', $userId)
                ->where('meta_key', self::META_KEY)
                ->value('meta_value');
        } catch (\Throwable) {
            return AiCostPolicy::Default;
        }

        if ($raw === null) {
            return AiCostPolicy::Default;
        }

        return AiCostPolicy::tryFromMixed($raw);
    }

    public static function persistForUserId(int $userId, AiCostPolicy $policy): void
    {
        if ($userId <= 0) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            return;
        }

        $user->setMeta(self::META_KEY, $policy->value);
    }

    /**
     * Snapshot for a new article-generation run. Prefer existing stamped value.
     *
     * @param  array<string, mixed>  $variablesOrSettings
     */
    public static function resolveForRun(array $variablesOrSettings, ?int $actorUserId = null): AiCostPolicy
    {
        if (array_key_exists(self::META_KEY, $variablesOrSettings)) {
            return AiCostPolicy::tryFromMixed($variablesOrSettings[self::META_KEY]);
        }

        // Alias accepted for BC / observability naming.
        if (array_key_exists('ai_generation_mode', $variablesOrSettings)) {
            return AiCostPolicy::tryFromMixed($variablesOrSettings['ai_generation_mode']);
        }

        $userId = $actorUserId !== null && $actorUserId > 0
            ? $actorUserId
            : self::resolveUserIdFromVariables($variablesOrSettings);

        return self::forUserId($userId);
    }

    /**
     * Stamp immutable mode into variables if absent. Returns updated bag.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function stampIntoVariables(array $variables, ?int $actorUserId = null): array
    {
        if (array_key_exists(self::META_KEY, $variables)) {
            $policy = AiCostPolicy::tryFromMixed($variables[self::META_KEY]);
            $variables[self::META_KEY] = $policy->value;
            $variables['ai_generation_mode'] = $policy->generationModeValue();

            return $variables;
        }

        $policy = self::resolveForRun($variables, $actorUserId);
        $variables[self::META_KEY] = $policy->value;
        $variables['ai_generation_mode'] = $policy->generationModeValue();

        return $variables;
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
}
