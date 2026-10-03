<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultFaqPromptInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: tạo Prompt + Settings binding article.faq.generate nếu thiếu.
 * Dùng: php artisan seo:prompt:install-default-faq
 * Reset markdown về canonical: --restore
 */
final class InstallDefaultFaqPromptCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-faq {--restore : Restore markdown from canonical Hook spec}';

    protected $description = 'Idempotent install default Prompt + Settings binding for FAQ Generation';

    public function handle(DefaultFaqPromptInstaller $installer): int
    {
        $result = $installer->install(restoreCanonical: (bool) $this->option('restore'));

        $this->info(sprintf(
            'prompt_id=%d created=%s binding_set=%s restored=%s',
            $result['prompt_id'],
            $result['created'] ? 'yes' : 'no',
            $result['binding_set'] ? 'yes' : 'no',
            $result['restored'] ? 'yes' : 'no',
        ));

        if (! $result['binding_set'] && ! $result['created'] && ! $result['restored']) {
            $this->comment('Binding đã có — không ghi đè Prompt Settings / markdown của operator.');
        }

        return self::SUCCESS;
    }
}
