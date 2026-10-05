<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        app(DefaultAgentRuntimePromptInstaller::class)->install();
    }

    public function down(): void {}
};
