<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPromotionThresholds;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Illuminate\Console\Command;

final class PromptHookStatusCommand extends Command
{
    protected $signature = 'seo:prompt-hooks:status';

    protected $description = 'List Prompt Hook registry versions, migration modes, promotion thresholds';

    public function handle(
        PromptHookRuntimeRegistry $registry,
        PromptHookMigrationFlags $flags,
        PromptHookPromotionThresholds $thresholds,
    ): int {
        $hooks = [
            'article.outline.generate',
            'article.faq.generate',
            'keyword.discovery.structured',
            'article.title_suggestion',
            'article.meta_description_suggestion',
        ];

        $rows = [];
        foreach ($hooks as $key) {
            $version = '0.1.0';
            $has = $registry->has($key, $version);
            $status = 'missing';
            if ($has) {
                $def = $registry->get($key, $version);
                $status = $def->status->value;
            }
            $rows[] = [
                $key,
                $version,
                $status,
                $flags->mode($key)->value,
                (string) $thresholds->forHook($key),
                $has ? 'yes' : 'no',
            ];
        }

        $this->table(
            ['hook', 'version', 'status', 'mode', 'threshold', 'registered'],
            $rows,
        );
        $this->line('live_shadow_enabled='.(config('seo-content-ai.prompt_hooks.live_shadow_enabled') ? 'true' : 'false'));
        $this->line('budget_store='.(string) config('seo-content-ai.prompt_hooks.budget_store', 'memory'));
        $this->comment('Live shadow multi-worker remains blocked with in-memory budget store.');

        return self::SUCCESS;
    }
}
