<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultImprovePromptInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: default Prompt + Settings binding for article.content.improve.
 *
 * Prefer CLI (plain php, no shell vars):
 *   php artisan seo:prompt:install-default-improve
 *
 * Migration vẫn chạy được khi SEO migrate; không phụ thuộc $PHP_BIN.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultImprovePromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            // Không dùng logger()/env var — tránh fail migrate khi channel/DB lock.
            error_log('default_improve_prompt_install_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Keep prompt + binding.
    }
};
