<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Hooks whose model authority + prompt shape rules apply (no application output ceiling).
 */
final class ArticleContentGenerationHooks
{
    public const GENERATE = 'article.content.generate';

    public const REWRITE = 'article.content.rewrite';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::GENERATE, self::REWRITE];
    }

    public static function matches(?string $hookKey): bool
    {
        $hook = strtolower(trim((string) $hookKey));

        return in_array($hook, self::keys(), true);
    }
}
