<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('thread_id')->constrained('agent_threads')->cascadeOnDelete();
            $table->unsignedBigInteger('user_message_id')->nullable();
            $table->unsignedBigInteger('assistant_message_id')->nullable();
            $table->string('app_key', 64);
            $table->string('scope_type', 32);
            $table->string('scope_ref', 191);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('decision_model', 191)->nullable();
            $table->string('answer_model', 191)->nullable();
            $table->string('retrieval_type', 32)->nullable();
            $table->json('retrieval_summary')->nullable();
            $table->string('correlation_id', 191)->nullable();
            $table->string('failure_code', 128)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('decision_ms')->nullable();
            $table->unsignedInteger('retrieval_ms')->nullable();
            $table->unsignedInteger('answer_ms')->nullable();
            $table->unsignedInteger('total_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['app_key', 'started_at']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
