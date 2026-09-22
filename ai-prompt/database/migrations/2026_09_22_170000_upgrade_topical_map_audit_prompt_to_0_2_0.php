<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: upgrade seo_keywords.topical_map_audit binding/content to @0.2.0.
 * Historical @0.1.0 Hook JSON remains on disk for AI History version fidelity.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultTopicalMapAuditPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            error_log('topical_map_audit_prompt_0_2_0_upgrade_failed: '.$exception->getMessage());
        }
    }

    public function down(): void
    {
        // Keep prompt + binding at current version.
    }
};
