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
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_site_sync_inbound_events')) {
            $schema->create('seo_site_sync_inbound_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('event_id', 128)->nullable()->index();
                $table->string('idempotency_key', 128)->index();
                $table->string('operation_id', 64)->nullable()->index();
                $table->string('event_type', 64)->index();
                $table->unsignedBigInteger('wordpress_id')->nullable()->index();
                $table->string('status', 32)->default('received')->index();
                $table->string('schema_version', 32)->default('site_sync.v1');
                $table->unsignedInteger('attempts')->default(0);
                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_message')->nullable();
                $table->timestamp('retry_after')->nullable();
                $table->json('hashes')->nullable();
                $table->json('payload')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['site_id', 'idempotency_key']);
            });
        }

        if ($schema->hasTable('seo_site_sync_run_steps')) {
            $schema->table('seo_site_sync_run_steps', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_site_sync_run_steps', 'attempt_count')) {
                    $table->unsignedInteger('attempt_count')->default(0)->after('status');
                }
                if (! $schema->hasColumn('seo_site_sync_run_steps', 'last_error_code')) {
                    $table->string('last_error_code', 64)->nullable()->after('attempt_count');
                }
                if (! $schema->hasColumn('seo_site_sync_run_steps', 'retry_after')) {
                    $table->timestamp('retry_after')->nullable()->after('error_message');
                }
                if (! $schema->hasColumn('seo_site_sync_run_steps', 'checkpoint')) {
                    $table->json('checkpoint')->nullable()->after('metrics');
                }
            });
        }

        if ($schema->hasTable('seo_site_link_catalog')) {
            $schema->table('seo_site_link_catalog', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_site_link_catalog', 'deleted_at')) {
                    $table->softDeletes();
                }
                if (! $schema->hasColumn('seo_site_link_catalog', 'inactive_at')) {
                    $table->timestamp('inactive_at')->nullable()->index();
                }
            });
        }

        if (! $schema->hasTable('seo_article_remote_snapshots')) {
            $schema->create('seo_article_remote_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('article_id')->nullable()->index();
                $table->unsignedBigInteger('wordpress_id')->index();
                $table->string('content_hash', 64)->nullable()->index();
                $table->boolean('remote_change_available')->default(false)->index();
                $table->json('payload')->nullable();
                $table->timestamp('remote_modified_at')->nullable();
                $table->timestamps();
                $table->unique(['site_id', 'wordpress_id']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_locks')) {
            $schema->create('seo_site_sync_locks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->unique();
                $table->string('owner_token', 64);
                $table->string('lock_type', 32)->default('sync');
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_site_sync_locks');
        $schema->dropIfExists('seo_article_remote_snapshots');
        $schema->dropIfExists('seo_site_sync_inbound_events');
    }
};
