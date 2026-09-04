<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;

/**
 * Upgrade system-default Outline/Vocabulary prompts to self-contained {{input}} contract.
 * Skips operator-customized prompts (see DefaultSplitOutlinePromptsInstaller::mayRefreshSystemDefault).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->bound(DefaultSplitOutlinePromptsInstaller::class)) {
            return;
        }

        try {
            app(DefaultSplitOutlinePromptsInstaller::class)->refreshSplitPromptInputContract();
        } catch (Throwable) {
            // Installer may run before SEO DB is bootstrapped in some environments.
        }
    }

    public function down(): void
    {
        // Non-destructive: keep upgraded {{input}} contract.
    }
};
