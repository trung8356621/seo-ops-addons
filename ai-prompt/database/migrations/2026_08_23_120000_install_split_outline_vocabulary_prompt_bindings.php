<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: split Outline + Vocabulary prompts + Settings bindings.
 *
 * Prefer CLI:
 *   php artisan seo:prompt:install-split-outline-prompts --clear-hook-cache
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultSplitOutlinePromptsInstaller::class)->install();
        } catch (\Throwable $exception) {
            error_log('split_outline_vocabulary_prompt_install_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Keep prompts + bindings.
    }
};
