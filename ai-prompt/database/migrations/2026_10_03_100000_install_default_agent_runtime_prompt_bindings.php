<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        try {
            app(DefaultAgentRuntimePromptInstaller::class)->install();
        } catch (Throwable $exception) {
            error_log('default_agent_runtime_prompt_install_failed: '.$exception->getMessage());
        }
    }

    public function down(): void {}
};
