<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultKeywordDiscoveryPromptInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: default Prompt + Settings binding for keyword.discovery.structured.
 *
 * Prefer CLI:
 *   php artisan seo:prompt:install-default-keyword-discovery
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultKeywordDiscoveryPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            error_log('default_keyword_discovery_prompt_install_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Keep prompt + binding.
    }
};
