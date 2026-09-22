<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: default Prompt + Settings binding for seo_keywords.topical_map_audit.
 *
 * Prefer CLI:
 *   php artisan seo:prompt:install-default-topical-map-audit
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultTopicalMapAuditPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            error_log('default_topical_map_audit_prompt_install_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Keep prompt + binding.
    }
};
