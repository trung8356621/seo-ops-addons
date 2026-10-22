<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RPA Admin user sessions (hashed tokens), package enable/audit flags,
 * Script execution digest, agent_kind (admin vs runner).
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_rpa_admin_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id'); // Core users.id — logical, no FK
            $table->string('token_hash', 64); // sha256 hex of opaque token
            $table->string('token_hint', 12)->nullable(); // last 6 chars for support
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('client_label', 128)->nullable();
            $table->timestamps();

            $table->unique('token_hash');
            $table->index(['user_id', 'revoked_at']);
            $table->index('expires_at');
        });

        Schema::connection($this->connection)->table('sa_packages', function (Blueprint $table): void {
            $table->boolean('is_enabled')->default(true)->after('description');
            $table->string('disabled_reason', 64)->nullable()->after('is_enabled');
            $table->json('disabled_meta')->nullable()->after('disabled_reason');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('disabled_meta');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->string('sync_source', 32)->nullable()->after('updated_by_user_id');
        });

        Schema::connection($this->connection)->table('sa_package_releases', function (Blueprint $table): void {
            $table->string('execution_digest', 64)->nullable()->after('content_digest');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('published_at');
            $table->string('sync_source', 32)->nullable()->after('created_by_user_id');
            $table->index('execution_digest');
        });

        Schema::connection($this->connection)->table('sa_agents', function (Blueprint $table): void {
            $table->string('agent_kind', 16)->default('runner')->after('device_id');
            $table->index('agent_kind');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('sa_agents', function (Blueprint $table): void {
            $table->dropIndex(['agent_kind']);
            $table->dropColumn('agent_kind');
        });

        Schema::connection($this->connection)->table('sa_package_releases', function (Blueprint $table): void {
            $table->dropIndex(['execution_digest']);
            $table->dropColumn(['execution_digest', 'created_by_user_id', 'sync_source']);
        });

        Schema::connection($this->connection)->table('sa_packages', function (Blueprint $table): void {
            $table->dropColumn([
                'is_enabled',
                'disabled_reason',
                'disabled_meta',
                'created_by_user_id',
                'updated_by_user_id',
                'sync_source',
            ]);
        });

        Schema::connection($this->connection)->dropIfExists('sa_rpa_admin_sessions');
    }
};
