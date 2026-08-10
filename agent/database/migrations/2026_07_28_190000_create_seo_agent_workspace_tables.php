<?php

declare(strict_types=1);

// Agent Workspace conversations / messages / executions (omi_seo_ai).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_agent_conversations')) {
            $schema->create('seo_agent_conversations', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('connection_id')->nullable()->index();
                $table->string('title', 255)->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->string('active_skill_key', 128)->nullable();
                $table->json('context_summary')->nullable();
                $table->boolean('is_pinned')->default(false)->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamp('archived_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_agent_messages')) {
            $schema->create('seo_agent_messages', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->string('role', 32)->index();
                $table->string('message_type', 32)->default('text')->index();
                $table->text('content')->nullable();
                $table->json('structured_content')->nullable();
                $table->string('skill_key', 128)->nullable()->index();
                $table->string('operation_ref', 64)->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (! $schema->hasTable('seo_agent_executions')) {
            $schema->create('seo_agent_executions', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->unsignedBigInteger('message_id')->nullable()->index();
                $table->string('skill_key', 128)->index();
                $table->string('capability', 128)->index();
                $table->string('status', 32)->default('pending')->index();
                $table->string('operation_ref', 64)->nullable()->index();
                $table->string('confirmation_ref', 128)->nullable();
                $table->json('input_summary')->nullable();
                $table->json('result_summary')->nullable();
                $table->string('error_code', 128)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_agent_executions');
        $schema->dropIfExists('seo_agent_messages');
        $schema->dropIfExists('seo_agent_conversations');
    }
};
