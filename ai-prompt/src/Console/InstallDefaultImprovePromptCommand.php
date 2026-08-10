<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultImprovePromptInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: tạo Prompt + Settings binding article.content.improve nếu thiếu.
 * Dùng: php artisan seo:prompt:install-default-improve
 */
final class InstallDefaultImprovePromptCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-improve';

    protected $description = 'Idempotent install default Prompt + Settings binding for article.content.improve';

    public function handle(DefaultImprovePromptInstaller $installer): int
    {
        $result = $installer->install();

        $this->info(sprintf(
            'prompt_id=%d created=%s binding_set=%s',
            $result['prompt_id'],
            $result['created'] ? 'yes' : 'no',
            $result['binding_set'] ? 'yes' : 'no',
        ));

        if (! $result['binding_set'] && ! $result['created']) {
            $this->comment('Binding đã có — không ghi đè Prompt Settings của operator.');
        }

        return self::SUCCESS;
    }
}
