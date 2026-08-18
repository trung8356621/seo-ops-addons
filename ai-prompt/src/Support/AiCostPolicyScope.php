<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Request/run-scoped cost policy. Default is always {@see AiCostPolicy::Default}.
 */
final class AiCostPolicyScope
{
    private static ?AiCostPolicy $current = null;

    public static function current(): AiCostPolicy
    {
        return self::$current ?? AiCostPolicy::Default;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(AiCostPolicy $policy, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $policy;
        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }
}
