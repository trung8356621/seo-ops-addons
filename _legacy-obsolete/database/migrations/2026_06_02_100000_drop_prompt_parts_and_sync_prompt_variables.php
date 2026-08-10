<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Support\PromptVariableSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        DB::connection($this->connection)
            ->table('prompts')
            ->orderBy('id')
            ->chunkById(100, function ($prompts): void {
                foreach ($prompts as $prompt) {
                    $markdown = trim((string) ($prompt->markdown_content ?? ''));
                    if ($markdown === '') {
                        continue;
                    }

                    $existing = json_decode((string) ($prompt->variables ?? '[]'), true);
                    $variables = PromptVariableSync::mergeFromMarkdown(
                        $markdown,
                        is_array($existing) ? $existing : [],
                    );

                    DB::connection($this->connection)
                        ->table('prompts')
                        ->where('id', (int) $prompt->id)
                        ->update([
                            'variables' => json_encode($variables, JSON_UNESCAPED_UNICODE),
                        ]);
                }
            });

        Schema::connection($this->connection)->dropIfExists('prompt_parts');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->create('prompt_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_id')->constrained('prompts')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('role', 32)->default('user');
            $table->string('name')->nullable();
            $table->longText('content');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
};
