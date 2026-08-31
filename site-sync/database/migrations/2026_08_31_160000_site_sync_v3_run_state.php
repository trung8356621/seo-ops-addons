<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site Sync V3 run state.
 *
 * V3 phase names live in seo_site_sync_runs.current_step (discover, import,
 * reconcile_stale, catch_up, verify, complete, needs_attention). Continuation /
 * retry counters and keyset cursors live in seo_site_sync_runs.meta JSON — do
 * not replay seo_site_sync_batches payloads on the V3 path.
 *
 * protocol_version: '2' (legacy V2 steps) | '3' (V3 phases).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_site_sync_runs')) {
            $schema->table('seo_site_sync_runs', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_site_sync_runs', 'protocol_version')) {
                    $table->string('protocol_version', 8)->default('2')->index()->after('mode');
                }
            });
        }

        if (! $schema->hasTable('seo_site_sync_v3_receipts')) {
            $schema->create('seo_site_sync_v3_receipts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('resource', 64);
                $table->unsignedInteger('processing_job_number')->default(0);
                $table->json('cursor_before')->nullable();
                $table->json('cursor_after')->nullable();
                $table->unsignedInteger('item_count')->default(0);
                $table->unsignedInteger('upsert_count')->default(0);
                $table->unsignedInteger('delete_count')->default(0);
                $table->string('checksum', 64)->nullable();
                $table->unsignedInteger('wp_request_ms')->default(0);
                $table->unsignedInteger('decode_ms')->default(0);
                $table->unsignedInteger('db_ms')->default(0);
                $table->unsignedInteger('total_ms')->default(0);
                $table->unsignedInteger('query_count')->default(0);
                $table->string('status', 32)->default('ok');
                $table->string('error_code', 64)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if ($schema->hasTable('wordpress_article_links')
            && ! $schema->hasColumn('wordpress_article_links', 'last_seen_sync_generation')
        ) {
            $schema->table('wordpress_article_links', function (Blueprint $table): void {
                $table->unsignedBigInteger('last_seen_sync_generation')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('wordpress_article_links')
            && $schema->hasColumn('wordpress_article_links', 'last_seen_sync_generation')
        ) {
            $schema->table('wordpress_article_links', function (Blueprint $table): void {
                $table->dropColumn('last_seen_sync_generation');
            });
        }

        $schema->dropIfExists('seo_site_sync_v3_receipts');

        if ($schema->hasTable('seo_site_sync_runs')
            && $schema->hasColumn('seo_site_sync_runs', 'protocol_version')
        ) {
            $schema->table('seo_site_sync_runs', function (Blueprint $table): void {
                $table->dropColumn('protocol_version');
            });
        }
    }
};
