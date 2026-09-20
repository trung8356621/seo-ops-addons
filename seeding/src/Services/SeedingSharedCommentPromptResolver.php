<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptVersionService;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptRenderer;
use RuntimeException;
use Throwable;

/**
 * Resolves the shared Prompt SSOT for seeding.comment.generate.
 * Does not read seeding_comment_prompt_settings for execution authority.
 */
class SeedingSharedCommentPromptResolver
{
    public function __construct(
        private readonly ?PromptBindingResolver $bindings = null,
        private readonly ?PromptVersionService $versions = null,
        private readonly ?SeedingCommentPromptRenderer $renderer = null,
    ) {}

    private function bindings(): PromptBindingResolver
    {
        if ($this->bindings instanceof PromptBindingResolver) {
            return $this->bindings;
        }

        if (! function_exists('app')) {
            throw new RuntimeException('Shared Prompt binding resolver is unavailable.');
        }

        return app(PromptBindingResolver::class);
    }

    private function versions(): PromptVersionService
    {
        if ($this->versions instanceof PromptVersionService) {
            return $this->versions;
        }

        if (function_exists('app') && app()->bound(PromptVersionService::class)) {
            return app(PromptVersionService::class);
        }

        return new PromptVersionService();
    }

    private function renderer(): SeedingCommentPromptRenderer
    {
        return $this->renderer ?? new SeedingCommentPromptRenderer();
    }

    public function resolvePrompt(): SeoPrompt
    {
        try {
            return $this->bindings()->resolveSettingsHook(DefaultSeedingCommentPromptInstaller::HOOK_KEY);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Shared Prompt for seeding.comment.generate is not configured. '
                .'Run: php artisan seo:prompt:install-default-seeding-comment',
                0,
                $e,
            );
        }
    }

    /**
     * @return array{
     *     prompt: SeoPrompt,
     *     prompt_id: int,
     *     prompt_version_id: int|null,
     *     hook_key: string,
     *     hook_version: string|null,
     *     body: string
     * }
     */
    public function resolveActive(): array
    {
        $prompt = $this->resolvePrompt();
        $version = $this->versions()->ensureCurrentVersion($prompt);
        $body = trim((string) ($prompt->markdown_content ?? ''));
        if ($body === '') {
            throw new RuntimeException('Shared Prompt seeding.comment.generate has empty markdown_content.');
        }

        return [
            'prompt' => $prompt,
            'prompt_id' => (int) $prompt->id,
            'prompt_version_id' => $version instanceof PromptVersion ? (int) $version->id : null,
            'hook_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
            'hook_version' => $prompt->hook_version !== null ? (string) $prompt->hook_version : null,
            'body' => (string) $prompt->markdown_content,
        ];
    }

    public function renderFinalPrompt(string $mcpContext): string
    {
        $active = $this->resolveActive();

        return $this->renderer()->render($active['body'], $mcpContext);
    }

    /**
     * @return list<string>
     */
    public function supportedVariables(): array
    {
        return $this->renderer()->supportedVariables();
    }
}
