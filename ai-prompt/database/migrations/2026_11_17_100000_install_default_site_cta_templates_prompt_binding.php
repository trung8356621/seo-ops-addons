<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSiteCtaTemplatesPromptInstaller;

/**
 * Idempotent: default Prompt + Settings binding for site.cta.templates.generate.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultSiteCtaTemplatesPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            if (function_exists('logger')) {
                logger()->warning('default_site_cta_templates_prompt_install_failed', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep prompt + binding for rollback safety.
    }
};
