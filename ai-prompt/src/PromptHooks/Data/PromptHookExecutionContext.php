<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Data;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

final class PromptHookExecutionContext
{
    /**
     * @param  array<string, mixed>  $runtimeInput
     * @param  array<string, mixed>  $entityContext
     * @param  array<string, mixed>  $resolvedInput
     * @param  array<string, mixed>  $resolvedSettings
     * @param  array<string, string>  $promptVariables
     */
    public function __construct(
        public readonly PromptHookDefinition $definition,
        public readonly SeoPrompt $prompt,
        public readonly SeoArticle $article,
        public readonly array $runtimeInput,
        public readonly array $entityContext,
        public readonly array $resolvedInput,
        public readonly array $resolvedSettings,
        public readonly array $promptVariables,
        public readonly string $finalPrompt,
    ) {}
}
