<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

/**
 * Rollback = set mode legacy + refresh config/cache. Never deletes definitions/logs.
 * Never runs legacy write in same request after hook provider already called.
 */
final class PromptHookRollbackPolicy
{
    public function targetMode(): PromptHookRuntimeMode
    {
        return PromptHookRuntimeMode::Legacy;
    }

    public function deletesDefinitions(): bool
    {
        return false;
    }

    public function deletesExecutionLogs(): bool
    {
        return false;
    }

    /** Silent same-request legacy fallback after provider cost is forbidden. */
    public function allowsSilentLegacyFallbackAfterProviderCall(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function hostingSteps(string $hookKey): array
    {
        $envKey = $this->envKeyFor($hookKey);

        return [
            "Set {$envKey}=legacy (or config prompt_hooks.migration.{$hookKey}=legacy)",
            'php artisan optimize:clear',
            'php artisan config:clear',
            'php artisan cache:clear',
            'php artisan seo:prompt-hooks:clear-cache',
            'php artisan queue:restart',
        ];
    }

    private function envKeyFor(string $hookKey): string
    {
        return match ($hookKey) {
            'article.outline.generate' => 'PROMPT_HOOK_MIGRATION_ARTICLE_OUTLINE_GENERATE',
            'article.faq.generate' => 'PROMPT_HOOK_MIGRATION_ARTICLE_FAQ_GENERATE',
            'keyword.discovery.structured' => 'PROMPT_HOOK_MIGRATION_KEYWORD_DISCOVERY_STRUCTURED',
            'article.title_suggestion' => 'PROMPT_HOOK_MIGRATION_ARTICLE_TITLE_SUGGESTION',
            'article.meta_description_suggestion' => 'PROMPT_HOOK_MIGRATION_ARTICLE_META_DESCRIPTION_SUGGESTION',
            default => 'PROMPT_HOOK_MIGRATION_'.strtoupper(str_replace('.', '_', $hookKey)),
        };
    }
}
