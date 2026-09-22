<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;

/**
 * Install/repair Discover New Topics + Topical Map Audit prompt ownership
 * so PromptResource (scoped by accountSiteOwnerId) can list them.
 *
 * Idempotent. Does not create duplicate named prompts. Does not delete history.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultDiscoverNewTopicsPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            if (function_exists('logger')) {
                logger()->warning('discover_new_topics_prompt_ownership_repair_failed', [
                    'error' => $exception->getMessage(),
                ]);
            } else {
                error_log('discover_new_topics_prompt_ownership_repair_failed: '.$exception->getMessage());
            }
        }

        try {
            app(DefaultTopicalMapAuditPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            if (function_exists('logger')) {
                logger()->warning('topical_map_audit_prompt_ownership_repair_failed', [
                    'error' => $exception->getMessage(),
                ]);
            } else {
                error_log('topical_map_audit_prompt_ownership_repair_failed: '.$exception->getMessage());
            }
        }
    }

    public function down(): void
    {
        // Keep ownership / history as-is.
    }
};
