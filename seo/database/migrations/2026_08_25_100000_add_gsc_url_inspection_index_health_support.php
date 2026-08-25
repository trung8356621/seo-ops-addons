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

        if ($schema->hasTable('seo_article_index_checks')
            && ! $schema->hasColumn('seo_article_index_checks', 'diagnostics')
        ) {
            $schema->table('seo_article_index_checks', function (Blueprint $table): void {
                $table->json('diagnostics')->nullable()->after('notes');
            });
        }

        if (! $schema->hasTable('seo_gsc_url_inspection_runs')) {
            $schema->create('seo_gsc_url_inspection_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('property_uri', 2048);
                $table->string('status', 32)->index(); // queued|running|completed|partial|failed
                $table->unsignedInteger('requested')->default(0);
                $table->unsignedInteger('inspected')->default(0);
                $table->unsignedInteger('indexed')->default(0);
                $table->unsignedInteger('not_indexed')->default(0);
                $table->unsignedInteger('unknown')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->string('error_code', 64)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['site_id', 'status']);
            });
        }

        if (! $schema->hasTable('seo_gsc_url_inspection_run_items')) {
            $schema->create('seo_gsc_url_inspection_run_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('article_id')->index();
                $table->string('url', 2048)->nullable();
                $table->string('status', 32)->index(); // queued|running|recorded|failed|skipped
                $table->string('check_status', 32)->nullable(); // indexed|not_indexed|unknown
                $table->string('error_code', 64)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->unsignedBigInteger('check_id')->nullable();
                $table->json('diagnostics')->nullable();
                $table->timestamps();

                $table->unique(['run_id', 'article_id'], 'seo_gsc_url_insp_run_article_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_gsc_url_inspection_run_items');
        $schema->dropIfExists('seo_gsc_url_inspection_runs');

        if ($schema->hasTable('seo_article_index_checks')
            && $schema->hasColumn('seo_article_index_checks', 'diagnostics')
        ) {
            $schema->table('seo_article_index_checks', function (Blueprint $table): void {
                $table->dropColumn('diagnostics');
            });
        }
    }
};
