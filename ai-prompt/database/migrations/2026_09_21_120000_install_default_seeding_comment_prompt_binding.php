<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;

/**
 * Idempotent: default Prompt + Settings binding for seeding.comment.generate.
 * Bootstraps from omi_seeding.seeding_comment_prompt_settings when present.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultSeedingCommentPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            if (function_exists('logger')) {
                logger()->warning('default_seeding_comment_prompt_install_failed', [
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
