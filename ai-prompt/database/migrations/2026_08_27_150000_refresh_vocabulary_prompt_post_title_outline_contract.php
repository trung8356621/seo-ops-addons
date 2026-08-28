<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;

/**
 * Refresh Vocabulary system prompt so {{post_title}} + {{outline}} are bound.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->bound(DefaultSplitOutlinePromptsInstaller::class)) {
            return;
        }

        try {
            app(DefaultSplitOutlinePromptsInstaller::class)->refreshVocabularyPromptContract();
        } catch (Throwable) {
            // Installer may run before SEO DB is bootstrapped in some environments.
        }
    }

    public function down(): void
    {
        // Non-destructive: keep refreshed vocabulary contract.
    }
};
