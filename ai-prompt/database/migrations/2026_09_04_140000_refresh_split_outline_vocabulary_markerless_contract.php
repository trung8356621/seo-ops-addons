<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;

/**
 * Remove legacy START/END marker instructions from system-default Prompt #22/#23.
 * AI must return direct Outline/Vocabulary body; PHP assembles markers for legacy downstream.
 * Skips operator-customized prompts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->bound(DefaultSplitOutlinePromptsInstaller::class)) {
            return;
        }

        try {
            app(DefaultSplitOutlinePromptsInstaller::class)->refreshSplitPromptMarkerlessContract();
        } catch (Throwable) {
            // Installer may run before SEO DB is bootstrapped in some environments.
        }
    }

    public function down(): void
    {
        // Non-destructive: keep markerless direct-output contract.
    }
};
