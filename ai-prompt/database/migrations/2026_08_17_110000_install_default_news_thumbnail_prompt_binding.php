<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultNewsThumbnailPromptInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: default Prompt + Settings binding for article.featured_image.generate.
 *
 * Prefer CLI:
 *   php artisan seo:prompt:install-default-news-thumbnail
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultNewsThumbnailPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            error_log('default_news_thumbnail_prompt_install_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Keep prompt + binding.
    }
};
