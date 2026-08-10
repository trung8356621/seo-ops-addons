<?php

declare(strict_types=1);

// Phase 4 — Agent Workspace scoped knowledge & memory proposals (omi_seo_ai).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_agent_knowledge_items')) {
            $schema->create('seo_agent_knowledge_items', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->string('connection_hash', 64)->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('scope_type', 32)->index();
                $table->string('scope_ref', 128)->nullable()->index();
                $table->unsignedBigInteger('owner_user_id')->nullable()->index();
                $table->string('type', 64)->index();
                $table->string('title', 255);
                $table->mediumText('content');
                $table->text('summary')->nullable();
                $table->string('source_type', 64)->index();
                $table->string('source_ref', 128)->nullable()->index();
                $table->string('source_version', 64)->nullable();
                $table->json('source_metadata')->nullable();
                $table->string('trust_level', 32)->index();
                $table->string('status', 32)->index();
                $table->unsignedSmallInteger('priority')->default(50);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable()->index();
                $table->timestamp('last_verified_at')->nullable();
                $table->string('content_hash', 64)->index();
                $table->unsignedInteger('version')->default(1);
                $table->unsignedBigInteger('supersedes_id')->nullable()->index();
                $table->string('index_status', 32)->default('pending')->index();
                $table->text('index_error')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('disabled_by')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['connection_hash', 'status'], 'seo_agent_knowledge_items_conn_status_index');
                $table->index(['scope_type', 'scope_ref'], 'seo_agent_knowledge_items_scope_index');
                $table->index(['source_type', 'source_ref'], 'seo_agent_knowledge_items_source_index');
                $table->index(['site_id', 'status', 'type'], 'seo_agent_knowledge_items_site_status_type_index');
            });

            try {
                Schema::connection($this->connection)->getConnection()->statement(
                    'ALTER TABLE seo_agent_knowledge_items ADD FULLTEXT seo_agent_knowledge_items_content_fulltext (title, content, summary)'
                );
            } catch (\Throwable) {
                // FULLTEXT optional — keyword fallback still works.
            }
        }

        if (! $schema->hasTable('seo_agent_knowledge_chunks')) {
            $schema->create('seo_agent_knowledge_chunks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('knowledge_item_id')->index();
                $table->unsignedInteger('chunk_index');
                $table->string('heading', 255)->nullable();
                $table->text('content');
                $table->unsignedInteger('token_estimate')->default(0);
                $table->string('content_hash', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['knowledge_item_id', 'chunk_index'], 'seo_agent_knowledge_chunks_item_index_unique');
                $table->unique(['knowledge_item_id', 'content_hash'], 'seo_agent_knowledge_chunks_item_hash_unique');
            });
        }

        if (! $schema->hasTable('seo_agent_memory_proposals')) {
            $schema->create('seo_agent_memory_proposals', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
                $table->unsignedBigInteger('message_id')->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('connection_hash', 64)->nullable()->index();
                $table->string('proposed_type', 64);
                $table->string('title', 255);
                $table->mediumText('content');
                $table->string('proposed_scope_type', 32);
                $table->string('proposed_scope_ref', 128)->nullable();
                $table->text('reason')->nullable();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->json('warnings')->nullable();
                $table->json('source_metadata')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('knowledge_item_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach (['seo_agent_memory_proposals', 'seo_agent_knowledge_chunks', 'seo_agent_knowledge_items'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }
};
