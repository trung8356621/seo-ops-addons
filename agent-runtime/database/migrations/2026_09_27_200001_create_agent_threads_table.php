<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_threads', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->unsignedBigInteger('agent_app_id');
            $table->string('principal_type', 32)->default('user');
            $table->string('principal_ref', 191);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('scope_type', 32);
            $table->string('scope_ref', 191);
            $table->string('title', 191)->nullable();
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agent_app_id', 'principal_type', 'principal_ref'], 'idx_agent_threads_app_principal');
            $table->index(['principal_type', 'principal_ref', 'scope_ref'], 'idx_agent_threads_principal_scope');
            $table->index(['status', 'last_message_at'], 'idx_agent_threads_status_last_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_threads');
    }
};
