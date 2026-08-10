<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Illuminate\Console\Command;

final class ClearPromptHookDefinitionCacheCommand extends Command
{
    protected $signature = 'seo:prompt-hooks:clear-cache';

    protected $description = 'Clear Prompt Hook definition cache (Spec v0.1 + Phase 1 dual-read)';

    public function handle(PromptHookRuntimeRegistry $registry): int
    {
        $registry->clearCache();
        $this->info('Prompt hook definition cache cleared.');

        return self::SUCCESS;
    }
}
