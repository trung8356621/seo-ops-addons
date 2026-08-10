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
        if (! Schema::connection($this->connection)->hasTable('seo_content_project_operations')) {
            Schema::connection($this->connection)->create('seo_content_project_operations', function (Blueprint $table): void {
                $table->id();
                $table->string('operation_id', 64)->unique();
                $table->string('request_id', 64)->nullable()->index();
                $table->string('command', 96)->index();
                $table->string('actor_type', 32)->index();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('tenant_ref', 64)->nullable()->index();
                $table->string('project_ref', 64)->nullable()->index();
                $table->string('item_ref', 64)->nullable()->index();
                $table->string('article_ref', 64)->nullable();
                $table->string('result_code', 96)->nullable()->index();
                $table->string('status', 32)->default('finished')->index();
                $table->boolean('success')->default(false);
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('idempotency_key_hash', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable()->index();
                $table->timestamps();

                $table->index(['command', 'finished_at'], 'cp_ops_cmd_finished_idx');
                $table->index(['result_code', 'finished_at'], 'cp_ops_result_finished_idx');
            });
        }

        if (! Schema::connection($this->connection)->hasTable('seo_content_project_ops_metrics')) {
            Schema::connection($this->connection)->create('seo_content_project_ops_metrics', function (Blueprint $table): void {
                $table->id();
                $table->string('metric_key', 96)->index();
                $table->date('bucket_date')->index();
                $table->unsignedBigInteger('site_id')->default(0)->index();
                $table->unsignedBigInteger('project_id')->default(0)->index();
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();

                $table->unique(
                    ['metric_key', 'bucket_date', 'site_id', 'project_id'],
                    'cp_ops_metrics_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_content_project_ops_metrics');
        Schema::connection($this->connection)->dropIfExists('seo_content_project_operations');
    }
};
