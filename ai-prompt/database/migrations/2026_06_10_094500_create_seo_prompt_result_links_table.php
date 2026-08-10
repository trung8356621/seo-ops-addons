<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->create('seo_prompt_result_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_result_id')->constrained('prompt_results')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('project_run_id')->nullable()->constrained('seo_project_runs')->nullOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('seo_project_tasks')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('source', 60)->default('unknown')->index();
            $table->string('workflow_node_id', 120)->nullable();
            $table->string('workflow_step_title', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['prompt_result_id', 'source', 'project_run_id', 'project_task_id', 'workflow_node_id'],
                'seo_prompt_result_links_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_prompt_result_links');
    }
};

