<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

/**
 * Narrow contract for workflow/editor explicit hook execution (testable without mocking final class).
 */
interface PromptHookBindingRunner
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $contextExtras
     * @param  array<string, mixed>  $previousOutputs
     * @return array<string, mixed>
     */
    public function execute(
        SeoPrompt $prompt,
        array $variables = [],
        array $contextExtras = [],
        array $previousOutputs = [],
    ): array;
}
