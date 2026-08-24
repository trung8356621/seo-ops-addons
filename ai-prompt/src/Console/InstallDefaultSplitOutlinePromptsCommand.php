<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: tạo 2 Prompt split (Outline + Vocabulary) + Settings bindings.
 * Dùng: php artisan seo:prompt:install-split-outline-prompts
 */
final class InstallDefaultSplitOutlinePromptsCommand extends Command
{
    protected $signature = 'seo:prompt:install-split-outline-prompts {--clear-hook-cache : Clear prompt hook definition cache after install}';

    protected $description = 'Idempotent install split Outline + Vocabulary prompts and hook bindings';

    public function handle(DefaultSplitOutlinePromptsInstaller $installer): int
    {
        $result = $installer->install();

        foreach (['outline' => 'Outline', 'vocabulary' => 'Vocabulary'] as $key => $label) {
            $row = $result[$key];
            $this->info(sprintf(
                '%s prompt_id=%d created=%s binding_set=%s',
                $label,
                $row['prompt_id'],
                $row['created'] ? 'yes' : 'no',
                $row['binding_set'] ? 'yes' : 'no',
            ));
        }

        if ($this->option('clear-hook-cache')) {
            $loader = app(PromptHookDefinitionLoader::class);
            $loader->clearCache();
            $this->comment('Prompt hook definition cache cleared.');
        }

        $this->comment('Legacy combined prompt (article.outline.generate) is unchanged.');

        return self::SUCCESS;
    }
}
