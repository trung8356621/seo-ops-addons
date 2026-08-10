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
        Schema::connection($this->connection)->create('seo_article_wp_sync_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('status', 32)->index();
            $table->string('idempotency_key', 191)->index();
            $table->string('mode', 32)->default('sync');
            $table->string('source', 64)->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('correlation_id', 64)->nullable();
            $table->string('worker_id', 64)->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('locked_until')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wordpress_permalink', 2048)->nullable();
            $table->string('stage', 64)->nullable();
            $table->json('settings')->nullable();
            $table->json('audit_meta')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'status']);
            $table->index(['status', 'locked_until']);
        });

        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('articles', 'wp_sync_status')) {
                $table->string('wp_sync_status', 32)->default('idle')->after('wp_post_id')->index();
            }
            if (! Schema::connection($this->connection)->hasColumn('articles', 'wp_sync_job_id')) {
                $table->unsignedBigInteger('wp_sync_job_id')->nullable()->after('wp_sync_status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('articles', 'wp_sync_job_id')) {
                $table->dropColumn('wp_sync_job_id');
            }
            if (Schema::connection($this->connection)->hasColumn('articles', 'wp_sync_status')) {
                $table->dropColumn('wp_sync_status');
            }
        });

        Schema::connection($this->connection)->dropIfExists('seo_article_wp_sync_jobs');
    }
};
