<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-authoritative edit sessions for Article Editor Phase 1.
 * Distinct from ActionSupport::withArticleLock (short Cache mutex).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('article_editor_sessions')) {
            return;
        }

        $schema->create('article_editor_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('status', 32)->index();
            $table->uuid('client_instance_id')->nullable()->index();
            $table->timestamp('acquired_at');
            $table->timestamp('heartbeat_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('takeover_by_user_id')->nullable()->index();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['article_id', 'status', 'expires_at'], 'article_editor_sessions_active_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('article_editor_sessions');
    }
};
