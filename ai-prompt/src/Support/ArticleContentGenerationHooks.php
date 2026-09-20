<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Hooks whose model authority + prompt shape rules apply (no application output ceiling).
 *
 * Runtime writing is article.content.generate only.
 * Historical article.content.rewrite snapshots remain readable via history classifiers —
 * not via this allowlist.
 */
final class ArticleContentGenerationHooks
{
    public const GENERATE = 'article.content.generate';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::GENERATE];
    }

    public static function matches(?string $hookKey): bool
    {
        $hook = strtolower(trim((string) $hookKey));

        return in_array($hook, self::keys(), true);
    }
}
