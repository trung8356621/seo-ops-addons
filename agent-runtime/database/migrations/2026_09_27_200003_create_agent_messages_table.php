<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('thread_id')->constrained('agent_threads')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->json('response_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['thread_id', 'position']);
            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
    }
};
