<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;

/**
 * Idempotent: tạo Prompt + Settings binding seeding.comment.generate nếu thiếu.
 * Dùng: php artisan seo:prompt:install-default-seeding-comment
 */
final class InstallDefaultSeedingCommentPromptCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-seeding-comment';

    protected $description = 'Idempotent install default Prompt + Settings binding for seeding.comment.generate';

    public function handle(DefaultSeedingCommentPromptInstaller $installer): int
    {
        $result = $installer->install();

        $this->info(sprintf(
            'prompt_id=%d created=%s binding_set=%s source=%s',
            $result['prompt_id'],
            $result['created'] ? 'yes' : 'no',
            $result['binding_set'] ? 'yes' : 'no',
            $result['source'],
        ));

        if (! $result['binding_set'] && ! $result['created']) {
            $this->comment('Binding đã có — không ghi đè Prompt Settings của operator.');
        }

        return self::SUCCESS;
    }
}
