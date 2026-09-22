<?php

declare(strict_types=1);

/**
 * Upgrade Discover New Topics system prompt to immutable hook version 0.2.0
 * (company context + discovery_guidance + avoid_topics). Keeps 0.1.0 JSON intact.
 */

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        try {
            app(DefaultDiscoverNewTopicsPromptInstaller::class)->install(restoreCanonical: true);
        } catch (\Throwable $exception) {
            if (function_exists('logger')) {
                logger()->warning('discover_new_topics_prompt_0_2_0_upgrade_failed', [
                    'error' => $exception->getMessage(),
                ]);
            }
            error_log('discover_new_topics_prompt_0_2_0_upgrade_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Immutable prompt versions — do not roll content back automatically.
    }
};
