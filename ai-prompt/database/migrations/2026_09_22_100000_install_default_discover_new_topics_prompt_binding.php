<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: default Prompt + Settings binding for seo_audit.discover_new_topics.
 *
 * Prefer CLI:
 *   php artisan seo:prompt:install-default-discover-new-topics
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultDiscoverNewTopicsPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            error_log('default_discover_new_topics_prompt_install_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Keep prompt + binding.
    }
};
