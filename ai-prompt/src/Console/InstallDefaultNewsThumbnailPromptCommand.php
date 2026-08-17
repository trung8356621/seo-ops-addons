<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultNewsThumbnailPromptInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: tạo Prompt + Settings binding article.featured_image.generate nếu thiếu.
 * Dùng: php artisan seo:prompt:install-default-news-thumbnail
 */
final class InstallDefaultNewsThumbnailPromptCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-news-thumbnail';

    protected $description = 'Idempotent install default Prompt + Settings binding for Create news thumbnail';

    public function handle(DefaultNewsThumbnailPromptInstaller $installer): int
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
