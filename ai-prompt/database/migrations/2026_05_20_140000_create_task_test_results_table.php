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
        Schema::connection($this->connection)->create('task_test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('seo_tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('status', 32)->default('completed');
            $table->json('input_snapshot')->nullable();
            $table->json('resolved_context')->nullable();
            $table->json('step_results')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('task_test_results');
    }
};
