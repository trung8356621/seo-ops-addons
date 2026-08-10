<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Console;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultProductGalleryPromptsInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: tạo 3 Prompt Mode 2 + Settings binding nếu trống.
 * Dùng: php artisan seo:prompt:install-default-product-gallery
 */
final class InstallDefaultProductGalleryPromptsCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-product-gallery';

    protected $description = 'Idempotent install default Prompts + Settings bindings for product.gallery.{plan,parent,child}';

    public function handle(DefaultProductGalleryPromptsInstaller $installer): int
    {
        $result = $installer->install();

        foreach (['plan', 'parent', 'child'] as $key) {
            $row = $result[$key];
            $this->info(sprintf(
                '%s prompt_id=%d created=%s binding_set=%s',
                $key,
                $row['prompt_id'],
                $row['created'] ? 'yes' : 'no',
                $row['binding_set'] ? 'yes' : 'no',
            ));
        }

        $this->comment('Không ghi đè binding/Prompt content đã có.');

        return self::SUCCESS;
    }
}
