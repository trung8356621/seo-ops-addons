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
        Schema::connection($this->connection)->create('seo_article_cta_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('mode', 32);
            $table->string('status', 32);
            $table->string('error_code', 80)->nullable();
            $table->string('source_fingerprint', 64);
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamp('generation_started_at')->nullable();
            $table->timestamp('generation_completed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->json('plan')->nullable();
            $table->json('changes')->nullable();
            $table->json('review')->nullable();
            $table->json('summary')->nullable();
            $table->json('selections')->nullable();
            $table->string('prompt_hook', 128)->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('execution_id', 80)->nullable();
            $table->unsignedBigInteger('prompt_result_id')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'created_at']);
            $table->unique(['article_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_article_cta_runs');
    }
};
