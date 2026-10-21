<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C — Agent / Browser Session / Account registries (omi_social_automation).
 * Never persist browser auth material or local profile paths.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('agent_uuid')->unique();
            $table->string('device_id', 128);
            $table->string('display_name', 255)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('runtime_version', 64)->nullable();
            $table->json('capabilities')->nullable();
            $table->string('availability', 32)->default('available'); // available|busy|paused|error
            $table->unsignedTinyInteger('max_concurrency')->default(1);
            $table->unsignedInteger('active_jobs')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedBigInteger('credential_id')->nullable()->index(); // Core service_api_credentials.id (logical)
            $table->timestamps();

            $table->unique(['device_id']);
            $table->index('last_heartbeat_at');
            $table->index('availability');
        });

        Schema::connection($this->connection)->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('platform', 64);
            $table->string('domain', 255)->nullable();
            $table->string('label', 255)->nullable();
            $table->string('declared_username', 255)->nullable();
            $table->string('declared_email', 255)->nullable();
            $table->string('platform_account_id', 191)->nullable();
            $table->string('identity_source', 32)->default('declared');
            $table->boolean('verified')->default(false);
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'platform_account_id']);
            $table->index('verified');
        });

        Schema::connection($this->connection)->create('sa_browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('agent_id')->constrained('sa_agents')->cascadeOnDelete();
            $table->string('platform', 64)->nullable();
            $table->string('domain', 255)->nullable();
            $table->string('ready_status', 32)->default('pending'); // pending|ready|needs_attention|invalid
            $table->timestamp('last_confirmed_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->unsignedBigInteger('locked_job_id')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'ready_status']);
            $table->index(['platform', 'domain']);
            $table->index('lock_expires_at');
        });

        Schema::connection($this->connection)->create('sa_session_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('browser_session_id')->constrained('sa_browser_sessions')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('sa_accounts')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->boolean('is_active')->default(false);
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            $table->unique(['browser_session_id', 'domain', 'account_id'], 'sa_session_accounts_unique_bind');
            $table->index(['browser_session_id', 'domain', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_session_accounts');
        Schema::connection($this->connection)->dropIfExists('sa_browser_sessions');
        Schema::connection($this->connection)->dropIfExists('sa_accounts');
        Schema::connection($this->connection)->dropIfExists('sa_agents');
    }
};
