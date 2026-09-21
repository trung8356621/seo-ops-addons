<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;

/**
 * Repair seeding.comment.generate Prompt ownership for PromptResource list scope.
 * Keeps canonical prompt_id; does not create a duplicate Prompt.
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
                logger()->warning('seeding_comment_prompt_ownership_repair_failed', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep ownership as-is.
    }
};
