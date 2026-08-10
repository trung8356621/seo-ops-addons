<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultCommentPromptInstaller;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: default Prompt + Settings binding for article.comment.generate.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultCommentPromptInstaller::class)->install();
        } catch (\Throwable $exception) {
            if (function_exists('logger')) {
                logger()->warning('default_comment_prompt_install_failed', [
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
